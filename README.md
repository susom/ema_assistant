# Ecological Momentary Assessment Assistant
A REDCap EM designed to create and manage surveys using the EMA method

The EMA method uses configurations to specify how often and when surveys should be sent.
The EM configuration file can be used to create these files or there is a web-based configuration builder
that can be used.

Each project can have one or more configurations.

## How It Works:

Each participant may have many events in a study.  For each event, a multi-day EMA window is opened.
Each window is 'triggered' by some event that can be defined by REDCap logic, such as a date field being completed, etc...

Within a given EMA window you have the following properties:
- **window-name** (e.g. baseline, month 1, etc...)
- **window-trigger-logic** (when true, window will be created)
- **window-start-field** (the field that holds the date the window begins yyyy-mm-dd)
- **window-start-event** event where the start field resides
- **window-opt-out-field** (if true, scheduled alerts will be cancelled and not sent)
- **window-opt-out-event** event where the opt out field resides
- **window-days** (e.g. number of days for window to run) - this is an array of day offsets, e.g. [1,2,3,4,5,6,7] to
  permit skipping days in more complex scenarios
- **window-form** repeating form or form in repeating event where the schedule is stored
- **window-form-event** event where the form resides
- **window-schedule-name** name of the schedule configuration that corresponds to this window
- **schedule-offset-default** default value of number of minutes past midnight this schedule starts
- **schedule-offset-override-field** -- name of field that contains a custom start time for the window's schedule, e.g. [wake_time]
- **schedule-offset-override-event** -- event where the offset override field resides
- **text-message** -- wording of message to be sent to participant
- **text-reminder1-message** -- wording of message to be sent as reminder 1 (optional)
- **text-reminder2-message** -- wording of message to be sent as reminder 2 (optional)


There is an EMA schedule which specifies the schedule for each window (linked by **window-schedule-name**):
Each schedule has the following properies:
- **schedule-name** -- used to link schedule with window
- **schedule-offsets** - comma separated list of minutes past midnight when texts are sent
- **schedule-randomize-window** - if entered, a random number between 0 and this value will be generate to add to offset time
- **schedule-reminders** - comma separated list of the number of minutes after each text is sent that a reminder will be sent.  Only 2 reminders are currently possible.
- **schedule-close-offset** - number of minutes after the send time to close the window.
- **schedule-length** - what is this used for???



- schedules:
{
    "schedule-name": "4x_day",
    "schedule-default_start": 480,      // start at 8am unless otherwise specified
    "schedule-offsets": [0, 240, 480, 720 ], // number of minutes from base_time for scheduled EMA events
    "schedule-length? offset-random": "120",           // window length for each EMA events
    "schedule-reminders": [ 5, 10 ]     // minutes from start time when reminders should be sent
    "schedule-duration": "20"       // number of minutes after start time when window is open -- after it will be closed
    "schedule-randomize-window": true   // whether to randomize the 'start_time' inside the length of the window
    "instrument": "..."                 // name of the REDCap instrument where EMA will initiate

}

### Scenario Example
Person A wakes at 6am and the goal is to start the first survey of the day at 8am.  But, if the person wakes at 7am, we may
want the survey to start at 9am. So, during the baseline survey, we ask them when do you typically wake and store the
result in a variable. Then, the first survey of the day can be adjusted to the person's schedule.

In this scenario, the first Baseline window would default the start time at 8am but each window after
Baseline would start the person's customized start window.

Once we have the starting time for surveys set and we have a text number to send the surveys to and
the logic to calculate schedules is true, then the schedules will be created for this window.

The send time for each survey instance will be determined and saved for each instance in the window. As an example,
if the window has 7 days and there are 4 surveys to be sent per day, then 28 instances of the survey
will be created.

The data stored for each instance, is:
- **ema_window_name** - name of window configuration
- **ema_window_day** - This is day number of the window configuration.  If there are 7 days of surveys, this value will be the offset day from the start
- **ema_sequence** - This is the survey number for the day that it is being sent.  If there are 4 surveys sent per day, this number will be 1-4.
- **ema_offset**  - This is the number of minutes from the first survey of the day that this survey will be scheduled
- **ema_open** - This is the number of minutes past midnight that the survey is scheduled (i.e. if sequence 1 starts at 0 and the sequence-offset = 480 and random = 67 -> 0 + 480 + 67 = 547 as time in minutes)
- **ema_open_ts** - This is the readable timestamp value of when the survey is schedule in format "yyyy-mm-dd H:i:s"
- **ema_status** - Dropdown of status values:
    <ul>
    <li>1, Schedule Calculated</li>
    <li>2, Notification Sent</li>
    <li>3, Reminder 1 Sent</li>
    <li>4, Reminder 2 Sent</li>
    <li>97, Notification Missed</li>
    <li>98, Window Closed</li>
    <li>99, Survey Access After Closed</li>
    </ul>
- ema_actions        // Checkboxes for each state?  (s/sent, r1, r2, aoac, x/cancelled, c/closed   missed?)

So, for the activated window, we just created entries for every day and every offset in the schedule (e.g. 7x4=28 instances)
The instance number is somewhat arbitrary.  If all assessments are in the same repeating form, then they could share one
redcap event.  Alternately, you could have repeating forms for each event.

# Cron Scheduler
The cron schedule runs every 5 minutes to determine if messages need to be sent out.

The scheduler will look through all configurations and check all instances for actions that need to be performed.
Since the send time is the only timestamp that stored with each instance of the survey, the configurations
will be loaded and and the close window time will be calculated to see if any instance should be closed. Since
this close window is calculated at each checkpoint, any changes to the close window will go to into effect
immediately.  For instance, suppose participants are given 15 minutes to complete the survey before the survey is closed but you decide to
extend the survey window to 30 minutes, you can make the change to the configuration.  The next time
the scheduler runs, it will wait 30 minutes before closing the survey.



In REDCap project we will have multiple events with repeating instances.  We need to determine what action needs to be done.
- We load all windows into memory
- for each window we get the event where the repeating instruments reside.
- from the schedule we get the instrument name
e.g.
Window: Baseline => [ baseline_arm_1 ], 4x_day => [ ema_survey ],
Window: Month 1 =>  [ month_1 ]       , 4x_day => [ ema_survey ]

We then need to query the ema_survey instruments in those events to determine if we need to send...
- get all records where ema_open_ts <= now AND window is not closed...
- we then loop through all those record-instances, sorted by record.

We need to load the contact information for each record into memory along with any variables required to do cancel logic
for the window...

For each record-instance, we
 - use window name to check window kill logic against record - if kill is true, we mark invitation as cancelled and closed.
   - returns record and instances
 - if delta is greater than window, then mark as window closed.
 - if invitation is not sent and it is less than the close time for the EMA?, we send and mark as sent.
 - if delta between ema_open_ts and now is <= reminders, then mark and send reminders

On record-save event, we need to evaluate if logic is true to trigger creation of EMA surveys for windows.
- Do we need to be able to delete/clear all entries for a window (what if something changes?)

Need to use tool for dealing with instances of forms

SMS invitations...
