<?php
namespace Stanford\EMA;

/** @var EMA $module */

/**
 * This is the configuration page for saving parameters as a json object
 * into this redcap module instance.
 *
 * Currently, we are using a single json object named "module-name-config" to
 * store everything
 */

if (!empty($_POST['action'])) {
	$action = $_POST['action'];

	switch ($action) {
		case "save":
			// SAVE A CONFIGURATION
			// THIS IS AN AJAX METHOD
            $raw_config = $_POST['raw_config'];
			// $module->debug($raw_config,"DEBUG","Raw Config");

			// Validate that $raw is valid json!
            $json = json_decode($raw_config);
            $json_error = json_last_error_msg();
            if ($json_error == "No error") {
                // SAVE
				$module->setConfigAsString($raw_config);
				$result = array('result' => 'success');
			} else {
				$result = array(
					'result' => 'error',
					'message' => $json_error
				);
			}
			header('Content-Type: application/json');
			print json_encode($result);
			exit();
			break;
		default:
			$module->debug($_POST,"DEBUG","Unsupported Action");
			print "Unknown action";
	}
}


// RENDER THE JSON EDITOR
$b = new \Browser();
$cmdKey = ( $b->getPlatform() == "Apple" ? "&#8984;" : "Ctrl" );
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

?>
    <div class="panel panel-primary">
        <div class="panel-heading pb-2">
            <strong><?php echo $module->getModuleName() ?> Configuration</strong>
        </div>
        <?php
            $use_config_file = $module->getProjectSetting('use-config-file');
            if (!$use_config_file) {
                ?>
                <div class="alert alert-danger text-center">
                    This configuration is not currently being used.  To use this configuration you must uncheck the `use-config-file`
                    option in the External Module setup.
                </div>
                <?php
            }
        ?>
        <div class="panel-body config-editor">
            <div id='config_editor' data-editor="ace" data-mode="json" data-theme="clouds"></div>
        </div>
        <div class="panel-footer">
            <div class="config-editor-buttons">
                <button class="btn btn-primary" name="save">SAVE (<?php echo $cmdKey; ?>-S)</button>
                <button class="btn btn-default" name="beautify">BEAUTIFY</button>
                <button class="btn btn-default" name="cancel">CANCEL</button>
            </div>
        </div>
    </div>



	<style>
		.config-editor { border-bottom: 1px solid #ddd; padding:0;}
	</style>
<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
?>
	<script src="<?php echo $module->getUrl('js/ace/ace.js'); ?>"></script>
    <script src="<?php echo $module->getUrl('js/config.js'); ?>"></script>
    <script>
        // Set the value of the editor
        EM.startVal = <?php print json_encode( $module->getConfigAsString() ) ?>;
        EM.editor.instance.setValue(EM.startVal,1);
    </script>

<?php
