<?php

namespace CASHMusic\Admin;

use CASHMusic\Core\CASHSystem as CASHSystem;
use CASHMusic\Core\CASHRequest as CASHRequest;
use ArrayIterator;
use CASHMusic\Admin\AdminHelper;

if (isset($_REQUEST['modalconfirm'])) {
	$new_template = 0;
	$requested_campaign_id = $request_parameters[0];

	if ($requested_campaign_id != 0) {
		$current_response = $cash_admin->requestAndStore(
			array(
				'cash_request_type' => 'element',
				'cash_action' => 'getcampaign',
				'id' => $requested_campaign_id
			)
		);
		$campaign = $current_response['payload'];
		$new_template = $campaign['template_id'];
	}

	$settings_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'system',
			'cash_action' => 'setsettings',
			'type' => 'public_profile_template',
			'value' => $new_template,
			'user_id' => $cash_admin->effective_user_id
		)
	);

	if ($settings_response['payload']) {
		if ($new_template == 0) {
			AdminHelper::formSuccess('Success. You have unpublished all campaigns.','/page/');
		} else {
			AdminHelper::formSuccess('Success. Campaign published.','/page/');
		}
	} else {
		AdminHelper::formFailure('Error. Something just didn\'t work right.','/page/');
	}

} else {
	AdminHelper::controllerRedirect('/page/');
}
?>
