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

            // We need to find the data for each field specified in the config.
            //  They can be in the same event or different events.
            if ($is_longitudinal) {

                // Find the form event id - the given event may be a name or id
                $form_event_id = $this->convertEventToID($all_events, $config['window-form-event'], $is_longitudinal);
                $form_data = $this->findFormData($record_data[$record], $form_event_id, $config['window-form']);

                $start_event_id = $this->convertEventToID($all_events, $config['window-start-event'], $is_longitudinal);
                $start_data = $record_data[$record][$start_event_id];

                $opt_out_event_id = $this->convertEventToID($all_events, $config['window-opt-out-event'], $is_longitudinal);
                $opt_out_data = $record_data[$record][$opt_out_event_id];

                $override_event_id = $this->convertEventToID($all_events, $config['schedule-offset-override-event'], $is_longitudinal);
                $override_data = $record_data[$record][$override_event_id];

                $phone_event_id = $this->convertEventToID($all_events, $phone_event, $is_longitudinal);
                $phone_data = $record_data[$record][$phone_event_id];

            } else {
                $form_event_id = $this->convertEventToID($all_events, $config['window-form-event'], $is_longitudinal);
                $form_data = $this->findFormData($record_data[$record], $form_event_id, $config['window-form']);
                $phone_data = $override_data = $start_data = $opt_out_data = $record_data[$record][$form_event_id];
            }

            // Check for required fields - window-start-field in window-start-event is not empty
            $start_date = $this->findRecordData($start_data, $config['window-start-field']);
            $phone_number = $this->findRecordData($phone_data, $phone_field);
            if (!empty($start_date) and !empty($phone_number)) {

                // See if the ready logic has been met
                $ready = REDCap::evaluateLogic($config['window-trigger-logic'], $project_id, $record);
                if ($ready) {

                    // Check that window-opt-out-field is not equal to 1
                    $opt_out = $this->checkForOptOut($config['window-opt-out-field'], $opt_out_data);
                    if (!$opt_out) {

                        // Get all instances of the window-form/window-event instrument and check that there are not already instances of the window-name in those instances...
                        $alreadyCreated = $this->scheduleAlreadyExists($form_data, $config['window-name']);
                        if (!$alreadyCreated) {

                            $schedule = $this->findScheduleForThisWindow($config['window-schedule-name'], $schedules);

                            $custom_start_time = $this->findRecordData($override_data, $config['schedule-offset-override-field']);

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
     * This function checks to see if there are already instances created with this Window Name.  If so, don't
     * create more instances.
     *
     * @param $data
     * @param $window_name
     * @return bool
     */
    private function scheduleAlreadyExists($data, $window_name)
    {

        $this->emDebug("Data: " . json_encode($data) . ", window_name: $window_name");
        $already_created = false;
        foreach($data as $instance => $instance_data) {
            if ($instance_data['ema_window_name'] == $window_name) {
                $this->emDebug("instance window: " . $instance_data['ema_window_name'] . ', window name: ' . $window_name);
                $already_created = true;
                break;
            }
        }

        $this->emDebug("already created " . $already_created);
        return $already_created;

    }

    /**
     * This function retrieves instance data based on project type.  The data is stored differently if it is a Classical
     * project, Longitundinal project and whether the data is on a repeating form or a repeating event.
     *
     * @param $data
     * @param $form_event_id
     * @param $form_name
     * @return mixed
     */
    private function findFormData($data, $form_event_id, $form_name) {

        global $Proj;

        // We need to determine if the form with the data is a repeating form or a repeating event
        $repeatingForms = $Proj->RepeatingFormsEvents;
        $repeats = $repeatingForms[$form_event_id];
        if ($repeats == 'WHOLE') {
            $form_data = $data['repeat_instances'][$form_event_id][''];
        } else {
            $form_data = $data['repeat_instances'][$form_event_id][$form_name];
        }

        return $form_data;
    }

    /**
     * This function will take an event name or an event id and returns the event id.
     *
     * @param $all_events
     * @param $event
     * @param $is_longitudinal
     * @return int|string
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
     * This function will retrieve a field value given the data from the event where it is stored.
     *
     * @param $data
     * @param $field
     * @return null
     */
    private function findRecordData($data, $field)
    {
        // Find the value of the requested field
        if (!empty($field)) {
            $value = $data[$field];
        } else {
            $value = null;
        }

        return $value;
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
    private function checkForOptOut($opt_out_field, $data)
    {

        // Check for the opt-out field for this config
        if (!empty($opt_out_field)) {

            // Find the opt-out field value
            $opt_out_value = $data["$opt_out_field"];

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
