# Ecological Momentary Assessment Assistant
A REDCap External Module designed to create and manage surveys using the Ecological Momentary Assessment (EMA) method

A variety of configuration options specify the number and timing of participant notifications for assessment via SMS.   The EM configuration file can be used to create these files or there is a web-based configuration builder
that can be used.

Each project can have one or more configurations to support complex, multi-family member projects.

## How It Works:

Each participant may have many events (called windows) in a study.  For example, baseline, 3 month, and 12 month.  For each event/window, a multi-day configuration can be activated.
The scheduling of each window is 'triggered' by an event that is defined by REDCap logic, such as a date field being completed or a box being checked, etc...

Configuration of an EMA event uses two objects: a `window` which controls if and on what days and a `schedule` which controls the timing of events in a given day.

An EMA window has the following parameters:
- **window-name** (e.g. baseline, month 1, etc...)
- **window-trigger-logic** (when true, window will be created)
- **window-start-field** (the field that holds the date the window begins yyyy-mm-dd) - this is day 0, not day 1.
- **window-start-event** event where the start field resides
- **window-opt-out-field** (if equal to 1/true, scheduled alerts will be cancelled and not sent)
- **window-opt-out-event** event where the opt out field resides
- **window-days** (e.g. number of days for window to run) - this is an array of day offsets, e.g. [1,2,3,4,5,6,7] to permit skipping days in more complex scenarios
- **window-form** repeating form or form in repeating event where the schedule is stored
- **window-form-event** event where the form resides
- **window-schedule-name** name of the schedule configuration that corresponds to this window (see below)
- **schedule-offset-default** default value of number of minutes past midnight this schedule starts (e.g. 480 for 8AM)
- **schedule-offset-override-field** -- name of field that contains a custom start time for the window's schedule, e.g. [wake_time]
- **schedule-offset-override-event** -- event where the offset override field resides
- **text-message** -- wording of message to be sent to participant
- **text-reminder1-message** -- wording of message to be sent as reminder 1 (optional)
- **text-reminder2-message** -- wording of message to be sent as reminder 2 (optional)
- **cell-phone-field** - field in project which holds the cell phone number
- **cell-phone-event** - event where the cell phone field resides

An EMA Schedule configuration controls the specific events within a given day of an active Window.  It is referenced from the Window by the **window-schedule-name** parameter.  There may be more than one window configuration that links to the same schedule.  Each schedule has the following parameters:
- **schedule-name** -- used to link schedule with window
- **schedule-offsets** - comma separated list of minutes past midnight when texts are sent
- **schedule-randomize-window** - if entered, a random number of minutes between 0 and this value will be added to the offset time when the schedule is generated
- **schedule-reminders** - comma separated list of the number of minutes after each text is sent that a reminder will be sent.  Only 2 reminders are currently possible.
- **schedule-close-offset** - number of minutes after the scheduled send time to close the window.  Responses cannot be started after this time has passed for a given assessment.

### Scenario Example: Customizing the first EMA of the day
You can use the `schedule-offset-default` to have the first assessment default to 8am for participants by setting the value to 480 and having the `schedule-offsets` array begin with a `0`.  However, if we have one participant that wakes at 9am and should begin their first EMA two hours later at 11am, we can override the default value by specifying a value in the REDCap field specified for `schedule-offset-override-field` and `schedule-offset-override-event`.  The value here would be 11:00am.

On each save for a record, we check if any windows need to be created.  This is done by verifying the required fields are present for the window, such as start time, a text number to send to, and that the
`window-trigger-logic` evaluates to true.  If all is valid, then the schedule will be created for this window configuration in the current record.  Currently, window creation on bulk import is not supported - a save is required.

The sending time for each EMA survey instance will be determined when the window and schedule are generated and saved into unique instances of instruments/events for the window.  As an example,
if the window has 7 days and there are 4 surveys to be sent per day, then 28 instances of the repeating survey form will be created.

In the repeating form or event that contains the survey, the following data fields must be present for each instance:
- **ema_window_name** - name of window configuration
- **ema_window_day** - This is day number of the window configuration from the `window-days` definition.  If there are 7 days of surveys, this value will be the offset day from the start
- **ema_sequence** - This is the survey number for the day that it is being sent and refers to the `schedule-offsets` parameter in the window schedule.  If there are 4 surveys sent per day, this number will be 1-4 respectively.
- **ema_offset**  - This is the number of minutes from the first survey of the day that this survey will be scheduled.  This field isn't used for any logic but is copied in case needed for data analysis.
- **ema_open** - This is the number of minutes past midnight that the survey is scheduled (i.e. if sequence 1 starts at 0 and the sequence-offset = 480 and random = 67 then this would have a value of 0 + 480 + 67 = 547 minutes)
- **ema_open_ts** - This is a human readable timestamp value of when the survey is schedule in format "yyyy-mm-dd H:i:s"
- **ema_status** - Dropdown of status values:
    <ul>
    <li>1, Schedule Calculated</li>
    <li>2, Notification Sent</li>
    <li>3, Reminder 1 Sent</li>
    <li>4, Reminder 2 Sent</li>
    <li>97, Notification Missed</li>
    <li>98, Window Closed</li>
    <li>99, Survey Access After Closed</li>
    <li>100, Error sending text</li>
    </ul>
- ema_actions        // Not currently set but may be used in the future (s/sent, r1, r2, aoac, x/cancelled, c/closed   missed?)

So, for the activated window, we just created entries for every day and every offset in the schedule (e.g. 7x4=28 instances)
The instance number is somewhat arbitrary.  If all assessments are in the same repeating form, then they could share one
redcap event.  Alternately, you could have repeating forms for multiple events.

## Setup
To use this External Module, the External Module config must be setup. In the configuration file, there is a checkbox option
which determines where the configuration data is stored and how to load it. When the 'Use config file' checkbox is selected, the
Window and Schedule configurations will be retrieved from
the External Module configuration file.  When unchecked, the configurations are loaded by the EMA Config page using the
link on the left-hand sidebar in your project in the External Module section. An example configuration loaded into the EMA
Config webpage is displayed below.

### Example of configurations saved into the EMA Config webpage
```
{
    "windows": [
        {
            "window-name": "Baseline",
            "window-trigger-logic": "[baseline_arm_1][ready_logic(1)] = 1",
            "window-start-field":"w1_start_date",
            "window-start-event":"baseline_arm_1",
            "window-days": [1,2,3,4,5,6,7],
            "window-schedule-name": "4xDay",
            "window-form":"ema_tracker",
            "window-form-event":"baseline_arm_1",
            "window-opt-out-field":"exclude_if",
            "window-opt-out-event":"baseline_arm_1",
            "schedule-offset-default": 480,
            "schedule-offset-override-field":"custom_start_date",
            "schedule-offset-override-event":"baseline_arm_1",
            "text-message":"Please complete this assessment",
            "text-reminder1-message":"This is your first reminder to complete the assessment",
            "text-reminder2-message":"This is your last reminder to complete the assessment",
            "cell-phone-field": "cell_phone",
            "cell-phone-event": "baseline_event_1"
        }
    ],
    "schedules": [
        {
            "schedule-name":"4xDay",
            "schedule-offsets": [0,240,480,720],
            "schedule-randomize-window": 120,
            "schedule-reminders": [5,10],
            "schedule-close-offset": 20
        }
    ]
}
```
This template can be used as a starting point to build configurations in the EMA Config page.

### Processing
Each time a record is saved, each window configuration will be evaluated to see if it is time to
create the window schedule. It is time when the window logic evaluates to true and there is phone
number entered and the opt-out flag is not set.  At this point, the instances are all created.  EMA instances are not
created when records are imported.


### Cron Scheduler
The cron schedule runs every 5 minutes to determine if messages need to be sent.  Because of the processing bandwidth
this cron is designed to be as 'lightweight' as possible.

The scheduler will look through all configurations and check all instances for actions that need to be performed.
Since the send time is the only timestamp that is stored with each instance of the survey, the configurations
will be loaded and the close window time will be calculated to see if any instance should be closed. Since
this close window is calculated at each checkpoint, any changes to the close window setup time will go into effect
immediately.  For instance, suppose participants are given 15 minutes to complete the survey before the survey is closed but you decide to
extend the survey window to 30 minutes. You can make the change to the configuration and the next time
the scheduler runs, it will use the 30 minutes offset before closing the survey.

When survey times pass, a text message will be sent using Twilio. The body of the text can be specified
in the config file and the link to the survey will be appended.

The cron also checks to see if reminders are ready to be sent and if so, they will be sent also.

### Survey Check
When participants click on the survey link they receive in text, the timestamp of the close window
offset will be checked to make sure that the survey is still open for the participant.  If the close
window offset has passed, the participant will receive a message to tell them the survey is closed. The status
of the survey will also be changed to "Survey Access After Closed".
Otherwise, the participant will be able to complete the survey.

Once the survey has been taken, the ema_status field will be changed to 'Closed: Survey Completed'.

### Twilio credentials
Twilio credentials (phone number, sid, token) are required in the EM Configuration file.
