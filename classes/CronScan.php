<?php
namespace Stanford\EMA;

use REDCap;
use Exception;

require_once "classes/RepeatingForms.php";
require_once APP_PATH_DOCROOT . "/Libraries/Twilio/Services/Twilio.php";

/**
 * CronScan
 *
 * A Class and functions to facilitate a cron-job scan of potential outgoing notifications
 * It is assumed that this class is being executed in the context of the project_id
 * where the EMA is configured and being run
 *
 * @property EMA $module
 */
class CronScan
{
    private $module;

    private $all_events;

    public $windows;
    public $schedules;

    private $loaded_record_id;
    private $record_data;


    private $window;    // Current Window

    public function __construct($module) {
        $this->module = $module;

        // Retrieve all window and schedule configs
        [$this->windows, $this->schedules] = $this->module->getConfigAsArrays();

        // Load events
        $this->all_events = REDCap::getEventNames(true, true);
    }

    /**
     * @throws Exception
     */
    public function scanWindows() {

        foreach ($this->windows as $window) {
            $window_name = $window['window-name'];

            // Find the schedule configuration for this window configuration
            $schedule_name = $window['window-schedule-name'];
            $schedule = $this->module->findScheduleForThisWindow($schedule_name, $this->schedules);
            if (empty($schedule)) {
                throw new Exception ("Unable to load schedule $schedule_name for window $window_name");
            }
            $this->module->emDebug("Looking over window $window_name and schedule $schedule_name");

            // Goal here is to find all records for this window that need to be processed
            // A record that should be processed is one where the ema_status is less than 90:
            // and the ema_open_ts is in the past....
            $window_form = $window['window-form'];
            [$window_form_event_name, $window_form_event_id] = $this->getEventNameAndId($window['window-form-event']);
            $instances = $this->getActiveInstances($window_form_event_id);
            if (empty($instances)) continue;

            $window_opt_out_field = $window['window-opt-out-field'];
            [$window_opt_out_event_name, $window_opt_out_event_id] = $this->getEventNameAndId($window['window-opt-out-event']);

            // Instantiate a repeatingForm helper
            $RF = new RepeatingForms($window_form, $window_form_event_id);

            // Loop through each record/instance and process it
            foreach ($instances as $i) {
                $record_id    = $i['record_id'];
                $instance_id  = $i['instance'];
                $age_in_min   = $i['age_in_min'];


                // Load current record data once and cache it to reduce db queries
                $this->loadRecordData($record_id);

                // Determine if this record has opted out for this window
                $opt_out = $this->getDataValue($record_id, $window_opt_out_event_id, $window_opt_out_field) == 1;

                if ($opt_out) {
                    $this->module->emDebug("Record $record_id, Event $window_opt_out_event_id, Field $window_opt_out_field for window $window_name is opted out");
                }

                // TODO: Decide what to do for instance_id = null which is what the first instance id is for some stupid reason.
                // if (empty($instance_id)) {
                //     $this->module->emError("Found an empty instance id for record $record_id - this shouldn't happen!");
                //     continue;
                // }

                if ($instance = $RF->getInstanceById($record_id,$instance_id, $window_form_event_id)) {

                }


            }
        }
    }


    private function getDataValue($record_id, $event_id, $field_name) {
        $this->loadRecordData($record_id);
        if (isset($this->record_data[$record_id][$event_id][$field_name])) {
            return $this->record_data[$record_id][$event_id][$field_name];
        } else {
            $this->module->emDebug("Unable to find record $record_id, event $event_id, field_name $field_name in cached record data");
            return null;
        }
    }

    /**
     * Load ALL record data from the specified events (optional)
     * @param $record_id
     * @param $event_ids
     * @return void
     */
    private function loadRecordData($record_id, $event_ids = []) {
        if ($this->loaded_record_id !== $record_id) {
            $params = [];
            $params['records'] = $record_id;
            if (!empty($event_ids)) $params['events'] = $event_ids;
            $this->record_data = REDCap::getData($params);
        }
    }

    /**
     * Obtain all records / instances of EMA for specified event that should be further processed
     * @param $event_id
     * @return array
     */
    private function getActiveInstances($event_id) {
        // Query the database to obtain all instances that require processing
        $q = $this->module->query(
            "select
                r1.record as record_id,
                r1.event_id,
                r1.instance,
                r1.value as ema_open_ts,
                r2.value as ema_status
            from
                redcap_data r1
                join redcap_data r2 on r1.project_id = r2.project_id and r1.record = r2.record
                                   and r1.event_id = r2.event_id and r1.instance <=> r2.instance
            where
                r1.project_id = ?
            and r1.event_id = ?
            and r1.field_name = 'ema_open_ts'
            and current_timestamp >= str_to_date(r1.value, '%Y-%m-%d %H:%i:%s')
            and r2.field_name = 'ema_status'
            and cast(r2.value as unsigned) < 90", [ $this->module->getProjectId(), $event_id ]
        );
        $results = [];
        while ($row = db_fetch_assoc($q)) $results[] = $row;
        return $results;
    }


    /** This function will accept an event name or event id and return both name and event.
     *
     * @param $event
     * @return array
     */
    private function getEventNameAndId($event) {
        global $Proj;

        // This is a longitudinal project
        $event_ids = array_keys($this->all_events);

        if (!in_array($event, $event_ids)) {
            // Incoming event is an event name
            $event_name = $event;
            $names_to_ids = array_flip($this->all_events);
            $event_id = $names_to_ids[$event];
        } else {
            // Incoming event is an event id
            $event_id = $event;
            $event_name = $this->all_events[$event];
        }
        return [$event_name, $event_id];
    }





}
