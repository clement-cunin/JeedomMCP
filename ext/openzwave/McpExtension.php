<?php
/**
 * Embedded JeedomMCP extension for the openzwave plugin.
 *
 * This file is maintained by the JeedomMCP project and ships as a built-in
 * extension so that openzwave is MCP-compatible out of the box.
 *
 * -------------------------------------------------------------------------
 * NOTE FOR PLUGIN AUTHORS
 * -------------------------------------------------------------------------
 * If you are the author of openzwave and want to take ownership of this
 * extension, simply copy this file to:
 *
 *   plugins/openzwave/mcp/McpExtension.php
 *
 * Your version will automatically take precedence over this embedded one.
 * You can then improve it independently without any dependency on JeedomMCP.
 * -------------------------------------------------------------------------
 */

class openzwaveMcpExtension {

    public static function getTools(): array {
        return [
            [
                'name'        => 'mode_inclusion',
                'description' => 'Put the Z-Wave controller in inclusion mode to add a new device to the network. The controller stays in inclusion mode until a device is paired or the mode is cancelled with the cancel tool. Trigger the pairing sequence on the device after calling this.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'secure' => [
                            'type'        => 'boolean',
                            'description' => 'If true, include the device with security (S0). Defaults to false (non-secure inclusion).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'mode_exclusion',
                'description' => 'Put the Z-Wave controller in exclusion mode to remove a device from the network. Trigger the reset or exclusion sequence on the device after calling this. Use the cancel tool to abort.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            ],
            [
                'name'        => 'cancel',
                'description' => 'Cancel the current Z-Wave controller operation (inclusion or exclusion mode).',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            ],
            [
                'name'        => 'health',
                'description' => 'Return the health status of the Z-Wave network: network readiness state and per-node information (product name, battery level, awake/sleep state, failed status, neighbour count).',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            ],
            [
                'name'        => 'node_remove_failed',
                'description' => 'Force-remove a dead or burned-out Z-Wave node that can no longer be excluded normally. The node must be marked as failed by the controller. Use the health tool to find node IDs and their failed status.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'node_id' => [
                            'type'        => 'integer',
                            'description' => 'The Z-Wave node ID to force-remove.',
                        ],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            [
                'name'        => 'node_config_get',
                'description' => 'Get all configuration parameters of a Z-Wave node (Command Class 112). Returns each parameter index with its current value, label, and allowed values for list-type parameters. Provide either node_id (Z-Wave node ID) or device_id (Jeedom equipment ID from devices_list).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'node_id'   => ['type' => 'integer', 'description' => 'The Z-Wave node ID (plugin_id from devices_list with with_plugin_info=true).'],
                        'device_id' => ['type' => 'integer', 'description' => 'Jeedom equipment ID (id from devices_list). Resolved to the Z-Wave node ID automatically.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'node_config_set',
                'description' => 'Set a configuration parameter on a Z-Wave node (Command Class 112). For sleeping devices the change will be applied on next wake-up. Provide either node_id (Z-Wave node ID) or device_id (Jeedom equipment ID from devices_list).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'node_id'   => ['type' => 'integer', 'description' => 'The Z-Wave node ID (plugin_id from devices_list with with_plugin_info=true).'],
                        'device_id' => ['type' => 'integer', 'description' => 'Jeedom equipment ID (id from devices_list). Resolved to the Z-Wave node ID automatically.'],
                        'index'     => ['type' => 'integer', 'description' => 'The parameter index to set.'],
                        'value'     => ['type' => 'string',  'description' => 'The value to set (e.g. "1", "true", or the list label).'],
                    ],
                    'required' => ['index', 'value'],
                ],
            ],
        ];
    }

    public static function callTool(string $name, array $args): array {
        switch ($name) {
            case 'mode_inclusion':   return static::modeInclusion($args);
            case 'mode_exclusion':   return static::modeExclusion();
            case 'cancel':           return static::cancel();
            case 'health':           return static::health();
            case 'node_remove_failed': return static::nodeRemoveFailed($args);
            case 'node_config_get':  return static::nodeConfigGet($args);
            case 'node_config_set':  return static::nodeConfigSet($args);
            default:                 throw new Exception("Unknown tool: {$name}");
        }
    }

    private static function loadClass(): void {
        if (!class_exists('openzwave')) {
            require_once __DIR__ . '/../../../openzwave/core/class/openzwave.class.php';
        }
    }

    private static function resolveNodeId(array $args): int {
        if (!empty($args['node_id'])) {
            return (int) $args['node_id'];
        }
        if (!empty($args['device_id'])) {
            $eq = eqLogic::byId((int) $args['device_id']);
            if (!is_object($eq)) throw new Exception('Device not found: ' . $args['device_id']);
            if ($eq->getEqType_name() !== 'openzwave') throw new Exception('Device ' . $args['device_id'] . ' is not managed by openzwave.');
            $logicalId = $eq->getLogicalId();
            if (!$logicalId) throw new Exception('Device ' . $args['device_id'] . ' has no logical_id.');
            return (int) $logicalId;
        }
        throw new Exception('Provide either node_id or device_id.');
    }

    private static function modeInclusion(array $args): array {
        static::loadClass();
        $secure = !empty($args['secure']) ? 1 : 0;
        openzwave::callOpenzwave('controller?type=addNode&security=' . $secure);
        return [
            'success' => true,
            'secure'  => (bool) $secure,
            'message' => 'Z-Wave controller is now in inclusion mode. Trigger the pairing sequence on your device.',
        ];
    }

    private static function modeExclusion(): array {
        static::loadClass();
        openzwave::callOpenzwave('controller?type=removeNode');
        return [
            'success' => true,
            'message' => 'Z-Wave controller is now in exclusion mode. Trigger the reset or exclusion sequence on your device.',
        ];
    }

    private static function cancel(): array {
        static::loadClass();
        openzwave::callOpenzwave('controller?type=action&action=cancelCommand');
        return [
            'success' => true,
            'message' => 'Z-Wave controller operation cancelled.',
        ];
    }

    private static function health(): array {
        static::loadClass();
        $status = openzwave::callOpenzwave('/network?type=info&info=getStatus');
        $health  = openzwave::callOpenzwave('/network?type=info&info=getHealth');

        $state   = $status['result']['state'] ?? null;
        $devices = $health['result']['devices'] ?? [];

        $nodes = [];
        foreach ($devices as $nodeId => $node) {
            $data = $node['data'] ?? [];
            $nodes[(int) $nodeId] = [
                'name'            => $data['description']['product_name'] ?? $data['product_name']['value'] ?? 'Unknown',
                'is_failed'       => $data['isFailed']['value'] ?? false,
                'is_awake'        => $data['isAwake']['value'] ?? true,
                'battery_level'   => $data['battery_level']['value'] ?? null,
                'neighbour_count' => $data['is_neighbours_ok']['neighbors'] ?? null,
            ];
        }
        ksort($nodes);

        return [
            'network_state' => $state,
            'network_ready' => ($state !== null && $state >= 7),
            'node_count'    => count($nodes),
            'nodes'         => $nodes,
        ];
    }

    private static function nodeRemoveFailed(array $args): array {
        static::loadClass();
        $nodeId = (int) ($args['node_id'] ?? 0);
        if ($nodeId <= 0) {
            throw new Exception('node_id must be a positive integer.');
        }
        $check  = openzwave::callOpenzwave('/node?node_id=' . $nodeId . '&type=info&info=hasNodeFailed');
        $failed = $check['result']['data'] ?? null;
        if ($failed === false) {
            return [
                'success'  => false,
                'node_id'  => $nodeId,
                'message'  => 'Node ' . $nodeId . ' is not marked as failed by the controller. Use mode_exclusion to remove it normally.',
            ];
        }
        openzwave::callOpenzwave('/node?node_id=' . $nodeId . '&type=action&action=removeFailedNode');
        return [
            'success' => true,
            'node_id' => $nodeId,
            'message' => 'Force-remove of node ' . $nodeId . ' sent to the controller.',
        ];
    }

    private static function nodeConfigGet(array $args): array {
        static::loadClass();
        $nodeId  = static::resolveNodeId($args);
        $result  = openzwave::callOpenzwave('/node?node_id=' . $nodeId . '&type=info&info=all');
        // CC 112 = Configuration — look in any instance
        $instances = $result['instances'] ?? $result['result']['instances'] ?? [];
        $cc_data   = [];
        foreach ($instances as $instance) {
            if (isset($instance['commandClasses'][112]['data'])) {
                foreach ($instance['commandClasses'][112]['data'] as $index => $entry) {
                    if (!is_array($entry) || $index === 'updateTime') continue;
                    $cc_data[(int) $index] = $entry;
                }
            }
        }

        $params = [];
        foreach ($cc_data as $index => $entry) {
            $param = [
                'label'     => $entry['name']  ?? null,
                'help'      => $entry['help']  ?? null,
                'type'      => $entry['typeZW'] ?? $entry['type'] ?? null,
                'units'     => $entry['units'] !== '' ? ($entry['units'] ?? null) : null,
                'value'     => $entry['val']   ?? null,
                'read_only' => $entry['read_only'] ?? false,
            ];
            if (!empty($entry['data_items'])) {
                $param['allowed_values'] = $entry['data_items'];
            }
            $params[$index] = array_filter($param, fn($v) => $v !== null && $v !== false);
        }
        ksort($params);

        return [
            'node_id' => $nodeId,
            'params'  => $params,
        ];
    }

    private static function nodeConfigSet(array $args): array {
        static::loadClass();
        $nodeId = static::resolveNodeId($args);
        $index  = (int) ($args['index']   ?? -1);
        $value  = (string) ($args['value'] ?? '');
        if ($index < 0) {
            throw new Exception('index must be a non-negative integer.');
        }
        openzwave::callOpenzwave(
            '/node?node_id=' . $nodeId .
            '&instance_id=0&cc_id=112&index=' . $index .
            '&type=setconfig&value=' . urlencode($value) . '&size=0'
        );
        return [
            'success' => true,
            'node_id' => $nodeId,
            'index'   => $index,
            'value'   => $value,
            'message' => 'Configuration parameter ' . $index . ' set on node ' . $nodeId . '. Sleeping devices will apply the change on next wake-up.',
        ];
    }
}
