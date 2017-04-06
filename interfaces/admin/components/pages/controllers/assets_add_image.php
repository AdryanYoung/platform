<?php

namespace CASHMusic\Admin;

use CASHMusic\Core\CASHSystem as CASHSystem;
use CASHMusic\Core\CASHRequest as CASHRequest;
use ArrayIterator;
use CASHMusic\Admin\AdminHelper;

// parsing posted data:
if (isset($_POST['doassetadd'])) {

	$parent_id = -1;
	if ($_POST['parent_type'] == 'release') {
		if ($_POST['parent_id']) {
			$parent_id = $_POST['parent_id'];
		}
	}

	$add_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'asset',
			'cash_action' => 'addasset',
			'title' => '',
			'description' => '',
			'parent_id' => $parent_id,
			'connection_id' => $_POST['connection_id'],
			'location' => $_POST['asset_location'],
			'user_id' => $cash_admin->effective_user_id,
			'type' => 'image'
		)
	);

	if ($add_response['payload']) {
		// check for metadata settings
		if ($_POST['parent_type'] == 'release') {
			// try getting the parent asset
			$asset_response = $cash_admin->requestAndStore(
				array(
					'cash_request_type' => 'asset',
					'cash_action' => 'getasset',
					'id' => $_POST['parent_id']
				)
			);
			// found it. now we can overwrite or extend the original metadata
			if ($asset_response['payload']) {
				// modify the existing chunk o metadata
				$new_metadata = $asset_response['payload']['metadata'];
				$new_metadata['cover'] = $add_response['payload'];

				// make it public
				$edit_response = $cash_admin->requestAndStore(
					array(
						'cash_request_type' => 'asset',
						'cash_action' => 'makepublic',
						'id' => $add_response['payload'],
						'commit' => true,
						'user_id' => $cash_admin->effective_user_id
					)
				);

				// now make the actual edits
				$edit_response = $cash_admin->requestAndStore(
					array(
						'cash_request_type' => 'asset',
						'cash_action' => 'editasset',
						'id' => $_POST['parent_id'],
						'user_id' => $cash_admin->effective_user_id,
						'metadata' => $new_metadata
					)
				);
			}
			AdminHelper::formSuccess('Success.','/assets/edit/' . $_POST['parent_id']);
		}
		if ($_POST['parent_type'] == 'item') {
			// make it public
			$edit_response = $cash_admin->requestAndStore(
				array(
					'cash_request_type' => 'asset',
					'cash_action' => 'makepublic',
					'id' => $add_response['payload'],
					'commit' => true,
					'user_id' => $cash_admin->effective_user_id
				)
			);

			// tell the item to use the asset
			$item_response = $cash_admin->requestAndStore(
				array(
					'cash_request_type' => 'commerce',
					'cash_action' => 'edititem',
					'id' => $_POST['parent_id'],
					'descriptive_asset' => $add_response['payload'],
					'user_id' => $cash_admin->effective_user_id
				)
			);
			AdminHelper::formSuccess('Success.','/commerce/items/edit/' . $_POST['parent_id']);
		}
	} else {
		if ($_POST['parent_type'] == 'release') {
			AdminHelper::formFailure('Error. Something didn\'t work.','/assets/edit/' . $_POST['parent_id']);
		} elseif ($_POST['parent_type'] == 'item') {
			AdminHelper::formFailure('Error. Something didn\'t work.','/commerce/items/edit/' . $_POST['parent_id']);
		} else {
			AdminHelper::formFailure('Error. Something didn\'t work.','/assets/');
		}
	}
}

$cash_admin->page_data['form_state_action'] = 'doassetadd';
$cash_admin->page_data['asset_button_text'] = 'Save changes';
$cash_admin->page_data['ui_title'] = 'Add an image';

$cash_admin->page_data['connection_options'] = AdminHelper::echoConnectionsOptions('assets', 0, true);

if (isset($request_parameters[1])) {
	$cash_admin->page_data['parent_type'] = $request_parameters[0];
	$cash_admin->page_data['parent_id'] = $request_parameters[1];
}

$cash_admin->page_data['assets_add_action'] = true;
$cash_admin->setPageContentTemplate('assets_details_image');
?>
