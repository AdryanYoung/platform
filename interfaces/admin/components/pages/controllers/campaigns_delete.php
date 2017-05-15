<?php

namespace CASHMusic\Admin;

use CASHMusic\Core\CASHSystem as CASHSystem;
use CASHMusic\Core\CASHRequest as CASHRequest;
use ArrayIterator;
use CASHMusic\Admin\AdminHelper;

$admin_helper = new AdminHelper($admin_primary_cash_request, $cash_admin);

if (!$request_parameters) {
	AdminHelper::controllerRedirect('/');
}

if (isset($_POST['dodelete']) || isset($_REQUEST['modalconfirm'])) {
	$delete_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'element',
			'cash_action' => 'deletecampaign',
			'id' => $request_parameters[0]
		)
	);
	if ($delete_response['status_uid'] == 'element_deletecampaign_200') {
		// get all campaigns
		$campaigns_response = $cash_admin->requestAndStore(
			array(
				'cash_request_type' => 'element',
				'cash_action' => 'getcampaignsforuser',
				'user_id' => $cash_admin->effective_user_id
			)
		);
		// if there's at least one remaining, select it
		if (count($campaigns_response['payload'])) {
			$current_campaign = $campaigns_response['payload'][count($campaigns_response['payload']) - 1]['id'];
			$admin_primary_cash_request->sessionSet('current_campaign',$current_campaign);

			$settings_request = new CASHRequest(
				array(
					'cash_request_type' => 'system',
					'cash_action' => 'setsettings',
					'type' => 'selected_campaign',
					'value' => $current_campaign,
					'user_id' => $cash_admin->effective_user_id
				)
			);
		} else {
			$settings_request = new CASHRequest(
				array(
					'cash_request_type' => 'system',
					'cash_action' => 'setsettings',
					'type' => 'selected_campaign',
					'value' => -1,
					'user_id' => $cash_admin->effective_user_id
				)
			);
		}

		if (isset($_REQUEST['redirectto'])) {
			$admin_helper->formSuccess('Success. Deleted.',$_REQUEST['redirectto']);
		} else {
			$admin_helper->formSuccess('Success. Deleted.','/elements/view/');
		}
	}
}
$cash_admin->page_data['title'] = 'Campaigns: Delete campaign';

$cash_admin->setPageContentTemplate('delete_confirm');
?>
