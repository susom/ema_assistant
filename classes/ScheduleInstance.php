<?php
namespace Stanford\EMA;

use REDCap;
use Exception;

// require_once "classes/RepeatingForms.php";

/**
 * ScheduleInstance
 *
 * @property EMA $module
 */
class ScheduleInstance
{
    private $module;

    // Window parameters
    private $window_name, $start_date, $form, $form_event_id;
    private $window_days = array();

    // Record parameters
    private $pid, $record_id;

    // Schedule parameters
    private $randomize, $start_time;
    private $sched_offsets = array();

    // Text messages
    private $text_msgs = array();

    public const MINUTES_IN_DAY = 1440;

    public function __construct($module) {
        $this->module = $module;
    }

    /**
     * Passes configuration data from the window and schedule configs so the schedules can be created.
     *
     * @param $record_id
     * @param $window_config
     * @param $start_date
     * @param $start_time
     * @param $form_event_id
     * @param $schedule_config
     */
    public function setUpSchedule($record_id, $window_config, $start_date, $start_time,
                                    $form_event_id, $schedule_config)
    {
        // These are window parameters
        $this->window_name              = $window_config['window-name'];
        $this->start_date               = $start_date;
        $this->start_time               = $start_time;
        $this->form                     = $window_config['window-form'];
        $this->form_event_id            = $form_event_id;
        $this->window_days              = $window_config['window-days'];

        // This is the record we are calculating the window for
        $this->record_id                = $record_id;
        $this->pid                      = $this->module->getProjectId();

        // These are the text messages that will be sent
        $this->text_msgs[EMA::STATUS_OPEN_SMS_SENT] = $window_config['text-message'];
        $this->text_msgs[EMA::STATUS_REMINIDER_1_SENT] = $window_config['text-reminder1-message'];
        $this->text_msgs[EMA::STATUS_REMINIDER_2_SENT] = $window_config['text-reminder2-message'];

        // This is the schedule configuration to determine days/times of surveys
        $this->sched_offsets            = $schedule_config['schedule-offsets'];
        $this->randomize                = $schedule_config['schedule-randomize-window'];
    }

    /**
     * This function loops over each window day
     */
    public function createWindowSchedule()
    {

        // Loop over each window day and create entries for each schedule instance
        foreach ($this->window_days as $wind_day_num) {

            $new_date = $this->addDaysToDate($this->start_date, $wind_day_num);
            $this->calculateSchedule($wind_day_num, $new_date);
        }

    }

    /**
     * This function creates each instance of the schedule for the day
     *
     * @param $window_num
     * @param $start_date
     * @throws Exception
     */
    private function calculateSchedule($window_num, $start_date) {

        // Instantiate the repeating form helper class
        try {
            $rf = new RepeatingForms($this->form, $this->form_event_id);
            $next_instance_id = $rf->getNextInstanceId($this->record_id);
        } catch (Exception $ex) {
            $this->module->emError("Exception when instantiating RepeatingForms class: " . $ex->getMessage());
            return;
        }


        // Loop over each offset and create an instance for each offset
        $ncounter = 1;
        foreach($this->sched_offsets as $offset) {

            // If randomization is being used, create a randomized offset to add to the base
            $rand_time = (empty($this->randomize) ? 0 : $this->generateRandomOffset(0, $this->randomize));

            // Save this data on each survey.  These fields will be hidden so participants will not see them.
            $saveSched = array();
            $saveSched['ema_window_name']   = $this->window_name;
            $saveSched['ema_window_day']    = $window_num;
            $saveSched['ema_sequence']      = $ncounter++;
            $saveSched['ema_offset']        = $offset;
            $saveSched['ema_open']          = $this->start_time + $rand_time + $offset;
            $saveSched['ema_open_ts']       = $this->addMinutesToDate($start_date, $saveSched['ema_open']);
            $saveSched['ema_status']        = EMA::STATUS_SCHEDULED;

            // Save this info on the instrument specified
            $instance_id = $next_instance_id++;
            $status = $rf->saveInstance($this->record_id, $instance_id,  $saveSched);
            if (!$status) {
                $message = $rf->last_error_message;
                $this->module->emError("Error when saving data for window $this->window_name, record $this->record_id with message: " . $message);
            }
        }

    }


    /**
     * Generate a random offset in minutes between min and max using a resolution specified
     *
     * @param $min
     * @param $max
     * @param $resolution   // Minimum resultion in minutes for the offset
     * @return int
     * @throws \Exception
     */
    private function generateRandomOffset($min, $max, $resolution = 1): int
    {
        // If max is 120 and resolution is 5, then we want a number between 0 and 24.
        $randMax = floor($max / $resolution);
        $randMin = $min;
        $randInt = random_int($randMin, $randMax);

        return $randInt * $resolution;
    }

    /**
     * This function is an utility which will add minutes to a date and returns a timestamp.
     *
     * @param $date
     * @param $minutes
     * @return string
     */
    private function addMinutesToDate($date, $minutes) {

        $datetime_in_sec = strtotime($date);
        $new_datetime = $datetime_in_sec + $minutes*60;
        return  date("Y-m-d H:i:s", $new_datetime);
    }

    /**
     * This function adds the number of days to a date and returns the new date.
     *
     * @param $date
     * @param $num_days
     * @return string
     */
    private function addDaysToDate($date, $num_days) {

        $datetime_in_sec = strtotime($date);
        $new_date = $datetime_in_sec + $num_days*24*60*60;
        return date("Y-m-d", $new_date);
    }

}
