<?php
/**
 * Embedded JeedomMCP extension for the JeedomConnect plugin.
 *
 * This file is maintained by the JeedomMCP project and ships as a built-in
 * extension so that JeedomConnect is MCP-compatible out of the box.
 *
 * -------------------------------------------------------------------------
 * NOTE FOR PLUGIN AUTHORS
 * -------------------------------------------------------------------------
 * If you are the author of JeedomConnect and want to take ownership of this
 * extension, simply copy this file to:
 *
 *   plugins/JeedomConnect/mcp/McpExtension.php
 *
 * Your version will automatically take precedence over this embedded one.
 * -------------------------------------------------------------------------
 */

class JeedomConnectMcpExtension {

    public static function getTools(): array {
        return [
            [
                'name'        => 'devices_list',
                'description' => 'List all JeedomConnect instances and their paired mobile devices (name, platform, last seen). Each instance corresponds to one user/device configured in JeedomConnect.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            ],
            [
                'name'        => 'notifications_list',
                'description' => 'List available push notification configurations for a JeedomConnect instance. Use this to discover notification_id values before calling send_notification.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'equipment_id' => ['type' => 'integer', 'description' => 'Jeedom equipment ID of the JeedomConnect instance (from devices_list).'],
                    ],
                    'required' => ['equipment_id'],
                ],
            ],
            [
                'name'        => 'send_notification',
                'description' => 'Send a push notification to a JeedomConnect mobile device. Use notifications_list to discover available notification_id values.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'equipment_id'    => ['type' => 'integer', 'description' => 'Jeedom equipment ID of the JeedomConnect instance (from devices_list).'],
                        'notification_id' => ['type' => 'string',  'description' => 'ID of the notification to send (from notifications_list). Defaults to "defaultNotif".'],
                        'title'           => ['type' => 'string',  'description' => 'Notification title (optional override).'],
                        'message'         => ['type' => 'string',  'description' => 'Notification message body.'],
                    ],
                    'required' => ['equipment_id', 'message'],
                ],
            ],
            [
                'name'        => 'config_get',
                'description' => 'Return the interface layout of a JeedomConnect instance: tabs, sections, and the list of widgets per section (id, type, name only). Use this to explore what is displayed on the mobile app without the internal command details.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'equipment_id' => ['type' => 'integer', 'description' => 'Jeedom equipment ID of the JeedomConnect instance (from devices_list).'],
                    ],
                    'required' => ['equipment_id'],
                ],
            ],
            [
                'name'        => 'get_geofences',
                'description' => 'Return configured geofences for a JeedomConnect instance with the current inside/outside status of the paired device.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'equipment_id' => ['type' => 'integer', 'description' => 'Jeedom equipment ID of the JeedomConnect instance (from devices_list).'],
                    ],
                    'required' => ['equipment_id'],
                ],
            ],
        ];
    }

    public static function callTool(string $name, array $args): array {
        switch ($name) {
            case 'devices_list':       return static::devicesList();
            case 'notifications_list': return static::notificationsList((int)($args['equipment_id'] ?? 0));
            case 'send_notification':  return static::sendNotification($args);
            case 'config_get':         return static::configGet((int)($args['equipment_id'] ?? 0));
            case 'get_geofences':      return static::getGeofences((int)($args['equipment_id'] ?? 0));
            default:                   throw new Exception("Unknown tool: {$name}");
        }
    }

    private static function loadClass(): void {
        if (!class_exists('JeedomConnect')) {
            require_once __DIR__ . '/../../../JeedomConnect/core/class/JeedomConnect.class.php';
        }
    }

    private static function getEqLogic(int $equipmentId): object {
        $eq = eqLogic::byId($equipmentId);
        if (!is_object($eq)) throw new Exception("Equipment {$equipmentId} not found");
        if ($eq->getEqType_name() !== 'JeedomConnect') throw new Exception("Equipment {$equipmentId} is not a JeedomConnect instance");
        return $eq;
    }

    private static function devicesList(): array {
        static::loadClass();
        $devices = [];
        foreach (JeedomConnect::getAllJCequipment() as $eq) {
            $deviceId = $eq->getConfiguration('deviceId');
            $item = [
                'equipment_id'   => (int) $eq->getId(),
                'name'           => $eq->getName(),
                'is_enable'      => (bool) $eq->getIsEnable(),
            ];
            if ($deviceId) {
                $item['device_name'] = $eq->getConfiguration('deviceName') ?: null;
                $item['platform']    = $eq->getConfiguration('platformOs')  ?: null;
                $item['last_seen']   = $eq->getConfiguration('lastSeen')    ? (int) $eq->getConfiguration('lastSeen') : null;
                $item['app_state']   = $eq->getConfiguration('appState')    ?: null;
                $item['has_token']   = !empty($eq->getConfiguration('token'));
            } else {
                $item['device_name'] = null;
                $item['platform']    = null;
                $item['paired']      = false;
            }
            $devices[] = $item;
        }
        return ['count' => count($devices), 'devices' => $devices];
    }

    private static function notificationsList(int $equipmentId): array {
        static::loadClass();
        $eq     = static::getEqLogic($equipmentId);
        $config = $eq->getNotifs();
        $notifs = [];
        foreach ($config['notifs'] ?? [] as $notif) {
            $notifs[] = [
                'id'      => $notif['id'],
                'name'    => $notif['name'] ?? $notif['id'],
                'channel' => $notif['channel'] ?? 'default',
            ];
        }
        return [
            'equipment_id' => $equipmentId,
            'notifications' => $notifs,
        ];
    }

    private static function sendNotification(array $args): array {
        static::loadClass();
        $equipmentId    = (int) ($args['equipment_id']    ?? 0);
        $notificationId = (string) ($args['notification_id'] ?? 'defaultNotif');
        $message        = (string) ($args['message']      ?? '');
        $title          = isset($args['title']) ? (string) $args['title'] : null;

        if ($equipmentId === 0) throw new Exception('equipment_id is required');
        if ($message === '')    throw new Exception('message is required');

        $eq = static::getEqLogic($equipmentId);

        $payload = ['message' => $message];
        if ($title !== null) $payload['title'] = $title;

        $eq->sendNotif($notificationId, [
            'type'    => 'DISPLAY_NOTIF',
            'payload' => $payload,
        ]);

        return [
            'success'         => true,
            'equipment_id'    => $equipmentId,
            'notification_id' => $notificationId,
            'message'         => $message,
        ];
    }

    private static function configGet(int $equipmentId): array {
        static::loadClass();
        $eq     = static::getEqLogic($equipmentId);
        $config = $eq->getConfig();
        $payload = $config['payload'] ?? [];

        // Index sections by id
        $sections = [];
        foreach ($payload['sections'] ?? [] as $s) {
            $sections[$s['id']] = [
                'id'      => $s['id'],
                'name'    => $s['name'] ?? '',
                'index'   => $s['index'] ?? 0,
                'widgets' => [],
            ];
        }

        // Index widgets by their section (parentId)
        // Use generated config to get type and name
        $generated  = $eq->getGeneratedConfigFile() ?? [];
        $gen_payload = $generated['payload'] ?? $payload;
        $widget_map  = [];
        foreach ($gen_payload['widgets'] ?? [] as $w) {
            $widget_map[$w['widgetId'] ?? $w['id']] = $w;
        }

        foreach ($payload['widgets'] ?? [] as $w) {
            $parentId = $w['parentId'] ?? null;
            if ($parentId === null || !isset($sections[$parentId])) continue;
            $enriched = $widget_map[$w['widgetId'] ?? $w['id']] ?? $w;
            $sections[$parentId]['widgets'][] = [
                'id'    => $w['widgetId'] ?? $w['id'],
                'type'  => $enriched['type'] ?? null,
                'name'  => $enriched['name'] ?? null,
                'index' => $w['index'] ?? 0,
            ];
        }

        // Sort widgets by index within each section
        foreach ($sections as &$s) {
            usort($s['widgets'], fn($a, $b) => $a['index'] <=> $b['index']);
            unset($s['index']); // cleanup internal field
        }
        unset($s);

        // Build tabs with their sections
        $tabs = [];
        foreach ($payload['tabs'] ?? [] as $t) {
            $tabs[] = [
                'id'       => $t['id'],
                'name'     => $t['name'] ?? '',
                'index'    => $t['index'] ?? 0,
                'sections' => [],
            ];
        }

        // Sections have parentId pointing to a tab
        $tab_map = [];
        foreach ($tabs as &$t) { $tab_map[$t['id']] = &$t; }
        unset($t);

        foreach ($payload['sections'] ?? [] as $s) {
            $tabId = $s['parentId'] ?? null;
            if ($tabId !== null && isset($tab_map[$tabId])) {
                $sec = $sections[$s['id']];
                $tab_map[$tabId]['sections'][] = $sec;
            }
        }

        // Sort tabs and sections by index
        usort($tabs, fn($a, $b) => $a['index'] <=> $b['index']);
        foreach ($tabs as &$t) {
            usort($t['sections'], fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
            unset($t['index']);
        }
        unset($t);

        return [
            'equipment_id'   => $equipmentId,
            'config_version' => $payload['configVersion'] ?? null,
            'tabs'           => $tabs,
        ];
    }

    private static function getGeofences(int $equipmentId): array {
        static::loadClass();
        $eq = static::getEqLogic($equipmentId);

        $geofences = [];
        foreach ($eq->getCmd('info') as $cmd) {
            if (strpos($cmd->getLogicalId(), 'geofence_') !== 0) continue;
            $geofences[] = [
                'identifier' => substr($cmd->getLogicalId(), strlen('geofence_')),
                'name'       => $cmd->getName(),
                'latitude'   => (float) $cmd->getConfiguration('latitude'),
                'longitude'  => (float) $cmd->getConfiguration('longitude'),
                'radius_m'   => (float) $cmd->getConfiguration('radius'),
                'inside'     => (bool) $cmd->getCache('value'),
            ];
        }

        return [
            'equipment_id' => $equipmentId,
            'count'        => count($geofences),
            'geofences'    => $geofences,
        ];
    }
}
