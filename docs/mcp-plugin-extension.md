# JeedomMCP Plugin Extension Standard

This document describes how any Jeedom plugin can expose its own MCP tools through JeedomMCP.

## Overview

JeedomMCP scans all active plugins at runtime for an extension file. If found, the plugin's tools are automatically included in the MCP `tools/list` response and routed by JeedomMCP when called.

## Creating an extension

Create the file `plugins/{pluginId}/mcp/McpExtension.php` with a class named `{PluginId}McpExtension` implementing two static methods:

```php
<?php

class MyPluginMcpExtension {

    /**
     * Returns the list of tool definitions exposed by this plugin.
     * Tool names must NOT include the ext_{pluginId}_ prefix — JeedomMCP adds it.
     */
    public static function getTools(): array {
        return [
            [
                'name'        => 'scan_devices',
                'description' => 'Scan for devices managed by MyPlugin.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [],
                    'required'   => [],
                ],
            ],
        ];
    }

    /**
     * Executes a tool by its unprefixed name.
     * Must return an array (will be wrapped in a tool_result by JeedomMCP).
     */
    public static function callTool(string $name, array $args): array {
        switch ($name) {
            case 'scan_devices':
                // ... your implementation
                return ['devices' => []];
            default:
                throw new Exception("Unknown tool: {$name}");
        }
    }
}
```

## Naming convention

JeedomMCP prefixes all extension tool names with `ext_{pluginId}_` automatically:

| What you declare in `getTools()` | What appears in MCP `tools/list` |
|---|---|
| `scan_devices` | `ext_myPlugin_scan_devices` |

The same prefix is stripped before `callTool()` is called, so your implementation always receives the unprefixed name.

## Access control

Extension tools are gated behind the `ext_{pluginId}.execution` ACL domain:

| ACL mode | Extension tools |
|---|---|
| `read_execute` | blocked |
| `read_execute_describe` | blocked |
| `full` | accessible |
| `full_admin` | accessible |
| `custom` | per-plugin toggle in the configuration page |

In **Custom** mode, each installed extension appears as a row in the JeedomMCP configuration page under **Tool permissions**, with a toggle for the `execution` operation.

## Discovery

JeedomMCP scans for extension files on every `tools/list` and `tools/call` request. There is no persistent cache — the scan is lightweight (file existence check per active plugin).

Active plugins are those with status `active=1` in Jeedom (`plugin::listPlugin(true)`).
