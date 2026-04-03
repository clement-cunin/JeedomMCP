<?php
/**
 * Embedded JeedomMCP extension for the MerossSync plugin.
 *
 * This file is maintained by the JeedomMCP project and ships as a built-in
 * extension so that MerossSync is MCP-compatible out of the box.
 *
 * -------------------------------------------------------------------------
 * NOTE FOR PLUGIN AUTHORS
 * -------------------------------------------------------------------------
 * If you are the author of MerossSync and want to take ownership of this
 * extension, simply copy this file to:
 *
 *   plugins/MerossSync/mcp/McpExtension.php
 *
 * Your version will automatically take precedence over this embedded one.
 * You can then improve it independently without any dependency on JeedomMCP.
 * -------------------------------------------------------------------------
 */

class MerosSyncMcpExtension {

    public static function getTools(): array {
        return [
            [
                'name'        => 'sync',
                'description' => 'Trigger a MerosSync synchronization — discovers new Meross devices and updates existing ones. Equivalent to the automatic sync that runs every 10 minutes.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            ],
        ];
    }

    public static function callTool(string $name, array $args): array {
        switch ($name) {
            case 'sync': return static::sync();
            default:     throw new Exception("Unknown tool: {$name}");
        }
    }

    private static function sync(): array {
        if (!class_exists('MerosSync')) {
            require_once __DIR__ . '/../../../MerosSync/core/class/MerosSync.class.php';
        }
        MerosSync::syncMeross();
        return ['success' => true, 'message' => 'MerosSync synchronization triggered successfully.'];
    }
}