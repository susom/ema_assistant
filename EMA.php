<?php
namespace Stanford\EMA;

require_once "emLoggerTrait.php";

class EMA extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}



	public function redcap_module_system_enable( $version ) {
	}


	public function redcap_module_project_enable( $version, $project_id ) {
	}


	public function redcap_module_save_configuration( $project_id ) {
	}


    /**
     * Read the current config from a single key-value pair in the external module settings table
     */
    function getConfigAsString() {
        $string_config = $this->getProjectSetting($this->PREFIX . '-config');
        // SurveyDashboard::log($string_config);
        return $string_config;
    }

    function setConfigAsString($string_config) {
        $this->setProjectSetting($this->PREFIX . '-config', $string_config);
    }


}
