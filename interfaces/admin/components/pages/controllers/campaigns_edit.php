<?php

namespace CASHMusic\Admin;

use CASHMusic\Core\CASHSystem as CASHSystem;
use CASHMusic\Core\CASHRequest as CASHRequest;
use ArrayIterator;
use CASHMusic\Admin\AdminHelper;

$admin_helper = new AdminHelper($admin_primary_cash_request, $cash_admin);

// parsing posted data:
if (isset($_POST['docampaignedit'])) {
	// do the actual list add stuffs...
	$edit_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'element',
			'cash_action' => 'editcampaign',
			'id' => $request_parameters[0],
			'title' => $_POST['campaign_title'],
			'description' => $_POST['campaign_description']
		)
	);
	if ($edit_response['status_uid'] == 'element_editcampaign_200') {
		$admin_helper->formSuccess('Success. Edited.','/');
	} else {
		$admin_helper->formFailure('Error. There was a problem editing your campaign.','/');
	}
}

$current_response = $cash_admin->requestAndStore(
	array(
		'cash_request_type' => 'element',
		'cash_action' => 'getcampaign',
		'id' => $request_parameters[0]
	)
);
$cash_admin->page_data['ui_title'] = 'Campaigns: Edit "' . $current_response['payload']['title'] . '"';

$current_campaign = $current_response['payload'];

if (is_array($current_campaign)) {
	$cash_admin->page_data = array_merge($cash_admin->page_data,$current_campaign);
}
$cash_admin->page_data['form_state_action'] = 'docampaignedit';
$cash_admin->page_data['button_text'] = 'Save changes';
$cash_admin->page_data['delete_text'] = 'Delete this campaign';
$cash_admin->page_data['edit_exisiting'] = $current_response['payload'];



$elements_response = $cash_admin->requestAndStore(
	array(
		'cash_request_type' => 'element',
		'cash_action' => 'getelementsforcampaign',
		'id' => $request_parameters[0]
	)
);

if (is_array($elements_response['payload'])) {
	foreach ($elements_response['payload'] as &$element) {
		if ($element['modification_date'] == 0) {
			$element['formatted_date'] = CASHSystem::formatTimeAgo($element['creation_date']);
		} else {
			$element['formatted_date'] = CASHSystem::formatTimeAgo($element['modification_date']);
		}
	}
	$cash_admin->page_data['elements_for_campaign'] = new ArrayIterator($elements_response['payload']);
}



$cash_admin->setPageContentTemplate('campaign_edit');
?>
