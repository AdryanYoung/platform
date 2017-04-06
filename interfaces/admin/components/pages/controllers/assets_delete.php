<?php

namespace CASHMusic\Admin;

use CASHMusic\Core\CASHSystem as CASHSystem;
use CASHMusic\Core\CASHRequest as CASHRequest;
use ArrayIterator;
use CASHMusic\Admin\AdminHelper;

if (!$request_parameters) {
	AdminHelper::controllerRedirect('/assets/');
}

if (isset($_POST['dodelete']) || isset($_REQUEST['modalconfirm'])) {
	$delete_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'asset', 
			'cash_action' => 'deleteasset',
			'id' => $request_parameters[0],
            'connection_id' => $request_parameters[1],
            'user_id' => AdminHelper::getPersistentData('cash_effective_user')
		)
	);
	if ($delete_response['status_uid'] == 'asset_deleteasset_200') {
		AdminHelper::formSuccess('Success. Deleted.','/assets/');
	}
}
$cash_admin->page_data['title'] = 'Assets: Delete asset';

$cash_admin->setPageContentTemplate('delete_confirm');
?>