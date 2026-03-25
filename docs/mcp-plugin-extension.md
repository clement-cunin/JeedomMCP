# JeedomMCP Plugin Extension Standard

This document describes the standard that allows any Jeedom plugin to expose its own MCP tools through JeedomMCP, without modifying JeedomMCP itself.

---

## Overview

JeedomMCP discovers extension files in active plugins, merges their tools into the MCP tools list, and routes calls back to the originating plugin. Discovery results are cached to avoid filesystem scans on every request.

---

## Extension file

Create the following file in your plugin:

```
plugins/{pluginId}/mcp/McpExtension.php
```

The file must define a class named `{PluginId}McpExtension` (ucfirst of the plugin id) with two static methods:

```php
<?php

class WifilightV2McpExtension {

    /**
     * Returns the list of MCP tool definitions exposed by this plugin.
     *
     * Tool names must NOT include the plugin prefix — JeedomMCP adds
     * "ext_{pluginId}_" automatically when building the tools list.
     *
     * The format is identical to JeedomMCP's internal tool definitions:
     * each entry is an associative array with keys "name", "description",
     * and "inputSchema" (JSON Schema object).
     */
    public static function getTools(): array {
        return [
            [
                'name'        => 'scan_devices',
                'description' => 'Scan for new WiFi/Meross devices on the local network.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => new stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_device_status',
                'description' => 'Get the current status of a wifilightV2 device.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'device_id' => ['type' => 'integer', 'description' => 'Equipment ID.'],
                    ],
                    'required' => ['device_id'],
                ],
            ],
        ];
    }

    /**
     * Execute a tool call.
     *
     * $name is the unprefixed tool name as declared in getTools().
     * $args is the associative array of arguments from the MCP call.
     *
     * Must return a tool_result() or tool_error() compatible array:
     *   ['content' => [['type' => 'text', 'text' => json_encode($data)]]]
     */
    public static function callTool(string $name, array $args): array {
        switch ($name) {
            case 'scan_devices':      return static::scanDevices($args);
            case 'get_device_status': return static::getDeviceStatus($args);
            default:
                return ['content' => [['type' => 'text', 'text' => json_encode(['error' => "Unknown tool: {$name}"])]], 'isError' => true];
        }
    }

    // --- private implementations ---

    private static function scanDevices(array $args): array {
        // Use Jeedom classes directly — core is already loaded.
        $devices = eqLogic::byType('wifilightV2');
        // ... plugin-specific logic
        return ['content' => [['type' => 'text', 'text' => json_encode(['found' => count($devices)])]]];
    }

    private static function getDeviceStatus(array $args): array {
        $eq = eqLogic::byId((int)($args['device_id'] ?? 0));
        if (!is_object($eq)) {
            return ['content' => [['type' => 'text', 'text' => json_encode(['error' => 'Device not found'])]], 'isError' => true];
        }
        // ...
        return ['content' => [['type' => 'text', 'text' => json_encode(['id' => $eq->getId()])]]];
    }
}
```

---

## Naming convention

JeedomMCP automatically prefixes tool names using the pattern:

```
ext_{pluginId}_{toolName}
```

| Plugin id | Tool declared | Tool visible in MCP |
|---|---|---|
| `wifilightV2` | `scan_devices` | `ext_wifilightV2_scan_devices` |
| `zwavejsui` | `get_node_info` | `ext_zwavejsui_get_node_info` |

The prefix is added when listing tools and stripped when routing a call — your `callTool()` always receives the unprefixed name.

---

## ACL

All tools exposed by a plugin extension are gated behind a single ACL domain:

```
ext_{pluginId}   operation: execution
```

| ACL mode | Extension tools |
|---|---|
| `full_admin` | All extensions accessible |
| `full` | All extensions accessible |
| `read_execute` / `read_execute_describe` | No extensions |
| `custom` | Each `ext_{pluginId}` domain toggled individually in the config page |

The ACL domain appears dynamically in the JeedomMCP configuration page for each plugin that has a `McpExtension.php` file (populated from the cache).

---

## Cache

JeedomMCP caches the discovered extension tools to avoid scanning the filesystem on every MCP request.

**Cache file**: `plugins/jeedomMCP/cache/ext_tools.json`

```json
{
  "generated_at": 1742900000,
  "tools": [
    {
      "name": "ext_wifilightV2_scan_devices",
      "description": "Scan for new WiFi/Meross devices on the local network.",
      "inputSchema": { "type": "object", "properties": {}, "required": [] }
    }
  ],
  "routing": {
    "ext_wifilightV2_scan_devices": "wifilightV2"
  }
}
```

**Cache invalidation**:
- Regenerated when `plugins_list` is called (natural trigger: listing plugins implies plugin state may have changed)
- Invalidated when `plugin_set_active` is called
- TTL fallback: 1 hour (cache is regenerated automatically if older than 3600 seconds, even without an explicit trigger)

---

## Plugin requirements

- The extension file must be loadable via `require_once` without side effects.
- The class must not define functions or globals that conflict with JeedomMCP's namespace.
- Jeedom core classes (`eqLogic`, `cmd`, `config`, etc.) are available — `core.inc.php` is already loaded by the time `callTool()` is invoked.
- Tool names declared in `getTools()` must be unique within the plugin (across plugins, the `ext_{pluginId}_` prefix prevents collisions).
- Keep tool descriptions concise and accurate — they are passed directly to the LLM.

---

## Example directory structure

```
plugins/
  wifilightV2/
    mcp/
      McpExtension.php     ← extension file
    core/
      class/
        wifilightV2.class.php
    plugin_info/
      info.json
```