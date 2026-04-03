# JeedomMCP Plugin Extension Standard

This document describes how any Jeedom plugin can expose its own MCP tools through JeedomMCP — either natively (implemented by the plugin author) or via an embedded extension shipped with JeedomMCP.

## Two ways to make a plugin MCP-compatible

### 1. Native extension (by the plugin author)

The plugin author creates `plugins/{pluginId}/mcp/McpExtension.php` directly in their plugin. This is the recommended approach for long-term ownership and customization.

### 2. Embedded extension (shipped with JeedomMCP)

JeedomMCP ships built-in extensions for popular plugins that don't yet implement the standard natively. These live in `plugins/jeedomMCP/ext/{pluginId}/McpExtension.php`.

**Any plugin can be MCP-compatible** — even without any change from the plugin author — as long as an embedded extension exists for it.

### Discovery priority

For each active plugin, JeedomMCP checks in order:

1. `plugins/{pluginId}/mcp/McpExtension.php` — native extension (always takes precedence)
2. `plugins/jeedomMCP/ext/{pluginId}/McpExtension.php` — embedded extension

---

## Creating a native extension

Create `plugins/{pluginId}/mcp/McpExtension.php` with a class named `{PluginId}McpExtension` implementing two static methods:

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

---

## Taking ownership of an embedded extension

> **Note for plugin authors**
>
> If JeedomMCP already ships an embedded extension for your plugin (see `ext/` directory), you can take full ownership of it at any time:
>
> 1. Copy `plugins/jeedomMCP/ext/{pluginId}/McpExtension.php` into your plugin at `plugins/{pluginId}/mcp/McpExtension.php`
> 2. Rename the class from `{PluginId}McpExtension` if needed (must match `{yourPluginId}McpExtension`)
> 3. Ship it with your plugin
>
> Your native extension will automatically take precedence over the embedded one — no configuration needed. You can then evolve it independently, add more tools, and improve descriptions without any dependency on JeedomMCP.

---

## Naming convention

JeedomMCP prefixes all extension tool names with `ext_{pluginId}_` automatically:

| Declared in `getTools()` | Exposed in MCP `tools/list` |
|---|---|
| `scan_devices` | `ext_myPlugin_scan_devices` |

The prefix is stripped before `callTool()` is called — your implementation always receives the unprefixed name.

## Access control

Extension tools are gated behind the `ext_{pluginId}.execution` ACL domain:

| ACL mode | Extension tools |
|---|---|
| `read_execute` | blocked |
| `read_execute_describe` | blocked |
| `full` | accessible |
| `full_admin` | accessible |
| `custom` | per-plugin toggle in the configuration page |

## Discovery

JeedomMCP scans for extension files on every `tools/list` and `tools/call` request. Only active plugins are scanned (`plugin::listPlugin(true)`).
