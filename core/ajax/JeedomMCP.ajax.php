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

    if (init('action') == 'setRoomDescription') {
        $object = jeeObject::byId(init('room_id'));
        if (!is_object($object)) {
            throw new Exception('Room not found: ' . init('room_id'));
        }
        $object->setConfiguration('description', init('description'));
        $object->save();
        ajax::success();
    }

    if (init('action') == 'createRoom') {
        $object = new jeeObject();
        $object->setName(init('name'));
        if (init('description') !== '') $object->setConfiguration('description', init('description'));
        if (init('surface') !== '')     $object->setConfiguration('info::space', init('surface'));
        if (init('orientation') !== '') $object->setConfiguration('info::orientation', init('orientation'));
        if (init('parent_id') !== '')   $object->setFather_id(init('parent_id'));
        $object->save();
        ajax::success(array(
            'id'          => intval($object->getId()),
            'name'        => $object->getName(),
            'description' => $object->getConfiguration('description'),
            'surface'     => $object->getConfiguration('info::space'),
            'orientation' => $object->getConfiguration('info::orientation'),
            'parent_id'   => $object->getFather_id(),
        ));
    }

    if (init('action') == 'updateRoom') {
        $object = jeeObject::byId(init('room_id'));
        if (!is_object($object)) {
            throw new Exception('Room not found: ' . init('room_id'));
        }
        if (init('name') !== '')        $object->setName(init('name'));
        if (init('description') !== '') $object->setConfiguration('description', init('description'));
        if (init('surface') !== '')     $object->setConfiguration('info::space', init('surface'));
        if (init('orientation') !== '') $object->setConfiguration('info::orientation', init('orientation'));
        if (init('parent_id') !== '') {
            $father_id = init('parent_id') === 'null' ? null : init('parent_id');
            $object->setFather_id($father_id);
        }
        $object->save();
        ajax::success(array(
            'id'          => intval($object->getId()),
            'name'        => $object->getName(),
            'description' => $object->getConfiguration('description'),
            'surface'     => $object->getConfiguration('info::space'),
            'orientation' => $object->getConfiguration('info::orientation'),
            'parent_id'   => $object->getFather_id(),
        ));
    }

    if (init('action') == 'deleteRoom') {
        $object = jeeObject::byId(init('room_id'));
        if (!is_object($object)) {
            throw new Exception('Room not found: ' . init('room_id'));
        }
        $object->remove();
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
