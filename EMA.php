<?php
namespace Stanford\EMA;

use REDCap;
use Stanford\SurveyDashboard\SurveyDashboard;

require_once "emLoggerTrait.php";
require_once "classes/ScheduleInstance.php";


class EMA extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    const MINUTE_RESOLUTION         = 5;

    // EMA Notification Statuses
    const SCHEDULE_CALCULATED        = 1;
    const NOTIFICATION_SENT          = 2;
    const REMINDER_1_SENT            = 3;
    const REMINDER_2_SENT            = 4;
    const NOTIFICATION_MISSED        = 97;
    const WINDOW_CLOSED              = 98;
    const ACCESS_AFTER_CLOSED        = 99;

    const OPT_OUT_VALUE              = 1;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}

	public function redcap_module_system_enable( $version ) {
	}


	public function redcap_module_project_enable( $version, $project_id ) {
	}


	public function redcap_module_save_configuration( $project_id ) {
	}

    public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id,
                                       $survey_hash, $response_id, $repeat_instance) {

        $this->emDebug("Save record $record, instrument $instrument, project id $project_id");
        // Check to see if it is time to create EMA instances specified by the configs
        $this->checkWindowScheduleCalculator($project_id, $record);

    }


    /**
     * Read the current config from a single key-value pair in the external module settings table
     */
    function getConfigAsString() {
        $string_config = $this->getProjectSetting($this->PREFIX . '-config');
        //SurveyDashboard::log($string_config);
/*
        $string_config =
            '{
    "windows": [
        {
            "window-name": "Baseline",
            "window-trigger-logic": "[ready_logic(1)] = 1",
            "window-start-field":"w1_start_date",
            "window-start-event":"baseline_arm_1",
            "window-days": [1,2,3,4,6,7],
            "window-schedule-name": "4xDay",
            "window-form":"ema_tracker",
            "window-form-event":"104",
            "window-opt-out-field":"exclude_if",
            "window-opt-out-event":"baseline_arm_1",
            "schedule-offset-default": 480,
            "schedule-offset-override-field":"custom_start_date",
            "schedule-offset-override-event":"baseline_arm_1",
            "text-message":"Please fill out this survey: ",
            "text-reminder1-message":"This is a reminder to please fill out the survey: ",
            "text-reminder2-message":"This is your final reminder to please fill out the survey: "
        }
    ],
    "schedules": [
        {
            "schedule-name":"4xDay",
            "schedule-offsets": [0,240,480,720],
            "schedule-randomize-window": "10",
            "schedule-reminders": [5,10],
            "schedule-close-offset": 20,
            "schedule-length": 100
        }
    ]
    }';
*/
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
            'text_reminder1-message', 'text_reminder2-message'
        );
        $schedule_config_fields = array(
            'schedule-name', 'schedule-offsets', 'schedule-randomize-window', 'schedule-reminders', 'schedule-close-offset',
            'schedule-length'
        );

        // Find the source of the configurations: config file or config builder
        $config_source = $this->getProjectSetting('use-config-file');
        if ($config_source) {

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
        // Retrieve the cell phone field and event from the configuration file
        $phone_field = $this->getProjectSetting('cell-phone-field');
        $phone_event = $this->getProjectSetting('cell-phone-event');

        // Find event name for the event we are saving
        $is_longitudinal = REDCap::isLongitudinal();
        $all_events = REDCap::getEventNames(true, true);

        // Retrieve configurations either from the config file or config builder
        [$windows, $schedules] = $this->getConfigAsArrays();

        // Retrieve the data for this record
        $record_data = $this->getRedcapRecord($project_id, $record);

        // Loop over each config to see if this schedule is ready to process
        foreach ($windows as $config) {

            // Check for required fields - window-start-field in window-start-event is not empty and the phone number is not empty
            $start_date = $this->findFieldValue($record_data, $record, $all_events, $config['window-start-field'], $config['window-start-event'], $is_longitudinal);
            $phone_number = $this->findFieldValue($record_data, $record, $all_events, $phone_field, $phone_event, $is_longitudinal);
            $this->emDebug("Start date: " . $start_date . ", phone num: " . $phone_number);
            if (!empty($start_date) and !empty($phone_number)) {

                // See if the ready logic has been met
                $ready = REDCap::evaluateLogic($config['window-trigger-logic'], $project_id, $record);
                $this->emDebug("Is this ready? " . $ready);
                if ($ready) {

                    // Check that window-opt-out-field is not equal to 1
                    $opt_out = $this->checkForOptOut($record_data, $record, $all_events,
                                $config['window-opt-out-field'], $config['window-opt-out-event'], $is_longitudinal);
                    $this->emDebug("Opt out value: " . $opt_out);
                    if (!$opt_out) {

                        // Get all instances of the window-form/window-event instrument and check that there are not already instances of the window-name in those instances...
                        $form_event_id = $this->convertEventToID($all_events, $config['window-form-event'], $is_longitudinal);
                        $alreadyCreated = $this->scheduleAlreadyExists($record_data[$record], $config['window-form'],
                            $form_event_id, $config['window-name']);
                        $this->emDebug("Schedule already exists? " . $alreadyCreated);
                        if (!$alreadyCreated) {

                            $schedule = $this->findScheduleForThisWindow($config['window-schedule-name'], $schedules);

                            $custom_start_time = $this->findFieldValue($record_data, $record, $all_events, $config['schedule-offset-override-field'],
                                $config['schedule-offset-override-event'], $is_longitudinal);

                            $final_start_time = (empty($custom_start_time) ? $config['schedule-offset-default'] : $custom_start_time);

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
            $sched->setUpSchedule($record, $config, $start_date, $final_start_time, $form_event_id,
                $phone_number, $schedule);
            $sched->createWindowSchedule();
        } catch (Exception $ex) {
            $this->emError("Exception while instantiating ScheduleInstance with message" . $ex);
        }

    }


    /**
     * This function checks to see if there are already instances created with this Window Name.  If so, don't
     * create more instances.
     *
     * @param $data
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
     * @return event_id
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
     * @param $opt_out_field
     * @param $data
     * @return bool
     */

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
            $opt_out = ($opt_out_value == EMA::OPT_OUT_VALUE ? true : false);

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
        // opt-out field, start-time field, etc. so it's not as easy to retrieve just the data we need
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

        while ($proj = $enabled->fetch_assoc()) {

            // Check for messages to send for each project using this EM
            $proj_id = $proj['project_id'];

            // Create the API URL to this project
            $msgCheckURL = $this->getUrl('pages/SendMessages.php?pid=' . $proj_id, true, true);
            $this->emDebug("Calling cron to check for messages to send for pid $proj_id at URL " . $msgCheckURL);

            // Call the project through the API so it will be in project context
            $response = http_get($msgCheckURL);

        }
    }
}
