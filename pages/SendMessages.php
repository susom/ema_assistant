<?php
namespace Stanford\EMA;
/** @var \Stanford\EMA\EMA $module */

use REDCap;
use Exception;
require_once $module->getModulePath() . "./classes/RepeatingForms.php";

$pid = $module->getProjectId();

// Retrieve all window and schedule configs
[$windows, $schedules] = $module->getConfigAsArrays();

// Get project info
$is_longitudinal = REDCap::isLongitudinal();
$all_events = REDCap::getEventNames(true, true);
$record_field = REDCap::getRecordIdField();
$classical_data = null;


// Loop over each window configuration
foreach($windows as $window) {

    //Instantiate the RepeatingForm class
    $rf = handleToRFClass($pid, $window['window-form']);

    // Find the schedule configuration for this window configuration
    $schedule_name = $window['window-schedule-name'];
    $schedule = $module->findScheduleForThisWindow($schedule_name, $schedules);

    // Retrieve the opt-out field
    $opt_outs = getOptOutValues($window['window-opt-out-event'], $record_field, $window['window-opt-out-field']);

    // Loop over each record and check if any texts need to be sent
    foreach($opt_outs as $opt_out) {

        $record_id = $opt_out[$record_field];
        $opt_out_value = $opt_out[$window['window-opt-out-field']];
        [$event_name, $event_id] = getEventNameAndId($window['window-form-event']);
        $form = $window['window-form'];
        $window_name = $window['window-name'];

        // Retrieve the data for this record
        $data = getRepeatingData($rf, $record_id, $is_longitudinal, $event_name, $event_id, $form, $window_name);
        $module->emDebug("Instances: " . json_encode($data));
        if (count($data) > 0) {

            // If the opt out flag is set, close out these instances
            if ($opt_out_value == EMA::OPT_OUT_VALUE)
            {
                $module->emDebug("Opt out for record $record_id, window name: $window_name");
                $status = closeInstancesOptOut($rf, $record_id, $event_id, $form, $data, $window_name);

            } else {

                // Determine if the window should be closed, the notification should be sent or a reminder should be sent
                // If the form complete status is 2, don't send out any reminders since the survey was completed.
                $sendInstances = determineAction($rf, $record_id, $event_id, $data, $schedule['schedule-close-offset'],
                     $schedule['schedule-reminders'], $window['window-form'] . '_complete');
                if (!empty($sendInstances)) {
                    $status = sendTexts($rf, $record_id, $event_id, $sendInstances);
                }
            }
        }
    }
}

/**
 * Send out the texts (either Original text, first reminder or second reminder) for this window configuration for
 * this record.  Once the text is sent, update the status for this instance.
 *
 * @param $rf
 * @param $record_id
 * @param $event_id
 * @param $send
 * @return bool
 */
function sendTexts($rf, $record_id, $event_id, $send) {

    global $module;

    foreach($send as $instance_id => $which_text) {

        if ($which_text['ema_status'] == EMA::NOTIFICATION_SENT) {

            // Send original text
            $module->emDebug("Instance ID: " . $instance_id . " sending original text");

        } else if ($which_text['ema_status'] == EMA::REMINDER_1_SENT) {

            // Send reminder 1
            $module->emDebug("Instance ID: " . $instance_id . " sending reminder 1 text");

        } else if ($which_text['ema_status'] == EMA::REMINDER_2_SENT) {

            // Send reminder 2
            $module->emDebug("Instance ID: " . $instance_id . " sending reminder 2 text");

        }
    }

    // if all texts are successfully sent, update the status for each instance
    if (!empty($send)) {
        try {
            $rf->saveAllInstances($record_id, $send, $event_id);
        } catch (Exception $ex) {
            $module->emError("Exception throw trying to save sent texts for record $record_id");
        }
    }


    return true;
}


/**
 * This function will determine if each instance is ready for a text to be sent out.  We need to check if the original
 * text needs to be sent, or a reminder text needs sending.
 *
 * If the window close timestamp has passed, we don't send out anything. Instead we set the status of the instance  If
 * a text never went out, we set the status to NOTIFICATION MISSED and if the notification was sent out, we set the status
 * WINDOW CLOSED.
 *
 * @param $rf
 * @param $record_id
 * @param $event_id
 * @param $data
 * @param $close_offset
 * @param $reminders
 * @param $form_complete_field
 * @return array
 */
function determineAction($rf, $record_id, $event_id, $data, $close_offset, $reminders, $form_complete_field) {

    global $module;

    $close_instances = array();
    $send_text = array();
    foreach($data as $instance_id => $instance_info) {

        // Check to see if the closed timestamp has passed
        $close_yn = windowCheck($instance_info['ema_open_ts'], $close_offset);
        if ($close_yn) {

            // Close dates are based when time has passed. If notification was never sent, set the NOTIFIXATION MISSED status
            if ($instance_info['ema_status'] == EMA::SCHEDULE_CALCULATED) {
                $close_instances[$instance_id]['ema_status'] = EMA::NOTIFICATION_MISSED;
            } else {
                $close_instances[$instance_id]['ema_status'] = EMA::WINDOW_CLOSED;
            }
        } else {

            $module->emDebug("Form complete field: " . $form_complete_field, ", and value: " . $instance_info[$form_complete_field]);
            // Now check to see if it is time to send the text
            if ($instance_info['ema_status'] == EMA::SCHEDULE_CALCULATED) {
                $send_yn =  windowCheck($instance_info['ema_open_ts'], 0);
                if ($send_yn) {

                    // To send the text one by one, send here and if successful, add the notification to the array
                    // Also need to save the survey link
                    $send_text[$instance_id]['ema_status'] = EMA::NOTIFICATION_SENT;
                }
            } else if ((($instance_info['ema_status'] == EMA::NOTIFICATION_SENT) or
                            ($instance_info['ema_status'] == EMA::REMINDER_1_SENT)) and
                            ($instance_info[$form_complete_field] <> 2)) {

                foreach($reminders as $reminder => $offset) {

                    // Original notification has already been sent. Check to see if a reminder needs to be sent
                    $send_yn = windowCheck($instance_info['ema_open_ts'], $offset);
                    if ($send_yn and $instance_info['ema_status'] == EMA::NOTIFICATION_SENT) {

                        // To send the text one by one, send here and if successful, add the notification to the array
                        // Also need to save the survey link
                        $send_text[$instance_id]['ema_status'] = EMA::REMINDER_1_SENT;

                    } else if ($send_yn and ($instance_info['ema_status'] == EMA::REMINDER_1_SENT) and (count($reminders) == 2)) {

                        // To send the text one by one, send here and if successful, add the notification to the array
                        // Also need to save the survey link
                        $send_text[$instance_id]['ema_status'] = EMA::REMINDER_2_SENT;

                    }
                }
            }
        }
    }

    // If there are instances that are past their close time, save the status
    if (!empty($close_instances)) {
        try {
            $rf->saveAllInstances($record_id, $close_instances, $event_id);

            // If sending texts one by one, save new status of the instances where texts were sent
            //$rf->saveAllInstances($record_id, $send_text, $event_id);

        } catch (Exception $ex) {
            $module->emError("Exception throw trying to save Close Window data");
        }
    }

    return $send_text;
}

/**
 * This function adds minutes to a timestamp and determines if that new timestamp has passed
 *
 * @param $timestamp
 * @param $minutes
 * @return bool
 */
function windowCheck($timestamp, $minutes) {

    // Convert the timestamp to seconds and the entered number of minutes to the timestamp
    $datetime_in_secs = strtotime($timestamp);
    $check_time = $datetime_in_secs + $minutes*60;

    // Find out number of seconds it is now
    $now_in_secs = strtotime("now");

    // If now is greater than the entered timestamp, send back true.
    if ($check_time < $now_in_secs) {
        return true;
    } else {
        return false;
    }

}

/**
 * This function will set all instances of this Window config to opt-out since the opt-out field is set.
 *
 * @param $rf
 * @param $record_id
 * @param $event_id
 * @param $form
 * @param $data
 * @param $window_name
 * @return false|mixed
 */
function closeInstancesOptOut($rf, $record_id, $event_id, $form, $data, $window_name) {

    global $module;

    // Set the status of each instance to Window Closed
    $save_data = array();
    foreach($data as $instance_id => $instance_data) {
        $save_data[$instance_id]['ema_status'] = EMA::WINDOW_CLOSED;
    }

    // Save all the instances of this form/event to set the status as window closed
    try {

        // Save all these instances from this window
        $status = $rf->saveAllInstances($record_id, $save_data, $event_id);

    } catch (Exception $ex) {
        $module->emError("Exception when instantiating RepeatingForms to Close All Instances of window $window_name");
        return false;
    }

    return $status;
}


/**
 * The opt-out field must be on a non-repeating form, retrieve the field for each record in this window configuration.
 *
 * @param $event
 * @param $record_field
 * @param $field
 * @return mixed
 */
function getOptOutValues($event, $record_field, $field) {

    global $module;

    // Retrieve the opt-out field for each record.  This is not on a repeating form
    $data = REDCap::getData('json', null, array($record_field, $field), array($event));
    $records = json_decode($data, true);

    return $records;

}

/**
 * This function will retrieve repeating instances for this window configuration.  We need to filter out
 * any instances that are closed but the filter is not working correctly (or I am not setting up the filter
 * correctly) so I have to manually filter them out.
 *
 * @param $rf
 * @param $record_id
 * @param $is_longitudinal
 * @param $event_name
 * @param $event_id
 * @param $form
 * @param $window_name
 * @return array|false
 */
function getRepeatingData($rf, $record_id, $is_longitudinal, $event_name, $event_id, $form, $window_name) {

    global $module;

    // We want to filter on window name and only retrieve non-closed instances.  For some reason, the filter is not working
    // This should actually be window_name = ema_window_name and ema_status not equal NOTIFICATION MISSED or WINDOW CLOSED
    //$filter = "([" . $event_name . "][ema_window_name] = '" . $window['window-name'] . "') and ([" . $event_name . "][ema_status] <> '" . EMA::WINDOW_CLOSED . "')";
    if ($is_longitudinal) {
        $filter = "[" . $event_name . "][ema_window_name] = '" . $window_name . "'";
    } else {
        $event_id = null;
        $filter = "['ema_window_name'] = '" . $window_name . "'";
    }
    $module->emDebug("Filter: " . $filter);

    // Retrieve all the instances of this form/event with the name of the window
    try {

        // Add option to load only certain fields so we don't have to retrieve the whole form
        $rf->loadData($record_id, $event_id, $filter);
        $instances = $rf->getAllInstances($record_id, $event_id);

        if ($is_longitudinal) {
            $all_instances  = $instances[$record_id][$event_id];
        } else {
            $all_instances = $instances[$record_id];
        }
    } catch (Exception $ex) {
        $module->emError("Exception when instantiating RepeatingForms");
        return false;
    }

    // Since we are not able to filter out closed and missed instances, take them out here.  Once the filter works correctly,
    // this section can be deleted
    $filter_instances = array();
    foreach ($all_instances as $instance_id => $instance_info) {
        if (($instance_info['ema_status'] != EMA::WINDOW_CLOSED) and ($instance_info['ema_status'] != EMA::NOTIFICATION_MISSED)) {
            $filter_instances[$instance_id] = $instance_info;
        }
    }

    $module->emDebug("Num of instances not closed or notifications missed: " . count($filter_instances));
    return $filter_instances;
}


/**
 * This function instantiate the Repeating Form class and that handle will be used to save repeating form data.
 *
 * @param $pid
 * @param $form
 * @return false|RepeatingForms
 */
function handleToRFClass($pid, $form) {

    global $module;

    // Retrieve all the instances of this form/event with the name of the window
    try {

        // Add option to load only certain fields so we don't have to retrieve the whole form
        $rf = new RepeatingForms($pid, $form);
    } catch (Exception $ex) {
        $module->emError("Exception when instantiating RepeatingForms");
        return false;
    }

    return $rf;

}


/**
 * This function will accept an event name or event id and return both name and event.
 *
 * @param $event
 * @return array
 */
function getEventNameAndId($event) {

    global $Proj;

    // This is a longitudinal project
    $all_events = REDCap::getEventNames(true, true);
    $event_ids = array_keys($all_events);

    if (!in_array($event, $event_ids)) {

        // Incoming event is an event name
        $names_to_ids = array_flip($all_events);
        $event_name = $event;
        $event_id = $names_to_ids[$event];

    } else {

        // Incoming event is an event id
        $event_name = $all_events[$event];
        $event_id = $event;
    }

    return [$event_name, $event_id];
}

