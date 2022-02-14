<?php
namespace Stanford\EMA;

use REDCap;
use Exception;
use ExternalModules\ExternalModules;

require_once "emLoggerTrait.php";
require_once "classes/ScheduleInstance.php";
require_once "classes/RepeatingForms.php";
require_once "classes/CronScan.php";
require_once APP_PATH_DOCROOT . "/Libraries/Twilio/Services/Twilio.php";


/**
 *
 */
class EMA extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    const MINUTE_RESOLUTION         = 5;

    // EMA Notification Statuses
    const STATUS_SCHEDULED          = 1;
    const STATUS_OPEN_SMS_SENT      = 2;
    const STATUS_REMINIDER_1_SENT   = 3;
    const STATUS_REMINIDER_2_SENT   = 4;
    const STATUS_COMPLETED          = 96;
    const STATUS_INSTANCE_SKIPPED   = 97;
    const STATUS_WINDOW_CLOSED      = 98;
    const STATUS_OPEN_AFTER_CLOSE   = 99;
    const STATUS_SEND_ERROR         = 100;
    const STATUS_OPTED_OUT          = 101;


    private $twilio_client;
    private $from_number;
    private $last_error_message;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}

    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id,
                                       $survey_hash, $response_id, $repeat_instance) {

        // Check to see if it is time to create EMA instances specified by the configs
        $this->checkWindowScheduleCalculator($project_id, $record);

   }

    public function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id,
                                           $survey_hash, $response_id, $repeat_instance) {

        // Check to see if this form is closed. If so, don't let the participant take the survey
        $this->checkForClosedWindow($project_id, $record, $instrument, $event_id, $repeat_instance);

    }

    public function redcap_survey_complete($project_id, $record, $instrument, $event_id, $group_id,
                                           $survey_hash, $response_id, $repeat_instance) {

        // Set the survey status ema_status to SURVEY_COMPLETE for this instance
        $this->setSurveyCompleteStatus($project_id, $record, $instrument, $event_id, $repeat_instance);

    }


    /**
     * When the survey has been completed, set the status of this instance to Survey Complete.
     *
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $repeat_instance
     */
    private function setSurveyCompleteStatus($project_id, $record, $instrument, $event_id, $repeat_instance) {

        try {
            // Retrieve the data on this form
            $rf = new RepeatingForms($instrument, $event_id);
            $instance = $rf->getInstanceById($record, $repeat_instance);

            // See if the status field exists on this form and if so, update the status
            if (array_key_exists('ema_status', $instance)) {

                // If this form is part of a configuration, set the status to Survey Complete
                $update['ema_status'] = EMA::STATUS_COMPLETED;
                $rf->saveInstance($record, $repeat_instance, $update);
                $this->emDebug("Survey Complete Save Return message: " . $rf->last_error_message);
            }
        } catch (\Exception $ex) {
            $this->emError("Cannot save Survey Complete status with error: " . json_encode($ex));
        }
    }


    /**
     * This function is called before the survey is rendered to the participant.  We need to check if the survey window
     * has closed and if so, don't let the participant take the survey.
     *
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $repeat_instance
     * @return false|void
     */
    private function checkForClosedWindow($project_id, $record, $instrument, $event_id, $repeat_instance) {

        // Retrieve the data for this instance
        try {
            $rf = new RepeatingForms($instrument, $event_id);
            $instance = $rf->getInstanceById($record, $repeat_instance);
        } catch (\Exception $ex) {
            $this->emError("Exception when trying to instance repeating Form Class with message" . json_encode($ex));
            return false;
        }

        // See if there is a window name filled out
        $window_name = $instance['ema_window_name'];
        if (!empty($window_name)) {

            // Retrieve the configurations for windows and schedules
            [$windows, $schedules] = $this->getConfigAsArrays();

            foreach ($windows as $window) {

                // Loop over all windows to see if this instance is part of the window configuration
                if ($window['window-name'] == $window_name) {

                    // Find schedule corresponding to this window
                    $schedule = $this->findScheduleForThisWindow($window['window-schedule-name'], $schedules);

                    // Add the close offset to the send timestamp and see if we are past the close timestamp
                    $datetime_in_sec = strtotime($instance['ema_open_ts']);
                    $close_sec = $datetime_in_sec + $schedule['schedule-close-offset']*60;
                    $now_sec = strtotime("now");

                    // See if we are past the close time
                    if ($close_sec < $now_sec) {

                        // Set the status that the participant tried to access the survey after close
                        $instance['ema_status'] = EMA::STATUS_OPEN_AFTER_CLOSE;
                        $rf->saveInstance($record, $repeat_instance, $instance);
                        $this->emDebug("Closed survey for project $project_id, record $record, form $instrument, event $event_id, instance $repeat_instance");

                        // Hide the normal container
                        $closed_message = $this->getProjectSetting('closed-message');
                        if (empty($closed_message)) $closed_message = 'The survey you are requesting is no longer open.  Please try again after your next prompt';

                        ?>
                            <style>#container, #pagecontent, #ema_closed {display:none;}</style>
                            <div id="ema_closed" class="p-3">
                                <div class="alert text-center m-5">
                                    <h3><?php echo $closed_message ?></h3>
                                </div>
                            </div>
                            <script type="text/javascript">
                                $(document).ready(function() {
                                    let ema = $('#ema_closed');
                                    $('#pagecontent').replaceWith(ema);
                                    ema.show();
                                });
                            </script>
                        <?php
                    }
                }
            }
        }
    }


    /**
     * Read the current config from a single key-value pair in the external module settings table
     */
    function getConfigAsString() {
        $string_config = $this->getProjectSetting($this->PREFIX . '-config');

        return $string_config;
    }

    /**
     * Update the Window/Schedule config using the config builder
     * @param $string_config
     */
    function setConfigAsString($string_config) {
        $this->setProjectSetting($this->PREFIX . '-config', $string_config);
    }

    /**
     * Retrieve the config Window/Schedule data depending on where the data is stored. Users can setup the
     * External Module configuration file or use the configuration builder.  The EM config file has a
     * checkbox which will decide where to retrieve the config data.
     *
     * @return array - Window and schedule configurations
     */
    public function getConfigAsArrays() {

        $window_config_fields = array(
            'window-name', 'window-trigger-logic', 'window-start-field', 'window-start-event', 'window-opt-out-field',
            'window-opt-out-event', 'window-days', 'window-form', 'window-form-event', 'window-schedule-name',
            'schedule-offset-default', 'schedule-offset-override-field', 'schedule-offset-override-event', 'text-message',
            'text-reminder1-message', 'text-reminder2-message', 'cell-phone-field', 'cell-phone-event'
        );
        $schedule_config_fields = array(
            'schedule-name', 'schedule-offsets', 'schedule-randomize-window', 'schedule-reminders', 'schedule-close-offset'
        );

        // Find the source of the configurations: config file or config builder
        $config_source = $this->getProjectSetting('use-config-file');
        if (!$config_source) {

            $windows = array();
            foreach($window_config_fields as $field) {
                $windows = $this->getConfigField($field, $windows);
            }

            $schedules = array();
            foreach($schedule_config_fields as $field) {
                $schedules = $this->getConfigField($field, $schedules);
            }

        } else {

            // Decode the json config and parse into windows and schedules when the config builder is used
            $configs = json_decode($this->getConfigAsString(), true);
            $windows = $configs['windows'];
            $schedules = $configs['schedules'];
        }
        return [$windows, $schedules];
    }

    /**
     * When using the External Module config file to setup the configurations, the data must be transformed to put in
     * arrays per configuration instead of arrays per field.  All fields will be looped through to put into the correct
     * location in the configuration arrays.
     *
     * @param $field
     * @param $storage
     * @return array
     */
    private function getConfigField($field, $storage) {

        $values = $this->getProjectSetting($field);
        for($ncnt = 0; $ncnt < count($values); $ncnt++) {
            if (($field == 'window-days') or ($field == 'schedule-offsets') or ($field == 'schedule-reminders')) {
                $storage[$ncnt][$field] = explode(",", $values[$ncnt]);
            } else {
                $storage[$ncnt][$field] = $values[$ncnt];
            }
        }

        return $storage;
    }

    /**
     * This function determines if it is time to calculate the schedule.  The criteria are:
     *      1. There must be a start date entered and a phone number entered for the texts
     *      2. Evaluate the start logic to make sure all conditions are met
     *      3. Check to see if the opt-out field is not set to 1
     *      4. Check to see if the schedule already exists
     *      5. After checking all these conditions, go calculate the schedule
     *
     * @param $project_id
     * @param $record
     * @param $event_id
     */
    public function checkWindowScheduleCalculator($project_id, $record)
    {

        // Find event name for the event we are saving
        $is_longitudinal = REDCap::isLongitudinal();
        $all_events = REDCap::getEventNames(true, true);

        // Retrieve configuration
        [$windows, $schedules] = $this->getConfigAsArrays();

        // Retrieve the data for this record
        $record_data = $this->getRedcapRecord($project_id, $record);

        // $this->emDebug($windows, $schedules, $record_data);

        // Loop over each config to see if this schedule is ready to process
        foreach ($windows as $config) {

            // Check for required fields - window-start-field in window-start-event is not empty and the phone number is not empty
            $start_date = $this->findFieldValue($record_data, $record, $all_events, $config['window-start-field'], $config['window-start-event'], $is_longitudinal);

            // Retrieve the cell phone field and event from the configuration file
            $phone_number = $this->findFieldValue($record_data, $record, $all_events, $config['cell-phone-field'], $config['cell-phone-event'], $is_longitudinal);
            $this->emDebug("Start date: " . $start_date . ", phone num: " . $phone_number);
            if (!empty($start_date) and !empty($phone_number)) {

                // See if the ready logic has been met
                $ready = REDCap::evaluateLogic($config['window-trigger-logic'], $project_id, $record);
                $this->emDebug("Create window " . $config['window-name'] . " for record $record? " . (int)$ready);
                if ($ready) {

                    // Check that window-opt-out-field is not equal to 1
                    $opt_out = $this->checkForOptOut($record_data, $record, $all_events,
                                $config['window-opt-out-field'], $config['window-opt-out-event'], $is_longitudinal);
                    $this->emDebug("Opt out value: " . $opt_out);
                    if (!$opt_out) {

                        // Get all instances of the window-form/window-event instrument and check that there are not already
                        // instances of the window-name in those instances...
                        $form_event_id = $this->convertEventToID($all_events, $config['window-form-event'], $is_longitudinal);
                        $alreadyCreated = $this->scheduleAlreadyExists($record_data[$record], $config['window-form'],
                            $form_event_id, $config['window-name']);
                        $this->emDebug("Does schedule for window " . $config['window-name'] . " already exist? " . (int) $alreadyCreated);
                        if (!$alreadyCreated) {

                            $schedule = $this->findScheduleForThisWindow($config['window-schedule-name'], $schedules);

                            $custom_start_time = $this->findFieldValue($record_data, $record, $all_events, $config['schedule-offset-override-field'],
                                $config['schedule-offset-override-event'], $is_longitudinal);

                            $final_start_time = $config['schedule-offset-default'];
                            if (!empty($custom_start_time)) {
                                if (strpos($custom_start_time, ":")) {
                                    // Hour:sec field
                                    $cst = new DateTime($custom_start_time);
                                    $hours = $cst->format('h');
                                    $mins = $cst->format('i');
                                    $final_start_time = $hours * 60 + $mins;
                                } elseif (is_numeric($custom_start_time)) {
                                    // number value (assume minutes)
                                    $final_start_time = intval($custom_start_time);
                                } else {
                                    $this->emError("Unknown value for custom start time: " . $custom_start_time);
                                }
                            }

                            // Everything's a go - create the schedule
                            $this->calculateWindowSchedule($record, $config, $start_date,
                                    $final_start_time, $form_event_id, $phone_number, $schedule);
                        }
                    }
                }
            }
        }
    }



    /**
     * This is a utility function to find all existing incomplete instances of the repeating window form and try
     * to delete them.  It can be called with the window (to affect a deletion) or without a window to return a
     * summary of each window and how many instances would be deleted.  This is used by the util page.
     * @param $project_id
     * @param $record
     * @param $window // Name of window
     * @return array
     */
    public function deleteIncompleteInstancesByWindow($project_id, $record, $window = null)
    {
        // Find event name for the event we are saving
        $is_longitudinal = REDCap::isLongitudinal();
        $all_events = REDCap::getEventNames(true, true);

        // Retrieve configurations either from the config file or config builder
        [$windows, $schedules] = $this->getConfigAsArrays();

        // Retrieve the data for this record
        $data = $this->getRedcapRecord($project_id, $record);
        $record_data = $data[$record];

        global $Proj;
        $results = [];

        // Loop over each config to see if this schedule is ready to process
        foreach ($windows as $config) {
            // Get all instances of the window-form/window-event instrument and check that there are not already
            // instances of the window-name in those instances...
            $form_name = $config['window-form'];
            $form_event_id = $this->convertEventToID($all_events, $config['window-form-event'], $is_longitudinal);

            // We need to determine if the form with the data is a repeating form or a repeating event
            $repeatingForms = $Proj->RepeatingFormsEvents;
            $repeat_type = $repeatingForms[$form_event_id];
            if ($repeat_type == 'WHOLE') {
                $form_data = $record_data['repeat_instances'][$form_event_id][''];
            } else {
                $form_data = $record_data['repeat_instances'][$form_event_id][$form_name];
            }

            $complete_field = $form_name . "_complete";

            $count_total = 0;
            $count_incomplete = 0;
            $count_deleted = 0;

            $RF = new RepeatingForms($form_name, $form_event_id);
            foreach($form_data as $instance_id => $instance_data) {
                $count_total++;
                $window_name = $instance_data['ema_window_name'];
                $window_status = $instance_data[$complete_field];
                if($window_status !== "2") {
                    $count_incomplete++;

                    if ($window === $window_name) {
                        // Delete this instance
                        $log_id = $RF->deleteInstance($record, $instance_id);
                        $count_deleted++;
                        $this->emDebug("$record - $instance_id: Deleted with log id #$log_id");
                    }
                }


            }

            $results[] = [
                "name" => $config['window-name'],
                "count" => $count_total,
                "incomplete" => $count_incomplete,
                "deleted" => $count_deleted
            ];
        }
        return $results;
    }



    /**
     * This function instantiates the calculate window schedule class to create the schedule for this record.
     *
     * @param $record
     * @param $config
     * @param $start_date
     * @param $final_start_time
     * @param $form_event_id
     * @param $phone_number
     * @param $schedule
     */
    private function calculateWindowSchedule($record, $config, $start_date, $final_start_time,
                                             $form_event_id, $phone_number, $schedule) {

        try {
            $sched = new ScheduleInstance($this);
            $sched->setUpSchedule($record, $config, $start_date, $final_start_time, $form_event_id, $schedule);
            $sched->createWindowSchedule();
        } catch (\Exception $ex) {
            $this->emError("Exception while instantiating ScheduleInstance with message" . $ex);
        }

    }


    /**
     * This function checks to see if there are already instances created with this Window Name.  If so, don't
     * create more instances.
     *
     * @param $data // Record array from getData
     * @param $form_name
     * @param $form_event_id
     * @param $window_name
     * @return bool
     */
    private function scheduleAlreadyExists($data, $form_name, $form_event_id, $window_name)
    {
        global $Proj;

        $already_created = false;

        // We need to determine if the form with the data is a repeating form or a repeating event
        $repeatingForms = $Proj->RepeatingFormsEvents;
        $repeat_type = $repeatingForms[$form_event_id];
        if ($repeat_type == 'WHOLE') {
            $form_data = $data['repeat_instances'][$form_event_id][''];
        } else {
            $form_data = $data['repeat_instances'][$form_event_id][$form_name];
        }

        foreach($form_data as $instance => $instance_data) {
            if ($instance_data['ema_window_name'] == $window_name) {
                $already_created = true;
                break;
            }
        }

        return $already_created;

    }


    /**
     * This function will take an event name or an event id and returns the event id.
     *
     * @param $all_events
     * @param $event
     * @param $is_longitudinal
     * @return int $event_id
     */
    private function convertEventToID($all_events, $event, $is_longitudinal) {

        global $Proj;

        // First see if the entered event is an event_id. If so, just return it
        if ($is_longitudinal) {
            $event_id_list = array_keys($all_events);
            if (in_array($event, $event_id_list)) {
                $event_id = $event;
            } else {
                // If the event is not an id, it must be an event_name so find the corresponding event_id.
                $event_name_list = array_flip($all_events);
                $event_id = $event_name_list[$event];
            }
        } else {
            // if this is a classical project, get the event_id from the data dictionary
            $event_id = array_keys($Proj->eventInfo)[0];
        }

        return $event_id;
    }

    /**
     * Find the value of the requested field
     *
     * @param $record_data
     * @param $record_id
     * @param $all_events
     * @param $field_name
     * @param $field_event
     * @param $is_longitudinal
     * @return - REDCap value of the request field
     */
    private function findFieldValue($record_data, $record_id, $all_events, $field_name, $field_event, $is_longitudinal)
    {

        if (empty($field_name)) {
            return null;
        } else {

            // First find the event id.  It maybe the value entered or we may need to find it from the event name.
            $event_id = $this->convertEventToID($all_events, $field_event, $is_longitudinal);
        }

        return $record_data[$record_id][$event_id][$field_name];
    }


    /**
     * This function finds the schedule corresponding to the window.
     *
     * @param $schedule_name
     * @param $schedules
     * @return - Schedule config corresponding to the window config
     */
    public function findScheduleForThisWindow($schedule_name, $schedules) {

        $found_schedule = null;

        // find the schedule corresponding to this window
        foreach($schedules as $schedule) {
            if ($schedule_name == $schedule['schedule-name']) {
                $found_schedule = $schedule;
                break;
            }
        }

        return $found_schedule;
    }


    /**
     * This function will check the opt out field and determine if the user opted-out of this schedule
     *
     * @param $record_data
     * @param $record_id
     * @param $all_events
     * @param $opt_out_field
     * @param $opt_out_event
     * @param $is_longitudinal
     * @return bool
     */
    private function checkForOptOut($record_data, $record_id, $all_events, $opt_out_field, $opt_out_event, $is_longitudinal)
    {

        // Check for the opt-out field for this config
        if (!empty($opt_out_field)) {

            // Find the event id
            $event_id = $this->convertEventToID($all_events, $opt_out_event, $is_longitudinal);

            // Find the opt-out field value
            $opt_out_value = $record_data[$record_id][$event_id]["$opt_out_field"];

            // Check the opt-out field and see if it is set.
            $opt_out = ($opt_out_value == EMA::STATUS_OPTED_OUT ? true : false);

        } else {

            // No opt-out field so this config cannot be cancelled
            $opt_out = false;
        }

        return $opt_out;
    }

    /**
     * This function retrieves the REDCap record data.
     *
     * @param $project_id
     * @param $record
     * @return false|mixed
     */
    private function getRedcapRecord($project_id, $record) {

        // ** TODO - should cut down on the fields retrieve but in addition to the form fields, we need the
        // ** TODO - opt-out field, start-time field, etc. so it's not as easy to retrieve just the data we need
        //$fields = array('ema_window_name', 'ema_window_day', 'ema_sequence', 'ema_offset', 'ema_open', 'ema_open_ts', 'ema_status');
        return REDCap::getData($project_id, 'array', $record);
    }

    /**
     * Crons - cron to check for messages to send.  This cron will run every 5 minutes and check each instance of
     * each config for each record in each project.
     */
    public function checkForMessagesToSend()
    {
        // Find all the projects that are using this EMA EM
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);
        while ($row = $enabled->fetch_assoc()) {

            // Check for messages to send for each project using this EM
            $project_id = $row['project_id'];

            // Create the API URL to this project
            $msgCheckURL = $this->getUrl('pages/SendMessages.php?pid=' . $project_id, true, true);
            $this->emDebug("Calling SendMessage cron for pid $project_id at " . $msgCheckURL);
            $ts_start = microtime(true);
            try {
                $client = new \GuzzleHttp\Client;
                $res = $client->request('GET', $msgCheckURL, [
                   'synchronous' => true
                ]);
                $this->emDebug("Guzzle Response", $res->getBody()->getContents());
            } catch (\Exception $ex) {
                $this->emError("Exception throw when instantiating Guzzle with error: " . $ex->getMessage());
            }
            $ts_end = microtime(true) - $ts_start;
            $this->emDebug("Cron for project $project_id took " . $ts_end . " seconds");
        }
    }


    /**
     * Send a Twilio SMS Message
     * @param $to_number string
     * @param $message string
     * @return bool
     * @throws Exception
     */
    public function sendTwilioMessage($to_number, $message) {
        // Check to instantiate the client
        if (empty($this->twilio_client)) {
            $account_sid = $this->getProjectSetting('twilio-account-sid');
            $token = $this->getProjectSetting('twilio-token');
            if (empty($account_sid) | empty($token)) throw new Exception ("Missing Twilio setup - see external module config");
            $this->twilio_client = new \Services_Twilio($account_sid, $token);
        }
        if (empty($this->from_number)) {
            $from_number = $this->getProjectSetting('twilio-from-number');
            if (empty($from_number)) throw new Exception ("Missing Twilio setup - see external module config");
            $this->from_number = self::formatNumber($from_number);
        }

        $to = self::formatNumber($to_number);
        // $this->emDebug("Formatting to number from $to_number to $to");

        try {
            $sms = $this->twilio_client->account->messages->sendMessage(
                $this->from_number,
                $to,
                $message
            );
            if (!empty($sms->error_code) || !empty($sms->error_message)) {
                $error_message = "Error #" . $sms->error_code . " - " . $sms->error_message;
                $this->emError($error_message);
                throw new Exception ($error_message);
            }
        } catch (\Exception $e) {
            $this->emError("Exception when sending sms: " . $e->getMessage());
            $this->last_error_message = $e->getMessage();
            return false;
        }
        return true;
    }


    # format number for E164 format.  Consider adding better validation here
    public static function formatNumber($number) {
        // Strip anything but numbers and add a plus
        $clean_number = preg_replace('/[^\d]/', '', $number);
        if (strlen($clean_number) == 10) $clean_number = '1' . $clean_number;
        return '+' . $clean_number;
    }


}
