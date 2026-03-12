<?php
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    // Allow both admin session auth and Jeedom API key auth (for server-side calls)
    $apikey = init('apikey');
    if (!isConnect('admin') && $apikey !== jeedom::getApiKey()) {
        throw new Exception(__('401 - Unauthorized access', __FILE__));
    }

    if (init('action') == 'generateApiKey') {
        if (!isConnect('admin')) {
            throw new Exception(__('401 - Unauthorized access', __FILE__));
        }
        ajax::success(JeedomMCP::generateApiKey());
    }

    if (init('action') == 'setDeviceComment') {
        $eqLogic = eqLogic::byId(init('equipment_id'));
        if (!is_object($eqLogic)) {
            throw new Exception('Equipment not found: ' . init('equipment_id'));
        }
        $eqLogic->setComment(init('comment'));
        $eqLogic->save();
        ajax::success();
    }

    if (init('action') == 'deleteScenario') {
        $scenario = scenario::byId(init('scenario_id'));
        if (!is_object($scenario)) {
            throw new Exception('Scenario not found: ' . init('scenario_id'));
        }
        $scenario->remove();
        ajax::success();
    }

    throw new Exception(__('No method found for: ', __FILE__) . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
