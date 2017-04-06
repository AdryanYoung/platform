<?php

namespace CASHMusic\Admin;

use CASHMusic\Core\CASHSystem as CASHSystem;
use CASHMusic\Core\CASHRequest as CASHRequest;
use ArrayIterator;
use CASHMusic\Admin\AdminHelper;


function formatEventOutput(&$response) {
	foreach ($response['payload'] as &$event) {
		// fix empty venue name
		$event['venue_name'] = isset($event['venue_name']) ? $event['venue_name'] : 'TBA';

		// format date for viewing
		$event['formatted_date'] = date('d M',$event['date']);

		// format location
		if (strtolower($event['venue_country']) == 'usa' || strtolower($event['venue_country']) == 'canada') {
			$event['event_location'] = $event['venue_city'] . ', ' . $event['venue_region'];
		} else {
			$event['event_location'] = $event['venue_city'] . ', ' . $event['venue_country'];
		}
		if ($event['event_location'] == ', ') {
			$event['event_location'] = '';
		}

	}
}

$thisweek_response = $cash_admin->requestAndStore(
	array(
		'cash_request_type' => 'calendar',
		'cash_action' => 'geteventsnostatus',
		'user_id' => $cash_admin->effective_user_id,
		'cutoff_date_low' => strtotime("monday this week"),
		'cutoff_date_high' => strtotime("sunday this week 11:59PM")
	)
);

// this week
if (is_array($thisweek_response['payload'])) {
	formatEventOutput($thisweek_response);
	$cash_admin->page_data['events_thisweek'] = new ArrayIterator($thisweek_response['payload']);
}

// unpublished
// most accessed
if (is_array($unpublished_response['payload'])) {
	formatEventOutput($unpublished_response);
	$cash_admin->page_data['events_unpublished'] = new ArrayIterator($unpublished_response['payload']);
}

$event = "";
// Archive events
$allpast_response = $cash_admin->requestAndStore(
	array(
		'cash_request_type' => 'calendar',
		'cash_action' => 'geteventsnostatus',
		'user_id' => $cash_admin->effective_user_id,
		'visible_event_types' => 'archive'
	)
);

if (is_array($allpast_response['payload'])) {
    formatEventOutput($allpast_response);

    $cash_admin->page_data['events_allpast'] = new ArrayIterator(array_reverse($allpast_response['payload']));
}

// Upcoming events
$allfuture_response = $cash_admin->requestAndStore(
	array(
		'cash_request_type' => 'calendar',
		'cash_action' => 'geteventsnostatus',
		'user_id' => $cash_admin->effective_user_id,
		'visible_event_types' => 'upcoming'
	)
);

if (is_array($allfuture_response['payload'])) {

    formatEventOutput($allfuture_response);

	$cash_admin->page_data['events_allfuture'] = new ArrayIterator($allfuture_response['payload']);
}


$cash_admin->page_data['options_venues'] = AdminHelper::echoFormOptions('venues',0,false,true);

// Is Event Published/Cancelled Page data
$cash_admin->page_data['published'] = isset($event['published']) ? $event['published'] : false;
$cash_admin->page_data['cancelled'] = isset($event['cancelled']) ? $event['cancelled'] : false;

// No events
if (!$allpast_response['payload'] && !$allfuture_response['payload']) {
	$cash_admin->page_data['no_events'] =  true;
}

$cash_admin->setPageContentTemplate('calendar');
?>
