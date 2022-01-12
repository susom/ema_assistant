<?php
namespace Stanford\EMA;

/** @var EMA $module */


if(isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action == "getRecords") {
        $records = \REDCap::getData([
            "fields"=>\REDCap::getRecordIdField()
        ]);
        $results = array_keys($records);
    }

    if ($action == "getWindows") {
        $record = filter_var($_POST['record'],FILTER_SANITIZE_STRING);
        // $module->emDebug("Looking up windows for record $record");
        $results = $module->deleteIncompleteInstancesByWindow($module->getProjectId(), $record, null);
    }

    if ($action == "deleteInstances") {
        $user = $module->getUser();
        $rights = $user->getRights();
        if (empty($rights['record_delete'])) {
            $module->emError($user->getUsername() . " does not have delete privs");
            $results["error"] = "Insufficient user rights to delete";
        } else {
            // $module->emDebug("Delete Instances", $_POST);
            $record = filter_var($_POST['record'], FILTER_SANITIZE_STRING);
            $window = filter_var($_POST['window'], FILTER_SANITIZE_STRING);
            $results = $module->deleteIncompleteInstancesByWindow($module->getProjectId(), $record, $window);
        }
    }


    header("Content-type: application/json");
    echo json_encode($results);
    exit();
}


require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

// Lets try CronScan!
// $cs = new CronScan($module);
// $cs->scanWindows();
// echo "<pre>CronScan Complete" . print_r($cs,true) . "</pre>";

?>
<style>
    pre {
        max-height: 300px;
        overflow: scroll;
    }
</style>


<style>
    .myspinner {
        height: 100vh;
        display:flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
    }
</style>


    <h3>DELETE Window Instances</h3>
    <p>In some cases, you may need to delete all incomplete EMA assessment instances for a record/window.  For example,
        if you generated the EMAs on the wrong date or if the participant wants to change values.</p>
    <p>This tool identifies the instances of the events where a given window works and then will delete those where
        the window-form is incomplete.  So long as you are only using the repeating form/event for this EMA purpose
        you should be okay and it shouldn't affect other data.  HOWEVER, i strongly recommend you make a backup before
        using this in production if you haven't already thoroughly tested it in development.
        </p>
    <p> If a participant wants to 're-generate' an EMA window but has already completed some of the assessments from
        the incorrect window, you will have to first use this tool to remove all the incomplete instances.  Then, I would
        recommend renaming the 'old' instances to a different window-name.  This will permit the EM to generate a new
        set of EMA instances on the next save.</p>

<p>Select a record:</p>
<select name="record" id="records"></select>

<p>Select a Window:</p>
<select name="window" id="windows">
    <option value="optionValue">NameVal</option>
</select>

<div class="mt-3">
    <span class="hidden btn btn-sm btn-danger" id="remove">Erase Selected Window Incomplete Entry Instances</span>
</div>

<div id="spinner" class="myspinner">
    <div class="spinner-border" role="status">
    </div>
</div>

<?php

// Insert Utils JS
$js = $module->getUrl("js/utils.js");
echo "<script type='text/javascript' src='$js'></script>";
