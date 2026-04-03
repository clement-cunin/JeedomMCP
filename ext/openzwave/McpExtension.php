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
        ];
    }

    public static function callTool(string $name, array $args): array {
        switch ($name) {
            case 'mode_inclusion':   return static::modeInclusion($args);
            case 'mode_exclusion':   return static::modeExclusion();
            case 'cancel':           return static::cancel();
            case 'health':           return static::health();
            case 'node_remove_failed': return static::nodeRemoveFailed($args);
            default:                 throw new Exception("Unknown tool: {$name}");
        }
    }

    private static function loadClass(): void {
        if (!class_exists('openzwave')) {
            require_once __DIR__ . '/../../../openzwave/core/class/openzwave.class.php';
        }
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
}
