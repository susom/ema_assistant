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
        $module->emDebug("Looking up windows for record $record");
        $results = $module->deleteIncompleteInstancesByWindow($module->getProjectId(), $record, null);
    }

    if ($action == "deleteInstances") {
        $user = $module->getUser();
        $rights = $user->getRights();
        $module->emDebug($user, $rights);
        if (empty($rights['record_delete'])) {
            $module->emError($user->getUsername() . " does not have delete privs");
            $results["error"] = "Insufficient user rights to delete";
        } else {
            $module->emDebug("Delete Instances", $_POST);
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

?>
<style>
    .myspinner {
        height: 100vh;
        display:flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
    }
</style>


    <h3>Clear a Record/Window</h3>
<p>In some cases, you may need to delete all incomplete EMA assessments for a record/window.  For example, if you generated
it erroneously or if the window start date changed.  This utility will allow you to pick and remove only those
entries.</p>

<p>Select a record:</p>
<select name="record" id="records"></select>


<p>Select a Window:</p>
<select name="window" id="windows">
    <option value="optionValue">NameVal</option>
</select>
<div class="mt-3">
    <span class="hidden btn btn-sm btn-danger" id="remove">Erase Selected Window Entries</span>
</div>

<div id="spinner" class="myspinner">
    <div class="spinner-border" role="status">
    </div>
</div>

<?php

// Insert Utils
$js = $module->getUrl("js/utils.js");
echo "<script type='text/javascript' src='$js'></script>";
