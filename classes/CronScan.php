<?php
namespace Stanford\EMA;

use REDCap;
use Exception;

// require_once "classes/RepeatingForms.php";

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

    private $all_events;    // A cache of project events

    public $windows;
    public $schedules;

    private $loaded_record_id;
    private $record_data;


    public function __construct($module) {
        $this->module = $module;

        // Retrieve all window and schedule configs
        [$this->windows, $this->schedules] = $this->module->getConfigAsArrays();
    }

    /**
     * @throws Exception
     */
    public function scanWindows() {

        foreach ($this->windows as $window) {
            $window_name = $window['window-name'];
            $this->module->emDebug("Processing window $window_name"); // . json_encode($window));

            // Find the schedule configuration for this window configuration
            $schedule_name = $window['window-schedule-name'];
            $schedule = $this->module->findScheduleForThisWindow($schedule_name, $this->schedules);
            if (empty($schedule)) {
                throw new Exception ("Unable to load schedule $schedule_name for window $window_name");
            }
            //$this->module->emDebug("Looking over window $window_name and schedule $schedule_name");

            // Goal here is to find all records for this window that need to be processed
            // A record that should be processed is one where the ema_status is less than 90:
            // and the ema_open_ts is in the past....
            $window_form = $window['window-form'];
            $window_form_event_id = $this->getEventId($window['window-form-event']);
            $window_text_message = $window['text-message'];
            $window_reminder_1   = $window['text-reminder1-message'];
            $window_reminder_2   = $window['text-reminder2-message'];


            $instances = $this->getActiveInstances($window_name, $window_form_event_id);
            $this->module->emDebug("Found " . count($instances) . " EMA instances for window $window_name");
            if (empty($instances)) continue;

            $window_opt_out_field = $window['window-opt-out-field'];
            $window_opt_out_event_id = $this->getEventId($window['window-opt-out-event']);

            $cell_phone_field = $window['cell-phone-field'];
            $cell_phone_event_id = $this->getEventId($window['cell-phone-event']);

            // These are the events where we may need to pull data values from, so lets only load those events
            $data_events = [$window_opt_out_event_id, $window_form_event_id, $cell_phone_event_id];

            // Instantiate a repeatingForm helper
            $RF = new RepeatingForms($window_form, $window_form_event_id);

            // Loop through each record/instance and process it
            foreach ($instances as $i) {
                $record_id    = $i['record'];
                $instance_id  = $i['instance'];
                $age_in_min   = $i['age_in_min'];

                $ts_start = microtime(true);
                // Load current record data once and cache it to reduce db queries
                $this->loadRecordData($record_id, $data_events);

                // Populate the RepeatingForm helper from the cached data
                if ($RF->getRecordId() !== $record_id) {
                    $RF->loadData($record_id,'',$this->record_data);
                }
                $ts_duration = microtime(true) - $ts_start;
                $this->module->emDebug("$record_id-   Data loaded in $ts_duration sec");

                // Determine if this record has opted out for this window
                $opt_out = $this->getDataValue($record_id, $window_opt_out_event_id, $window_opt_out_field) == 1;
                if ($opt_out) {
                    $this->module->emDebug("Record $record_id, Event $window_opt_out_event_id, Field $window_opt_out_field for window $window_name is opted out");
                }

                // Look at the instance to see if it needs updating
                $ts_start = microtime(true);
                $instance_data = $RF->getInstanceById($record_id, $instance_id);
                $ts_duration = microtime(true) - $ts_start;
                $ema_status = $instance_data['ema_status'];
                $this->module->emDebug("$record_id-$instance_id Loaded instance with status $ema_status in $ts_duration sec");

                // Clear loop
                $new_status = null;
                $outbound_sms = null;

                // Check if expired
                if ($age_in_min >= $schedule['schedule-close-offset']) {
                    // This invitation has expired.  Depending on the status we can set the expiration type
                    switch($ema_status) {
                        case EMA::STATUS_SCHEDULED:
                            $new_status = EMA::STATUS_INSTANCE_SKIPPED;
                            break;
                        case EMA::STATUS_OPEN_SMS_SENT:
                        case EMA::STATUS_REMINIDER_1_SENT:
                        case EMA::STATUS_REMINIDER_2_SENT:
                        case EMA::STATUS_SEND_ERROR:
                            $new_status = EMA::STATUS_WINDOW_CLOSED;
                            break;
                        default:
                            $this->module->emError("Unexpected EMA expired status of $ema_status found", $i, $instance_data);
                    }
                } else {
                    // Invitation is still valid.  Check for further actions:
                    if ($opt_out) {
                        $new_status = EMA::STATUS_OPTED_OUT;
                        $this->module->emDebug("$record_id-$instance_id Opted Out - setting status $new_status");
                    } else {
                        switch ($ema_status) {
                            case EMA::STATUS_SCHEDULED:
                                // Send invite
                                $outbound_sms = $window_text_message;
                                $new_status = EMA::STATUS_OPEN_SMS_SENT;
                                break;
                            case EMA::STATUS_OPEN_SMS_SENT:
                                // Check if ready for reminder 1
                                if (!empty($schedule['schedule-reminders'][0]) && $age_in_min >= $schedule['schedule-reminders'][0]) {
                                    $outbound_sms = $window_reminder_1;
                                    $new_status = EMA::STATUS_REMINIDER_1_SENT;
                                }
                                break;
                            case EMA::STATUS_REMINIDER_1_SENT:
                                // Check if ready for reminder 2
                                if (!empty($schedule['schedule-reminders'][1]) && $age_in_min >= $schedule['schedule-reminders'][1]) {
                                    $outbound_sms = $window_reminder_2;
                                    $new_status = EMA::STATUS_REMINIDER_2_SENT;
                                }
                                break;
                            case EMA::STATUS_REMINIDER_2_SENT:
                                // Do Nothing...
                                break;
                            default:
                                $this->module->emError("Unexpected EMA status of $ema_status found", $i, $instance_data);
                        }
                    }
                }

                // See if we are supposed to send a text
                if (!empty($outbound_sms)) {
                    // Append Survey URL
                    $survey_link = REDCap::getSurveyLink($record_id, $window_form, $window_form_event_id, $instance_id);
                    // $outbound_sms .= " ($record_id-$instance_id)";  // DEBUG
                    $outbound_sms .= " $survey_link";

                    // Get the To Number
                    $to_number = $this->getDataValue($record_id, $cell_phone_event_id, $cell_phone_field);
                    if (empty($to_number)) {
                        $new_status = EMA::STATUS_SEND_ERROR;
                        $instance_data = $this->appendEmaLog($instance_data, "Missing cell phone number in $cell_phone_field");
                    } else {
                        $ts_start = microtime(true);
                        $result = $this->module->sendTwilioMessage($to_number, $outbound_sms);
                        $ts_duration = microtime(true) - $ts_start;
                        if ($result === false) {
                            $new_status = EMA::STATUS_SEND_ERROR;
                            $instance_data = $this->appendEmaLog($instance_data, "Error sending sms message");
                            $this->module->emDebug("$record_id-$instance_id SMS failure in $ts_duration sec: $outbound_sms");
                        }
                        $this->module->emDebug("$record_id-$instance_id SMS sent (" . json_encode($result) . ") in $ts_duration sec: $outbound_sms");
                        \REDCap::logEvent("EMA SMS Sent", $outbound_sms,"", $record_id);
                    }
                }

                if (!empty($new_status)) {
                    $instance_data['ema_status'] = $new_status;

                    // If it is a 'final status' then mark the form as complete
                    $complete_field = $window_form . "_complete";
                    if ($new_status >= 90 && $instance_data[$complete_field] == 0) $instance_data[$window_form . "_complete"] = 2;

                    // Save Instance
                    $ts_start = microtime(true);
                    $result = $RF->saveInstance($record_id, $instance_id, $instance_data);
                    $ts_duration = microtime(true) - $ts_start;
                    $this->module->emDebug("$record_id-$instance_id updated to $new_status in $ts_duration sec (" . json_encode($result) . ")");
                }
            }
        }
    }


    /**
     * Append a log entry to the ema_log field and return the instance_data again
     * @param array $instance_data
     * @param string $msg
     * @param bool $prefix_with_date
     * @return array
     */
    private function appendEmaLog($instance_data, $msg, $prefix_with_date = true) {
        $suffix = ( empty($instance_data['ema_log']) ? '' : "\n" . $instance_data['ema_log']);
        $prefix = $prefix_with_date ? "[" . date("Y-m-d H:i:s ") . "] " : '';
        $instance_data['ema_log'] = $prefix . $msg . $suffix;
        return $instance_data;
    }


    /**
     * Pull any NON-REPEATING value from the REDCap record that has been cached
     * Used to get overrides, etc...
     * @param $record_id
     * @param $event_id
     * @param $field_name
     * @return mixed|null
     */
    private function getDataValue($record_id, $event_id, $field_name) {
        // Make sure data is loaded - if not, load it all
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
     * Applies a cache so as to only load one time
     * @param $record_id
     * @param array $event_ids
     * @return void
     */
    private function loadRecordData($record_id, $event_ids = []) {
        if ($this->loaded_record_id !== $record_id) {
            $params = [];
            $params['records'] = $record_id;
            if (!empty($event_ids)) $params['events'] = $event_ids;
            $this->record_data = REDCap::getData($params);
            $this->loaded_record_id = $record_id;
        }
    }


    /**
     * Obtain all records / instances of EMA for specified window / event that should be further processed
     * @param $window_name
     * @param $event_id
     * @return array
     */
    private function getActiveInstances($window_name, $event_id) {
        // Query the database to obtain all instances that require processing
        $q = $this->module->query(
            "select
                r1.record,
                ifnull(r1.instance,1) as instance,
                r1.value as ema_open_ts,
                r2.value as ema_status,
                TIMESTAMPDIFF(MINUTE, str_to_date(r1.value, '%Y-%m-%d %H:%i:%s'), current_timestamp) as age_in_min
            from
                redcap_data r1
                join redcap_data r2 on r1.project_id = r2.project_id and r1.record = r2.record
                    and r1.event_id = r2.event_id and r1.instance <=> r2.instance
                join redcap_data r3 on r1.project_id = r3.project_id and r1.record = r3.record
                    and r1.event_id = r3.event_id and r1.instance <=> r3.instance
                    and r3.value = ?
            where
                r1.project_id = ?
            and r2.event_id = ?
            and r1.field_name = 'ema_open_ts'
            and current_timestamp >= str_to_date(r1.value, '%Y-%m-%d %H:%i:%s')

            and r2.field_name = 'ema_status'
            and cast(r2.value as unsigned) < 90

            and r3.field_name = 'ema_window_name'
            order by 1,2", [ $window_name, $this->module->getProjectId(), $event_id ]
        );

        $results = [];
        while ($row = db_fetch_assoc($q)) $results[] = $row;
        return $results;
    }


    /**
     * This function will accept a event name or event id and return the event_id
     * @param $ambiguous_event
     * @return int
     * @throws Exception
     */
    private function getEventId($ambiguous_event) {
        // Load event_id to event_name map if not already done
        if (empty($this->all_events)) $this->all_events = REDCap::getEventNames(true, true);

        if (isset($this->all_events[$ambiguous_event])) {
            // Matches an event_id
            return intval($ambiguous_event);
        } elseif(false !== $key = array_search($ambiguous_event, $this->all_events)) {
            // Matches an event_name (e.g. baseline_arm_1)
            return intval($key);
        } else {
            // unable to match
            throw new Exception ("Unable to find valid event_id from $ambiguous_event");
        }
    }



}
