<?php
namespace Stanford\EMA;
/** @var EMA $module */


try {
    $module->emDebug("Running CronScan");
    $cs = new CronScan($module);
    $cs->scanWindows();
} catch (\Exception $e) {
    $module->emError("Exception in CronScan:", $e->getMessage());
}
