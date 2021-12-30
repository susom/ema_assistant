<?php
namespace Stanford\EMA;

use \REDCap;
use \Project;
use \Records;
use \Exception;
/*
 * For longitudinal projects, the returned data is in the form of:
    [record_id 1]
        [event_id 1]
            [instance a form]       => {form data}
            [instance b form]       => {form data}
            . . .
        [event_id 2]
            [instance a form]       => {form data}
            [instance b form]       => {form data}
            . . .
        [event_id n]
            [instance a form]       => {form data}
            [instance b form]       => {form data}
            . . .
    [record_id 2]
        [event_id 1]
            [instance a form]       => {form data}
        [event_id y]
            [instance a form]       => {form data}


 * For classical projects, the returned data is in the form of:
    [record_id 1]
        [instance 1 form]       => {form data}
        [instance 2 form]       => {form data}
        . . .


The instance identifiers (i.e. a, b) are used to depict the first and second instances.  The number of the instance
may not be uniformly increasing numerically since some instances may have been deleted. For instance, instance 2 may
have been deleted, so the instance numbers would be instance 1 and instance 3.

If using the instance filter, only instances which match the filter criteria will be returned so the instance numbers
will vary.

*/


/**
 * Class RepeatingForms
 * @package Stanford\EMA
 *
 */
class RepeatingForms
{
    // Form Data
    private $Proj;
    private $pid;
    private $is_longitudinal;
    private $instrument;
    private $fields;                    // Array of fields contained in the instrument
    private $event_id;
    private $repeat_context;            // REPEAT_FORM || REPEAT_EVENT

    // Instance Data
    private $data;                      // Array of instance_ids and data
    private $data_loaded = false;
    private $events_loaded = [];        // Array of specific events loaded
    private $filter_loaded;
    private $record_id;

    // Last error message
    public $last_error_message = null;

    const REPEAT_FORM  = 1;
    const REPEAT_EVENT = 2;

    /**
     * This class is instantiated with a scope of a project and a form
     * TODO: Should this only be instantiated with an event id if longitudinal?  I think so!
     * TODO: Also, is it really possible for PID to be from another project outside project scope?  This needs to be
     *       tested -- if not then we should just remove it and require that you be in project scope to use this class.
     * @param string $instrument
     * @param int $event_id
     * @param int $project_id
     * @throws Exception
     */
    function __construct($instrument, $event_id = null, $project_id = null)
    {
        global $Proj;

        // Validate the Project
        if (is_null($project_id)) {
            if (!empty($Proj)) $this->Proj = $Proj;
        } else {
            $this->Proj = ( empty($Proj) ||  $Proj->project_id != $project_id) ? new Project($project_id) :  $Proj;
        }
        if (empty($this->Proj)) {
            throw new Exception("Cannot determine project ID in RepeatingForms");
        }
        $this->pid = $this->Proj->project_id;
        $this->is_longitudinal = $this->Proj->longitudinal;

        // Retrieve valid repeating events for this form
        $repeating_forms_events = $this->Proj->getRepeatingFormsEvents();

        // Validate the EventId
        if (is_null($event_id)) {
            if ($this->is_longitudinal) {
                throw new Exception("You must supply an event_id for longitudinal projects");
            } else {
                $event_id = $this->Proj->firstEventId;
            }
        } else {
            if (!isset($repeating_forms_events[$event_id])) {
                throw new Exception("The supplied event_id $event_id is not repeating in this project");
            }
        }
        $this->event_id = $event_id;

        // Validate the Instrument
        if (!in_array($instrument, $this->Proj->eventsForms[$event_id])) {
            throw new Exception("Instrument $instrument is not enabled in event $event_id");
        }
        $this->instrument = $instrument;

        // Get the valid fields
        $this->fields = array_keys($this->Proj->forms[$instrument]['fields']);

        // Set the repeating context
        if (isset($repeating_forms_events[$event_id][$instrument])) {
            $this->repeat_context = self::REPEAT_FORM;
        } elseif ($repeating_forms_events[$event_id] === "WHOLE") {
            $this->repeat_context = self::REPEAT_EVENT;
        } else {
            throw new Exception("Unable to assign repeat context to $instrument in event $event_id");
        }
    }

    /**
     * This function will load data internally from the database using the record, event and optional
     * filter in the calling arguments here as well as pid and instrument name from the constructor.  The data
     * is saved internally in $this->data.  The calling program must then call one of the get* functions
     * to retrieve the data.
     *
     * @param string        $record_id
     * @param null|string   $filter
     * @param null|array    $existing_record_data
     * @return None
     */
    public function loadData($record_id, $filter=null, $existing_record_data=null)
    {
        $this->record_id = $record_id;

        // Populate the data
        if (empty($existing_record_data)) {
            // We will query the required data
            $params = [
                "project_id"    => $this->pid,
                "records"       => $record_id,
                "fields"        => $this->fields,
                "events"        => $this->event_id,
                "filterLogic"   => $filter
            ];
            $record_data = REDCap::getData($params);
        } else {
            // Assume data was already queried and passed in
            $record_data = $existing_record_data;
        }

        // Filter out just the repeating event instance part of the data structure
        if (!empty($record_data[$record_id]["repeat_instances"][$this->event_id]))
        {
            $rei_parent = $record_data[$record_id]["repeat_instances"][$this->event_id];
            if ($this->repeat_context == self::REPEAT_EVENT) {
                // Repeating Event so instrument name is missing
                $rei_data = $rei_parent[''];
            } else {
                // Repeating Form in an Event
                $rei_data = $rei_parent[$this->instrument];
            }

            // Filter instance data to only use valid fields from the repeating form
            foreach ($rei_data as $i => $data) {
                $rei_data[$i] = array_intersect_key($data, array_flip($this->fields));
            }

            // Save data to object removing event_id for classical projects (is this necessary)
            // Save data object -- take care that data has event id for classical projects which should
            // be removed on save
            $this->data[$record_id][$this->event_id] = $rei_data;
        }

        $this->data_loaded = true;
    }


    /**
     * This function will return the data retrieved based on a previous loadData call. All instances of an
     * instrument fitting the criteria specified in loadData will be returned. See the file header for the
     * returned data format.
     *
     * @param $record_id
     * @return array (of data loaded from loadData) or false if an error occurred
     */
    public function getAllInstances($record_id) {
        $this->verifyRecordDataLoaded($record_id);

        return $this->data[$record_id][$this->event_id];
    }


    /**
     * This function will return one instance of data retrieved in dataLoad using the $instance_id.
     *
     * @param $record_id
     * @param $instance_id
     * @return false | array (of instance data) or false if an error occurs
     */
    public function getInstanceById($record_id, $instance_id)
    {
        $this->verifyRecordDataLoaded($record_id);
        $data = $this->data[$record_id][$this->event_id];

        if (!isset($data[$instance_id])) {
            // There is no instance data
            $this->last_error_message = "Instance number invalid";
            return false;
        } else {
            return $data[$instance_id];
        }
    }


    /**
     * This function will return the first instance_id for this record and optionally event. This function
     * does not return data. If the instance data is desired, call getInstanceById using the returned instance id.
     *
     * @param $record_id
     * @return int (instance number) or false (if an error occurs)
     */
     public function getFirstInstanceId($record_id) {
         $this->verifyRecordDataLoaded($record_id);
         $data = $this->data[$record_id][$this->event_id];
         if (empty($data)) {
             // There is no instance data
             return false;
         } else {
             return min(array_keys($data));
         }
    }


    /**
     * This function will return the last instance_id for this record and optionally event. This function
     * does not return data. To retrieve data, call getInstanceById using the returned $instance_id.
     *
     * @param $record_id
     * @param null $event_id
     * @return int | false (If an error occurs)
     */
    public function getLastInstanceId($record_id) {
        $this->verifyRecordDataLoaded($record_id);

        $data = $this->data[$record_id][$this->event_id];
        if (empty($data)) {
            // There is no instance data
            return false;
        } else {
            return max(array_keys($data));
        }
    }


    /**
     * This function will return the next instance_id in the sequence that does not currently exist.
     * If there are no current instances, it will return 1.
     *
     * @param $record_id
     * @return int
     */
    public function getNextInstanceId($record_id)
    {
        $this->verifyRecordDataLoaded($record_id);

        // Find the last instance and add 1 to it. If there are no current instances, return 1.
        $last_index = $this->getLastInstanceId($record_id);
        if ($last_index === false) {
            return 1;
        } else {
            return $last_index + 1;
        }
    }


    /**
     * This function will return an array of instance_ids for this record/event.
     *
     * @param $record_id
     * @return array of instance IDs
     */
    public function getAllInstanceIds($record_id)
    {
        $this->verifyRecordDataLoaded($record_id);

        $data = $this->data[$record_id][$this->event_id];
        // All instance IDs
        return array_keys($data);
    }


    /**
     * This function will save an instance of data.  If the instance_id is supplied, it will overwrite
     * the current data for that instance with the supplied data.
     *
     * @param $record_id
     * @param $instance_id
     * @param $data
     * @return true | false (if an error occurs)
     */
    public function saveInstance($record_id, $instance_id, $data)
    {
        $new_instance[$record_id]['repeat_instances'][$this->event_id][$this->getRepeatContextKey()][$instance_id] = $data;
        $return = REDCap::saveData($this->pid, 'array', $new_instance);

        if (!empty($return["errors"]) and ($return["item_count"] <= 0)) {
            $this->last_error_message = "Problem saving instance $instance_id for record $record_id in project $this->pid. Returned: " . json_encode($return);
            return false;
        } else {
            return true;
        }
    }


    /**
     * Save multiple instances at once
     *
     * @param $record_id
     * @param array $multi_instance_data  An array of instances where the key is the instance ID
     * @return bool
     * @throws Exception
     */
    public function saveAllInstances($record_id, $multi_instance_data)
    {
        // Include instance and format into REDCap expected format
        $new_instance[$record_id]['repeat_instances'][$this->event_id][$this->getRepeatContextKey()] = $multi_instance_data;

        $return = REDCap::saveData($this->pid, 'array', $new_instance);
        if (!isset($return["errors"]) and ($return["item_count"] <= 0)) {
            $this->last_error_message = "Problem saving instances for record $record_id in project $this->pid. Returned: " . json_encode($return);
            return false;
        } else {
            return true;
        }
    }


    /**
     * This function will delete the specified instance of a repeating form or repeating event.
     * It does not require that any data have been loaded previously
     *
     * @param $record_id
     * @param $instance_id
     * @return int $log_id - log entry number for this delete action
     */
    public function deleteInstance($record_id, $instance_id) {
        // TODO: Test that it works to supply the event_id in a classical project here
        $log_id = Records::deleteForm($this->pid, $record_id, $this->instrument, $this->event_id, $instance_id);

        return $log_id;
    }


    /**
     * TODO: NOT SURE HOW THIS IS USED - ALSO SHOULD IT RETURN ALL MATCHES OR JUST THE FIRST?
     * This function will look for the data supplied in the given record/event and send back the instance
     * number if found.  The data supplied does not need to be all the data in the instance, just the data that
     * you want to search on.
     *
     * @param $needle
     * @param $record_id
     * @return int | false (if an error occurs)
     */
    public function exists($needle, $record_id) {
        $this->verifyRecordDataLoaded($record_id);

        // Look for the supplied data in an already created instance
        $found_instance_id = null;
        $size_of_needle = sizeof($needle);

        // Modify search area depending on longitudinal or not -- TODO:Change this to store classical with event_id
        $search_area = $this->data[$record_id][$this->event_id];

        foreach ($search_area as $instance_id => $instance) {
            $intersected_fields = array_intersect_assoc($instance, $needle);
            if (sizeof($intersected_fields) == $size_of_needle) {
                return $instance_id;
            }
        }

        // Supplied data did not match any instance data
        $this->last_error_message = "Instance was not found with the supplied data " . __FUNCTION__;
        return false;
    }


    /**
     * This should be called to verify the record_id in an incoming function call matches the current context of the
     * object
     * @param $record_id
     * @return void
     */
    private function verifyRecordDataLoaded($record_id) {
        // Check to see if we have the correct data loaded.
        if ($this->data_loaded == false || $this->record_id != $record_id) {
            $this->loadData($record_id, null, null);
        }
    }


    /**
     * Returns the correct key to use when building the array of data depending on the type of repeating form/event
     * @return string|null
     * @throws Exception
     */
    private function getRepeatContextKey() {
        if ($this->repeat_context == self::REPEAT_FORM) {
            return $this->instrument;
        } elseif ($this->repeat_context = self::REPEAT_EVENT) {
            return null;
        } else {
            throw new Exception("Invalid repeat context for $this->instrument in event $this->event_id");
        }
    }
}
