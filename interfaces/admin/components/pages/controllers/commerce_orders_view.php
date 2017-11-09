<?php

namespace CASHMusic\Admin;

use CASHMusic\Core\CASHSystem as CASHSystem;
use CASHMusic\Core\CASHRequest as CASHRequest;
use ArrayIterator;
use CASHMusic\Admin\AdminHelper;

$admin_helper = new AdminHelper($admin_primary_cash_request, $cash_admin);
/*******************************************************************************
 *
 * 1. SET UP SCRIPT VARIABLES
 *
 ******************************************************************************/
$effective_user = $cash_admin->effective_user_id;

if ($request_parameters) {
	/****************************************************************************
	 *
	 * 2. FOUND (AT LEAST) AN ORDER ID, FIRST LOOK FOR ACTION PARAMS
	 *
	 ***************************************************************************/

	// receipt request requested
	if (isset($_POST['resend_store_url'])) {
		$resend_response = $cash_admin->requestAndStore(
			array(
				'cash_request_type' => 'commerce',
				'cash_action' => 'sendorderreceipt',
				'id' => $request_parameters[0],
				'finalize_url' => $_POST['resend_store_url']
			)
		);
		$admin_helper->formSuccess('Receipt sent!','/commerce/orders/view/' . $request_parameters[0]);
	}

	// edit order notes
	if (isset($_POST['ordernotes'])) {
		$order_details_response = $cash_admin->requestAndStore(
			array(
				'cash_request_type' => 'commerce',
				'cash_action' => 'editorder',
				'id' => $request_parameters[0],
				'notes' => $_POST['ordernotes']
			)
		);
		$admin_helper->formSuccess('Changes saved.','/commerce/orders/view/' . $request_parameters[0]);
	}

	// mark order as fulfilled
	if (isset($request_parameters[1])) {
		if ($request_parameters[1] == 'fulfilled') {
			$order_details_response = $cash_admin->requestAndStore(
				array(
					'cash_request_type' => 'commerce',
					'cash_action' => 'editorder',
					'id' => $request_parameters[0],
					'fulfilled' => 1
				)
			);
			$admin_helper->formSuccess('Order fulfilled.','/commerce/orders/view/' . $request_parameters[0]);
		}  else if ($request_parameters[1] == 'cancel') {

			$order_cancel_response = $cash_admin->requestAndStore(
				array(
					'cash_request_type' => 'commerce',
					'cash_action' => 'cancelorder',
					'order_id' => $request_parameters[0]
				)
			);

			if ($order_cancel_response['payload']) {
				$admin_helper->formSuccess('Order cancelled.','/commerce/orders/view/' . $request_parameters[0]);
			} else {
				$admin_helper->formFailure('Try again.','/commerce/orders/view/' . $request_parameters[0]);
			}
		}
	}

	/****************************************************************************
	 *
	 * 3. GET ORDER DETAILS AND FORMAT RETURN
	 *
	 ***************************************************************************/
	$order_details_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'commerce',
			'cash_action' => 'getorder',
			'id' => $request_parameters[0],
			'deep' => true
		)
	);
	$order_all_details = $order_details_response['payload'];
	if ($order_all_details['user_id'] == $effective_user) {
		// format all the details into easy mustache variables
		$order_all_details['padded_id'] = str_pad($order_all_details['id'],6,0,STR_PAD_LEFT);
		$order_all_details['order_date'] = date("M j, Y, g:i A", $order_all_details['creation_date']);
		$order_all_details['formatted_gross_price'] = sprintf("%01.2f",$order_all_details['gross_price']);

		$order_all_details['formatted_net_price'] = sprintf("%01.2f",$order_all_details['gross_price'] - $order_all_details['service_fee']);
		$order_all_details['formatted_connection_name'] = preg_replace('/\(|\)/','',$admin_helper->getConnectionName($order_all_details['connection_id']));
		$order_all_details['order_connection_details'] = $admin_helper->getConnectionName($order_all_details['connection_id']) . ' (' . $order_all_details['connection_type'] . ')';
		//if ($order_all_details['fulfilled']) { $order_all_details['order_fulfilled'] = 'yes'; } else { $order_all_details['order_fulfilled'] = 'no'; }

		$order_contents = $order_all_details['order_contents'];

		$item_price = 0;

		if ($order_contents) {
            foreach ($order_contents as $key => $item) {
                if (!isset($item['qty'])) {
                    $item['qty'] = 1;
                }
                $item['price'] = $item['qty'] * $item['price'];
                $item_price += $item['price'];

                if (isset($item['variant'])) {
                    $variant_response = $cash_admin->requestAndStore(
                        array(
                            'cash_request_type' => 'commerce',
                            'cash_action' => 'formatvariantname',
                            'name' => $item['variant']
                        )
                    );
                    if ($variant_response['payload']) {
                        $order_contents[$key]['variant'] = $variant_response['payload'];
                    }
                }
            }
		} else {
			$order_contents = [];
		}

        $order_all_details['formatted_subtotal'] = sprintf("%01.2f",$item_price);
        $cash_admin->page_data = array_merge($cash_admin->page_data,$order_all_details);

		$cash_admin->page_data['order_contents'] = new ArrayIterator($order_contents);
		$cash_admin->page_data['formatted_shipping'] = number_format($order_all_details['gross_price']-$item_price,2);

		$shipping_address = $order_all_details['data'];
		$cash_admin->page_data['ui_title'] = 'Order #' . $order_all_details['padded_id'];

		// customer

		if (!empty($order_all_details['customer_shipping_name'])) {
			$customer_name = $order_all_details['customer_shipping_name'];
		} else {
			$customer_name = $order_all_details['customer_name'];
		}

		$cash_admin->page_data['customer_name'] = $customer_name;
		$cash_admin->page_data['customer_email'] = $order_all_details['customer_email'];
		$cash_admin->page_data['customer_countrycode'] = $order_all_details['customer_countrycode'];
		$cash_admin->page_data['customer_address1'] = $order_all_details['customer_address1'];
		$cash_admin->page_data['customer_address2'] = $order_all_details['customer_address2'];
		$cash_admin->page_data['customer_city'] = $order_all_details['customer_city'];
		$cash_admin->page_data['customer_region'] = $order_all_details['customer_region'];
		$cash_admin->page_data['customer_postalcode'] = $order_all_details['customer_postalcode'];
		$cash_admin->page_data['customer_country'] = $order_all_details['customer_countrycode'];

		// we need to iterate through order_contents to see if any items are
		$cash_admin->page_data['display_shipping_address'] = false;
		foreach($order_contents as $item) {
			if ($item['physical_fulfillment'] == 1)
			{
				$cash_admin->page_data['display_shipping_address'] = true;
			}
		}
	} else {
		// bogus ID specified — bounce that shit
		header('Location: ' . ADMIN_WWW_BASE_PATH . '/commerce/orders/');
	}
} else {
	/****************************************************************************
	 *
	 * 4. NO ORDER ID SET, BOUNCE BACK TO MAIN ORDERS PAGE
	 *
	 ***************************************************************************/
	header('Location: ' . ADMIN_WWW_BASE_PATH . '/commerce/orders/');
}

/*******************************************************************************
 *
 * 5. SET THE TEMPLATE AND GO!
 *
 ******************************************************************************/
$cash_admin->setPageContentTemplate('commerce_orders_details');
?>
