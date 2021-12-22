<?php
namespace Stanford\EMA;
/** @var \Stanford\EMA\EMA $module */

use REDCap;
use Exception;
require_once $module->getModulePath() . "./classes/RepeatingForms.php";
require_once APP_PATH_DOCROOT . "/Libraries/Twilio/Services/Twilio.php";

$pid = $module->getProjectId();

// Retrieve all window and schedule configs
[$windows, $schedules] = $module->getConfigAsArrays();

// Get project info
$is_longitudinal = REDCap::isLongitudinal();
$all_events = REDCap::getEventNames(true, true);
$record_field = REDCap::getRecordIdField();

// Retrieve the twilio setup data
$sid = $module->getProjectSetting('twilio-account-sid');
$token = $module->getProjectSetting('twilio-token');
$from_number = $module->getProjectSetting('twilio-from-number');

// Get handle to twilio service
try {
    $client = new \Services_Twilio($sid, $token);
} catch (Exception $ex) {
    $module->emError("Cannot get handle to twilio service. Error message: " . $ex);
    return;
}

// Loop over each window configuration
foreach($windows as $window) {

    //Instantiate the RepeatingForm class
    $rf = handleToRFClass($pid, $window['window-form']);

    // Find the phone number in REDCap for all records
    [$phone_event_name, $phone_event_id] = getEventNameAndId($all_events, $window['cell-phone-event']);
    $phones = getPhoneNumForText($window['cell-phone-field'], $phone_event_id);

    // Find the schedule configuration for this window configuration
    $schedule_name = $window['window-schedule-name'];
    $schedule = $module->findScheduleForThisWindow($schedule_name, $schedules);


    // Retrieve the opt-out field
    $records = getOptOutValues($window['window-opt-out-event'], $record_field, $window['window-opt-out-field']);

    // Loop over each record and check if any texts need to be sent
    foreach($records as $opt_out) {

        $record_id = $opt_out[$record_field];
        $opt_out_value = $opt_out[$window['window-opt-out-field']];
        [$event_name, $event_id] = getEventNameAndId($all_events, $window['window-form-event']);
        $form = $window['window-form'];
        $window_name = $window['window-name'];

        // Retrieve the data for this record
        $data = getRepeatingData($rf, $record_id, $is_longitudinal, $event_name, $event_id, $form, $window_name);
        if (count($data) > 0) {

            // If the opt out flag is set, close out these instances
            if ($opt_out_value == EMA::OPT_OUT_VALUE)
            {
                $module->emDebug("Opt out for record $record_id, window name: $window_name");
                $status = closeInstancesOptOut($rf, $record_id, $event_id, $form, $data, $window_name);

            } else {

                // Determine if the window should be closed, the notification should be sent or a reminder should be sent
                // If the form complete status is 2, don't send out any reminders since the survey was completed.
                determineAction($rf, $client, $record_id, $event_id, $data, $schedule, $window, $from_number, $phones[$record_id]);
            }
        }
        break;
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
function sendText($client, $from_number, $destination_number, $text) {

    global $module;

    try {
        $sms = $client->account->messages->sendMessage(
            $from_number,
            $destination_number,
            $text
        );
    } catch (Exception $ex) {
        $module->emError("Exception when sending text with message $ex");
        return false;
    }

    return true;
}


function getPhoneNumForText($phone_field, $phone_event_id)
{
    global $module;

    // Retrieve the field name and event
    $record_field = REDCap::getRecordIdField();

    $data = REDCap::getData('json', null, array($record_field, $phone_field), $phone_event_id);
    $phone_records = json_decode($data, true);

    // Convert into easy access form {record_id => phone_number}
    $phone = array();
    foreach($phone_records as $record_id => $record_info){
        $phone[$record_info[$record_field]] = $record_info[$phone_field];
    }

    return $phone;
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
 */
function determineAction($rf, $client, $record_id, $event_id, $data, $schedule, $window,
                         $from_number, $destination_number) {

    global $module;

    // Pull data from the config files
    $close_offset   = $schedule['schedule-close-offset'];
    $reminders      = $schedule['schedule-reminders'];
    $text           = $window['text-message'];
    $text_r1        = $window['text-reminder1-message'];
    $text_r2        = $window['text-reminder2-message'];
    $form           = $window['window-form'];
    $module->emDebug("Text: " . $text . ", reminder 1: " . $text_r1 . ", reminder 2: " . $text_r2);

    $close_instances = array();
    $send_text = array();
    foreach($data as $instance_id => $instance_info) {

        // Check to see if the closed timestamp has passed
        $close_yn = windowCheck($instance_info['ema_open_ts'], $close_offset);
        if ($close_yn) {

            // Close dates are based when time has passed. If notification was never sent, set the NOTIFICATION MISSED status
            if ($instance_info['ema_status'] == EMA::SCHEDULE_CALCULATED) {
                $close_instances[$instance_id]['ema_status'] = EMA::NOTIFICATION_MISSED;
            } else {
                $close_instances[$instance_id]['ema_status'] = EMA::WINDOW_CLOSED;
            }
        } else {

            // Now check to see if it is time to send the text
            if ($instance_info['ema_status'] == EMA::SCHEDULE_CALCULATED) {
                $send_yn =  windowCheck($instance_info['ema_open_ts'], 0);
                if ($send_yn) {

                    // To send the text one by one, send here and if successful, add the notification to the array
                    // Retrieve link to survey
                    $survey_link = REDCap::getSurveyLink($record_id, $form, $event_id, $instance_id);
                    $status = sendText($client, $from_number, $destination_number, $text . ' ' . $survey_link);
                    if ($status) {
                        $send_text[$instance_id]['ema_status'] = EMA::NOTIFICATION_SENT;
                    } else {
                        $send_text[$instance_id]['ema_status'] = EMA::ERROR_WHEN_SENDING;
                    }

                }
            } else if ((($instance_info['ema_status'] == EMA::NOTIFICATION_SENT) or
                            ($instance_info['ema_status'] == EMA::REMINDER_1_SENT)) and
                            ($instance_info['ema_status'] <> EMA::SURVEY_COMPLETED)) {

                foreach($reminders as $reminder => $offset) {

                    // Original notification has already been sent. Check to see if a reminder needs to be sent
                    $send_yn = windowCheck($instance_info['ema_open_ts'], $offset);
                    if ($send_yn and $instance_info['ema_status'] == EMA::NOTIFICATION_SENT) {

                        // To send the text one by one, send here and if successful, add the notification to the array
                        // Also need to save the survey link
                        $survey_link = REDCap::getSurveyLink($record_id, $form, $event_id, $instance_id);
                        $status = sendText($client, $from_number, $destination_number, $text_r1 . ' ' . $survey_link);
                        if ($status) {
                            $send_text[$instance_id]['ema_status'] = EMA::REMINDER_1_SENT;
                        } else {
                            $send_text[$instance_id]['ema_status'] = EMA::ERROR_WHEN_SENDING;
                        }

                    } else if ($send_yn and ($instance_info['ema_status'] == EMA::REMINDER_1_SENT) and (count($reminders) == 2)) {

                        // To send the text one by one, send here and if successful, add the notification to the array
                        // Also need to save the survey link
                        $survey_link = REDCap::getSurveyLink($record_id, $form, $event_id, $instance_id);
                        $status = sendText($client, $from_number, $destination_number, $text_r2 . ' ' . $survey_link);
                        if ($status) {
                            $send_text[$instance_id]['ema_status'] = EMA::REMINDER_2_SENT;
                        } else {
                            $send_text[$instance_id]['ema_status'] = EMA::ERROR_WHEN_SENDING;
                        }

                    }
                }
            }
        }
    }

    // If there are instances that are past their close time, save the status
    if (!empty($close_instances)) {
        try {
            $rf->saveAllInstances($record_id, $close_instances, $event_id);

        } catch (Exception $ex) {
            $module->emError("Exception thrown trying to save Close Window data with error message: " . json_encode($ex));
        }
    }

    // If there are instances where we sent out texts, save the status that we've sent them
    if (!empty($send_text)) {
        try {
            $rf->saveAllInstances($record_id, $send_text, $event_id);

        } catch (Exception $ex) {
            $module->emError("Exception thrown trying to save status update data with error message: " . json_encode($ex));
        }
    }

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
function getEventNameAndId($all_events, $event) {

    global $Proj;

    // This is a longitudinal project
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

