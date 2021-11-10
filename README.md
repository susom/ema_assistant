# Ecological Momentary Assessment Assistant
A REDCap EM designed to create and manage surveys using the EMA method



Plan:

Each participant has many events in the study.  For each event, a seven day EMA windows is opened.
A window is 'triggered' by some event that can be defined by REDCap logic, such as a date field being completed, etc...

Within a given EMA window you have the following properties:
- window name (e.g. baseline, month 1, etc...)
- window start date (the day the window begins)
- window trigger logic (when true, window will be created)
- window kill logic (if true, scheduled alerts will be cancelled and not sent)
- window days (e.g. number of days for window to run) - this is an array of day offsets, e.g. [1,2,3,4,5,6,7] to
  permit skipping days in more complex scenarios
- schedule to run for days in window
- window event where repeating instruments should reside, e.g. 'baseline_arm_1'
- custom_start_time_field -- name of field that contains a custom start time for the window's schedule, e.g. [wake_time]
- custom_start_time_event -- default to window event, but can be overridden - e.g. to use baseline for all windows.

Each EMA window applies a schedule:

schedules:
{
    "name": "4x_day",
    "default_start": 480,               // start at 8am unless otherwise specified
    "ema_offsets": [0, 240, 480, 720 ], // number of minutes from base_time for scheduled EMA events
    "length": "120",                    // window length for each EMA events
    "reminders": [ 5, 10 ]              // minutes from start time when reminders should be sent
    "close_offset": "20"                // number of minutes after start time when window is closed
    "randomize": true                   // whether to randomize the 'start_time' inside the length of the window
    "instrument": "..."                 // name of the REDCap instrument where EMA will initiate

}

Person A wakes at 6am - goal is to start at 8am.  During baseline we ask them when do you typically wake and store the
result in a variable.

During baseline we ask a person when they typically wake -- result is stored in variable defined in window.

When window logic is true, we generate a schedule for the window and use it to populate instances of the instrument
to be sent.  Instrument must contain:
- window name
- window day number
- ema offset
- ema open (this is the calculated offset + randomization) -- this is time zero for this assessment.
- ema open ts (timestamp version?)
- ema_actions (s/sent, r1, r2, aoac, x/cancelled, c/closed)

So, for the activated window, we just created entries for every day and every offset in the schedule (e.g. 7x4=28 instances)
The instance number is somewhat arbitrary.  If all assessments are in the same repeating form, then they could share one
redcap event.  Alternately, you could have repeating forms for each event.


--- What does the cron scheduler do?
Do we run in 5 minute increments?  Seems best.

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
 - if invitation is not sent, we send and mark as sent.
 - if delta between ema_open_ts and now is <= reminders, then mark and send reminders
 - if delta is greater than window, then mark as window closed.

On record-save event, we need to evaluate if logic is true to trigger creation of EMA surveys for windows.
- Do we need to be able to delete/clear all entries for a window (what if something changes?)

Need to use tool for dealing with instances of forms

SMS invitations...

