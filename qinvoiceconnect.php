<?php
/**
 * @copyright	Copyright (C) 2013-2014 q-invoice.com - All rights reserved.
 * @license		http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 */
defined('_JEXEC') or die('Restricted access');
define('IDENTIFIER','hika_1.0.3');

require_once('qinvoiceconnect/class.q-invoice.php');

class plgHikashopQinvoiceConnect extends JPlugin
{

	function __construct(&$subject, $config){
		parent::__construct($subject, $config);
		$this->config = json_decode($config['params']);
	}

	/* 
	This function will be triggered by HikaShop after an order is updated. You will get in the $order object the information which is updated for the order. The $send_email variable can be set to true/false in case you want to allow/disallow the email notification to the customer.
	*/
	function onAfterOrderUpdate(&$order,&$send_email){
		
		$invoice_trigger = $this->config->invoice_trigger;
		
        if($invoice_trigger == $order->order_status || ($invoice_trigger == 'created' && ($order->old->order_status) == 'created')){ 
            $this->_prepareRequestForQinvoice($order);            
        }
		//die('hi');
	}

	/*
	This method will be called by HikaShop when the order is created at the end of the checkout. You might want to do some processing here. If you are developping a payment gateway plugin, you might want to redirect the customer to the gateway payment page.
	*/
	function onAfterOrderConfirm(&$order,&$methods,$method_id){
	}

	function _prepareRequestForQinvoice($order,$new_order_status = '') {
		//Get plugin params
		$api_url = $this->config->api_url;
		$api_username = $this->config->api_username;
		$api_password = $this->config->api_password;
		$layout_code = $this->config->layout_code;
		$invoice_remark = $this->config->invoice_remark;
		$calculation_method = $this->config->calculation_method;

		$invoice_tag = strlen($this->config->invoice_tag) > 0 ? $this->config->invoice_tag : '';
		$invoice_action = $this->config->invoice_action;
		$save_relation = $this->config->save_relation;

        $lang = $order->order_language;
        $lang = str_replace("-",'_',$lang);
        $lang = strtolower($lang);

        $invoice = new qinvoice($api_username,$api_password,$api_url);
        $invoice->identifier = IDENTIFIER;
        $invoice->calculation_method = $calculation_method;

        /* CURRENCY */
        $currencyClass = hikashop_get('class.currency');
        $currency = $currencyClass->get($order->order_currency_id);
        $invoice->currency = $currency->currency_code;  

        /* BILLING ADDRESS */
        $addressClass = hikashop_get('class.address');
		$billing_address = $addressClass->get($order->order_billing_address_id);

        $zoneClass = hikashop_get('class.zone');
        $zone = $zoneClass->get(end(explode('-',$billing_address->address_country)));

        $userClass = hikashop_get('class.user');
        $user = $userClass->get($order->order_user_id);


        $invoice->companyname = $billing_address->address_company;       // Your customers company name
        $ivnoice->salutation = $billing_address->address_title == 'Mr' ? 'sir' : 'madam';
        $invoice->firstname = $billing_address->address_firstname;       // Your customers contact name
        $invoice->lastname = $billing_address->address_middlename .' '. $billing_address->address_lastname;       // Your customers 
        $invoice->email = $user->user_email;                // Your customers emailaddress (invoice will be sent here)
        $invoice->address = $billing_address->address_street; 
        $invoice->address2 = $billing_address->address_street2;                // Self-explanatory
        $invoice->zipcode = $billing_address->address_post_code;              // Self-explanatory
        $invoice->city = $billing_address->address_city;                     // Self-explanatory
       	$invoice->country = $zone->zone_code_2;                 // 2 character country code: NL for Netherlands, DE for Germany etc
        $invoice->phone = $billing_address->address_telephone;
        $invoice->vatnumber = $billing_address->address_vat;

        /* DELIVERY ADDRESS */
        $delivery_address = $addressClass->get($order->order_shipping_address_id);
        $zone = $zoneClass->get(end(explode('-',$delivery_address->address_country)));

        $invoice->delivery_address = $delivery_address->address_street;
        $invoice->delivery_address2 = $delivery_address->address_street2;                // Self-explanatory
        $invoice->delivery_zipcode = $delivery_address->address_post_code;              // Self-explanatory
        $invoice->delivery_city = $delivery_address->address_city;                     // Self-explanatory
       	$invoice->delivery_country = $zone->zone_code_2;      
        
        $invoice->saverelation = $save_relation;
        
        $paid = 0;
        $paid_remark = '';
        
        if($order->order_status == 'confirmed'){
            $paid = 1;
            $paid_remark = $this->confi->paid_remark;
        }

        $invoice->paid = $paid;
        $invoice->layout = $invoice_layout;
        $invoice->action = $invoice_action;
        $invoice->addTag($order->order_number);
        if($invoice_tag){
        	$invoice->addTag($invoice_tag);
        }


        /* INVOICE REMARK */
        $invoice_remark = str_replace('{order_id}',$order->order_number,$invoice_remark);
        //$invoice_remark = str_replace('{order_weight}',$STweight,$invoice_remark);
        $invoice_remark .= ' '. $paid_remark; 

        $invoice->remark = $invoice_remark;
        //$invoice->remark = str_replace('{order_id}',$order['details']['BT']->order_number,$invoice_remark) .' '. $paid_remark;                  // Self-explanatory

       

        /* TAXES  */
        $taxcount = 0;
        foreach($order->order_tax_info as $tax){
        	if($taxcount > 0) continue;
        	$order_tax_rate = $tax->tax_rate;
        	$taxcount++;
        }
        // Tax info is in $order->order_tax_info

        $addressClass = hikashop_get('class.address');
		$billing_address = $addressClass->get($order->order_billing_address_id);

        $orderClass = hikashop_get('class.order');
        $items = $orderClass->loadProducts($order);
        
        // echo '<pre>';
        // print_r($order);
        // echo '</pre>';

        // die();

        
        /* ITEMS */
        foreach($order->products as $item):

            $attributes = json_decode($item->product_attribute);
            $procuct_att = '';
            foreach((array)$attributes as $a){
                $product_att .= "\n". strip_tags($a);
            }

            $params = array(    
                    'code' => $item->order_product_code,
                    'description' => $item->order_product_name . $product_att,
                   // 'price' => ($item->product_discountedPriceWithoutTax - ($item->product_subtotal_discount/$item->product_quantity))*100,
                    'price' => $item->order_product_price*100,
                    'price_incl' => ($item->order_product_price + $item->order_product_tax)*100,
                    'price_vat' => $item->order_product_tax*100,
                    'vatpercentage' => $item->order_product_tax_info[0]->tax_rate*10000,
                    'quantity' => $item->order_product_quantity*100,
                    'ledgeraccount' => $this->config->ledger_account
                    );
            $invoice->addItem($params);

        endforeach;
        
        /* DISCOUNT */
        if($order->discount_price > 0){
        	// vat = $order->order_discount_tax;
            $params = array(    
                    'code' => 'DSCNT',
                    'description' => 'Coupon: '. $order->order_discount_code,
                   // 'price' => ($item->product_discountedPriceWithoutTax - ($item->product_subtotal_discount/$item->product_quantity))*100,
                    'price_incl' => $order->order_discount_price*100,
                    'price' => ($order->order_discount_price - $order->order_discount_tax)*100,
                    'price_vat' => $order->order_discount_tax*100,
                    'vatpercentage' => $this->config->discount_vat_percentage,
                    'quantity' => 100,
                    'categories' => 'discount'
                    );
            $invoice->addItem($params);
        }

         /* PAYMENT */
        if($order->order_payment_price > 0){
        	// no tax available
            $price_excl = round(($order->order_payment_price / (1 + $order_tax_rate))*100,2);

            $params = array(    
                    'code' => 'CSTS',
                    'description' => $order->order_payment_method,
                   // 'price' => ($item->product_discountedPriceWithoutTax - ($item->product_subtotal_discount/$item->product_quantity))*100,
                    'price_incl' => $order->order_payment_price*100,
                    'price' => $price_excl,
                    'price_vat' => ($order->order_payment_price*100) - $price_excl,
                    'vatpercentage' => $order_tax_rate*10000,
                    'quantity' => 100,
                    'categories' => 'payment'
                    );
            $invoice->addItem($params);
        }

        /* SHIPPING */
        if($order->order_shipping_price > 0){
        	// tax: $order->order_shipping_tax;
            $params = array(    
                    'code' => 'SHPMNT',
                    'description' => $order->order_shipping_method,
                   // 'price' => ($item->product_discountedPriceWithoutTax - ($item->product_subtotal_discount/$item->product_quantity))*100,
                    'price_incl' => $order->order_shipping_price*100,
                    'price' => ($order->order_shipping_price - $order->order_shipping_tax)*100,
                    'price_vat' => $order->order_shipping_tax*100,
                    'vatpercentage' => $order_tax_rate*10000,
                    'quantity' => 100,
                    'categories' => 'shipment'
                    );
            $invoice->addItem($params);
        }
       

        $result =  $invoice->sendRequest();
        return true;
    }
	/*
	// Get a db connection.
	$hikashop_user_id = JFactory::getDbo();
					
	// Create a new query object.
	$hikashop_query = $hikashop_user_id->getQuery(true);
	$hikashop_user_id->setQuery('SELECT user_id, user_cms_id FROM #__hikashop_user WHERE user_cms_id = ' . (int) $userId);

	// Set $hikashop_id as the id we matched up in the hikashop_user_id query
	$hikashop_id = $hikashop_user_id_results['user_id'];
	*/

}
?>