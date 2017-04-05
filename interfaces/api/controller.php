<?php
/**
 * The main API controller
 *
 * @package api.org.cashmusic
 * @author CASH Music
 * @link http://cashmusic.org/
 *
 * Copyright (c) 2014, CASH Music
 * Licensed under the Affero General Public License version 3.
 * See http://www.gnu.org/licenses/agpl-3.0.html
 *
 */

namespace CASHMusic\API;

use CASHMusic\Core\CASHSystem;

$cash_settings = json_decode(getenv('cashmusic_platform_settings'),true);
// env settings allow use on multi-server, multi-user instances
if ($cash_settings) {
	// thanks to json_decode this will be null if the
	if (isset($cash_settings['platforminitlocation'])) {
		$cashmusic_root = str_replace('/cashmusic.php', '', $_SERVER['DOCUMENT_ROOT'] . $cash_settings['platforminitlocation']);
	}
}

// set up autoload for core classes
//require_once(__DIR__ . '/../../framework/classes/core/CASHSystem.php');
CASHSystem::startUp();

// push away anyone who's trying to access the controller directly
if (strrpos($_SERVER['REQUEST_URI'],'controller.php') !== false) {
	header($http_codes[403], true, 403);
	exit;
} else {
	// instantiate the API, pass the request from .htaccess to it
	//require_once('./classes/APICore.php');
	if (!isset($_REQUEST['p'])) {
		$final_request = '/';
	} else {
		$final_request = $_REQUEST['p'];
	}
	new APICore($final_request);
	exit;
}
?>
