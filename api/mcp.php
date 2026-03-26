<?php
/**
 * JeedomMCP - PHP MCP Server (streamable-http transport)
 *
 * Implements the Model Context Protocol entirely in PHP,
 * using Jeedom's internal classes directly (no Python daemon needed).
 *
 * Endpoint: /plugins/JeedomMCP/api/mcp.php
 * Auth:     X-API-Key header must match the stored MCP API key.
 */

ob_start();
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
ob_clean();

// ---------------------------------------------------------------------------
// Admin actions (Jeedom session auth, not API key)
// ---------------------------------------------------------------------------

if (isset($_GET['action'])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $authorized = false;
    if (isset($_POST['nonce'])) {
        $stored = config::byKey('admin_nonce', 'jeedomMCP', '');
        $parts = explode('|', $stored);
        if (count($parts) === 2 && hash_equals($parts[0], $_POST['nonce']) && time() < (int)$parts[1]) {
            $authorized = true;
        }
    }
    if (!$authorized) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    if ($_GET['action'] === 'generateApiKey') {
        $key = bin2hex(random_bytes(24));
        config::save('mcpApiKey', $key, 'jeedomMCP');
        echo json_encode(['success' => true, 'key' => $key]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function mcp_respond(array $data, int $status = 200): void {
    ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_ok($id, $result): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

function mcp_error($id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function tool_result($data): array {
    return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]]];
}

function tool_error(string $message): array {
    return ['content' => [['type' => 'text', 'text' => json_encode(['error' => $message])]], 'isError' => true];
}

function acl_allowed(string $domain, string $operation): bool {
    $mode = config::byKey('acl_mode', 'jeedomMCP', 'read_execute');
    $is_ext   = strncmp($domain, 'ext_',   4) === 0;
    $is_admin = strncmp($domain, 'admin_', 6) === 0;
    switch ($mode) {
        case 'full_admin': return true;
        case 'full':       return !$is_admin;
        case 'read_execute_describe':
            if ($is_ext || $is_admin) return false;
            return in_array($operation, ['read', 'execution', 'set_description']);
        case 'custom':
            return config::byKey("acl_{$domain}_{$operation}", 'jeedomMCP', '0') == 1;
        default: // read_execute
            if ($is_ext || $is_admin) return false;
            return in_array($operation, ['read', 'execution']);
    }
}

function acl_check(string $domain, string $operation): void {
    if (!acl_allowed($domain, $operation)) {
        throw new Exception("Operation '{$operation}' on '{$domain}' is not authorized");
    }
}

// ---------------------------------------------------------------------------
// Plugin extension cache
// ---------------------------------------------------------------------------

function ext_cache_path(): string {
    return __DIR__ . '/../cache/ext_tools.json';
}

function ext_cache_build(): array {
    $tools   = [];
    $routing = [];
    foreach (plugin::listPlugin(true) as $plugin) {
        $id   = $plugin->getId();
        $file = __DIR__ . "/../../{$id}/mcp/McpExtension.php";
        if (!file_exists($file)) continue;
        require_once $file;
        $class = ucfirst($id) . 'McpExtension';
        if (!class_exists($class) || !method_exists($class, 'getTools')) continue;
        foreach ($class::getTools() as $tool) {
            if (empty($tool['name'])) continue;
            $prefixed           = "ext_{$id}_{$tool['name']}";
            $tool['name']       = $prefixed;
            $tools[]            = $tool;
            $routing[$prefixed] = $id;
        }
    }
    $cache = ['generated_at' => time(), 'tools' => $tools, 'routing' => $routing];
    $dir   = dirname(ext_cache_path());
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(ext_cache_path(), json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $cache;
}

function ext_cache_get(bool $force = false): array {
    $path = ext_cache_path();
    if (!$force && file_exists($path)) {
        $data = json_decode(file_get_contents($path), true);
        if (is_array($data) && isset($data['generated_at']) && (time() - $data['generated_at']) < 3600) {
            return $data;
        }
    }
    return ext_cache_build();
}

function ext_cache_invalidate(): void {
    $path = ext_cache_path();
    if (file_exists($path)) unlink($path);
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

$api_key = config::byKey('mcpApiKey', 'jeedomMCP');
$request_key = $_SERVER['HTTP_X_API_KEY'] ?? '';

if (empty($api_key) || $request_key !== $api_key) {
    mcp_respond(['error' => 'Unauthorized'], 401);
}

// ---------------------------------------------------------------------------
// Request routing
// ---------------------------------------------------------------------------

$http_method = $_SERVER['REQUEST_METHOD'] ?? 'POST';

if ($http_method === 'GET') {
    // Health check
    mcp_respond(['status' => 'ok', 'server' => 'JeedomMCP-PHP']);
}

if ($http_method !== 'POST') {
    mcp_respond(['error' => 'Method not allowed'], 405);
}

$body = file_get_contents('php://input');
$rpc = json_decode($body, true);

if (!is_array($rpc)) {
    mcp_respond(mcp_error(null, -32700, 'Parse error'), 400);
}

$rpc_method = $rpc['method'] ?? '';
$rpc_id     = $rpc['id'] ?? null;
$rpc_params = $rpc['params'] ?? [];

// ---------------------------------------------------------------------------
// MCP protocol dispatch
// ---------------------------------------------------------------------------

switch ($rpc_method) {

    case 'initialize':
        mcp_respond(mcp_ok($rpc_id, [
            'protocolVersion' => '2024-11-05',
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => 'JeedomMCP-PHP', 'version' => '1.0.0'],
            'instructions'    => (
                'Control a Jeedom home automation system. ' .
                'Use devices_list to discover available equipment, ' .
                'devices_states to refresh current values, ' .
                'command_execute to trigger actions, ' .
                'and scenarios_list / scenario_run for automation scenarios.'
            ),
        ]));

    case 'notifications/initialized':
        // Notification — no response body expected
        ob_end_clean();
        http_response_code(202);
        exit;

    case 'ping':
        mcp_respond(mcp_ok($rpc_id, new stdClass()));

    case 'tools/list':
        mcp_respond(mcp_ok($rpc_id, ['tools' => mcp_get_tools()]));

    case 'tools/call':
        $tool_name = $rpc_params['name'] ?? '';
        $args      = $rpc_params['arguments'] ?? [];
        $result    = mcp_call_tool($tool_name, $args);
        mcp_respond(mcp_ok($rpc_id, $result));

    default:
        mcp_respond(mcp_error($rpc_id, -32601, 'Method not found: ' . $rpc_method));
}

// ---------------------------------------------------------------------------
// Tool registry
// ---------------------------------------------------------------------------

function mcp_get_tools(): array {
    return [
        [
            'name'        => 'devices_list',
            'description' => 'List all enabled Jeedom equipment with their current state and available actions. Use include_state=false or include_actions=false to reduce response size when only metadata is needed.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'categories'      => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string', 'enum' => ['heating', 'security', 'energy', 'light', 'opening', 'automatism', 'multimedia', 'default']],
                        'description' => 'Filter by category — returns equipment matching at least one.',
                    ],
                    'room_ids'        => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Filter by room — returns only equipment whose room_id is in this list.',
                    ],
                    'include_hidden'     => ['type' => 'boolean', 'description' => 'Include devices hidden in the Jeedom UI (is_visible=false). Default false.'],
                    'include_state'      => ['type' => 'boolean', 'description' => 'Include the state map for each device (default true).'],
                    'include_actions'    => ['type' => 'boolean', 'description' => 'Include the actions array for each device (default true).'],
                    'include_historical' => ['type' => 'boolean', 'description' => 'Include a "historical" array listing historized info commands with their id, name and unit. Use these ids with device_get_history. Default false.'],
                    'limit'           => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 50). Use 0 for no limit.'],
                    'offset'          => ['type' => 'integer', 'description' => 'Number of items to skip (default 0).'],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'device_set_description',
            'description' => 'Set the description (comment) of a Jeedom equipment.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'equipment_id' => ['type' => 'integer', 'description' => 'Equipment ID obtained from devices_list.'],
                    'description'  => ['type' => 'string',  'description' => 'Description text explaining the equipment\'s purpose or location.'],
                ],
                'required' => ['equipment_id', 'description'],
            ],
        ],
        [
            'name'        => 'device_update',
            'description' => 'Update a device\'s metadata: name, room assignment, and/or categories. Only provided fields are modified.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'equipment_id' => ['type' => 'integer', 'description' => 'Equipment ID obtained from devices_list.'],
                    'name'         => ['type' => 'string',  'description' => 'New display name for the device.'],
                    'room_id'      => ['type' => 'integer', 'description' => 'ID of the room to assign the device to. Pass 0 to unassign.'],
                    'categories'   => [
                        'type'        => 'array',
                        'description' => 'List of category keys to assign. Replaces existing categories.',
                        'items'       => ['type' => 'string', 'enum' => ['heating', 'security', 'energy', 'light', 'opening', 'automatism', 'multimedia', 'default']],
                    ],
                ],
                'required' => ['equipment_id'],
            ],
        ],
        [
            'name'        => 'device_get_history',
            'description' => 'Query the history of a device command (sensor values, power consumption, states…). Identify the command either by command_id alone, or by equipment_id + command_name together. Use devices_list with include_historical=true to discover historized command names.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'command_id'     => ['type' => 'integer', 'description' => 'Direct command ID. Use this or (equipment_id + command_name).'],
                    'equipment_id'   => ['type' => 'integer', 'description' => 'Equipment ID (from devices_list). Use with command_name.'],
                    'command_name'   => ['type' => 'string',  'description' => 'Name of the historized command (from the historical array in devices_list). Use with equipment_id.'],
                    'start'          => ['type' => 'string',  'description' => 'Start datetime, ISO 8601 or YYYY-MM-DD. Defaults to 7 days ago.'],
                    'end'            => ['type' => 'string',  'description' => 'End datetime, ISO 8601 or YYYY-MM-DD. Defaults to now.'],
                    'aggregate'      => ['type' => 'string',  'description' => '"stats" (default): single summary (avg/min/max/sum/count). "avg"/"min"/"max"/"sum": time series grouped by group_by. "raw": all individual points.', 'enum' => ['raw', 'stats', 'avg', 'min', 'max', 'sum']],
                    'group_by'       => ['type' => 'string',  'description' => 'Time bucket for series aggregates. Ignored for "raw" and "stats".', 'enum' => ['hour', 'day']],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'devices_states',
            'description' => 'Bulk refresh the state of equipment. Provide equipment_ids for specific devices, or use categories/room_ids to match devices without prior discovery. Returns {id, state} per device.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'equipment_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'List of specific equipment IDs to refresh.',
                    ],
                    'categories' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string', 'enum' => ['heating', 'security', 'energy', 'light', 'opening', 'automatism', 'multimedia', 'default']],
                        'description' => 'Filter by category — returns all devices matching any of the given categories.',
                    ],
                    'room_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Filter by room — returns all devices in any of the given rooms.',
                    ],
                ],
            ],
        ],
        [
            'name'        => 'command_execute',
            'description' => 'Execute one or more action commands with optional per-command values. Use {id, value} for a single command, {ids, value} to share a value across several commands.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'commands' => [
                        'type'        => 'array',
                        'description' => 'List of commands to execute. Each entry has either "id" (int) or "ids" (int[]), plus an optional "value" string.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'id'    => ['type' => 'integer', 'description' => 'Single command ID.'],
                                'ids'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Multiple command IDs sharing the same value.'],
                                'value' => ['type' => 'string', 'description' => 'Value for slider, color or message subTypes.'],
                            ],
                        ],
                    ],
                ],
                'required' => ['commands'],
            ],
        ],
        [
            'name'        => 'rooms_list',
            'description' => 'List all rooms (objects) in the Jeedom home. Returns a paginated response.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit'  => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 50). Use 0 for no limit.'],
                    'offset' => ['type' => 'integer', 'description' => 'Number of items to skip (default 0).'],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'room_set_description',
            'description' => 'Set the description of a Jeedom room.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'room_id'     => ['type' => 'integer', 'description' => 'Room ID obtained from rooms_list.'],
                    'description' => ['type' => 'string',  'description' => 'Description text explaining the room.'],
                ],
                'required' => ['room_id', 'description'],
            ],
        ],
        [
            'name'        => 'room_create',
            'description' => 'Create a new room in Jeedom.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'name'        => ['type' => 'string',  'description' => 'Display name of the room.'],
                    'description' => ['type' => 'string',  'description' => 'Optional description of the room.'],
                    'surface'     => ['type' => 'string',  'description' => 'Floor area in square metres (e.g. "25").'],
                    'orientation' => ['type' => 'integer', 'description' => 'Orientation in degrees: 0=N, 45=NE, 90=E, 135=SE, 180=S, 225=SW, 270=W, 315=NW.'],
                    'parent_id'   => ['type' => 'integer', 'description' => 'ID of the parent room for hierarchy, or null for top-level.'],
                ],
                'required' => ['name'],
            ],
        ],
        [
            'name'        => 'room_update',
            'description' => 'Update fields of an existing room. Only provided fields are modified.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'room_id'     => ['type' => 'integer', 'description' => 'Room ID obtained from rooms_list.'],
                    'name'        => ['type' => 'string',  'description' => 'New display name.'],
                    'description' => ['type' => 'string',  'description' => 'New description.'],
                    'icon'        => ['type' => 'string',  'description' => 'CSS icon class (e.g. "icon maison-wc" or "fas fa-home"). Pass empty string to clear.'],
                    'surface'     => ['type' => 'string',  'description' => 'Floor area in square metres.'],
                    'orientation' => ['type' => 'integer', 'description' => 'Orientation in degrees: 0=N, 45=NE, 90=E, 135=SE, 180=S, 225=SW, 270=W, 315=NW. Pass null to clear.'],
                    'parent_id'   => ['type' => 'integer', 'description' => 'Parent room ID. Pass 0 to move to top-level.'],
                ],
                'required' => ['room_id'],
            ],
        ],
        [
            'name'        => 'room_delete',
            'description' => 'Delete a room from Jeedom permanently.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'room_id' => ['type' => 'integer', 'description' => 'Room ID obtained from rooms_list.'],
                ],
                'required' => ['room_id'],
            ],
        ],
        [
            'name'        => 'acl_list',
            'description' => 'Returns the current ACL mode and all authorized operations. Call this first to know which tools are available.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
        [
            'name'        => 'scenarios_list',
            'description' => 'List all Jeedom scenarios. Returns a paginated response.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit'  => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 50). Use 0 for no limit.'],
                    'offset' => ['type' => 'integer', 'description' => 'Number of items to skip (default 0).'],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'scenario_run',
            'description' => 'Trigger a Jeedom scenario.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['scenario_id' => ['type' => 'integer', 'description' => 'Scenario ID obtained from scenarios_list.']],
                'required'   => ['scenario_id'],
            ],
        ],
        [
            'name'        => 'scenario_delete',
            'description' => 'Delete a Jeedom scenario permanently.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['scenario_id' => ['type' => 'integer', 'description' => 'Scenario ID obtained from scenarios_list.']],
                'required'   => ['scenario_id'],
            ],
        ],
        [
            'name'        => 'scenario_get_actions',
            'description' => 'Get the full action blocks of a Jeedom scenario (elements, sub-elements, expressions).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['scenario_id' => ['type' => 'integer', 'description' => 'Scenario ID obtained from scenarios_list.']],
                'required'   => ['scenario_id'],
            ],
        ],
        [
            'name'        => 'scenario_set_actions',
            'description' => 'Replace the action blocks of a Jeedom scenario. The elements structure mirrors the output of scenario_get_actions.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'scenario_id' => ['type' => 'integer', 'description' => 'Scenario ID obtained from scenarios_list.'],
                    'elements'    => ['type' => 'array',   'description' => 'Full list of action blocks to save (replaces existing blocks).'],
                ],
                'required' => ['scenario_id', 'elements'],
            ],
        ],
        [
            'name'        => 'scenario_set_description',
            'description' => 'Set the description of a Jeedom scenario.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'scenario_id' => ['type' => 'integer', 'description' => 'Scenario ID obtained from scenarios_list.'],
                    'description' => ['type' => 'string',  'description' => 'Description text explaining the scenario\'s purpose.'],
                ],
                'required' => ['scenario_id', 'description'],
            ],
        ],
        [
            'name'        => 'scenario_update',
            'description' => 'Update fields of an existing Jeedom scenario. Only provided fields are modified.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'scenario_id' => ['type' => 'integer', 'description' => 'Scenario ID obtained from scenarios_list.'],
                    'name'        => ['type' => 'string',  'description' => 'New display name.'],
                    'mode'        => ['type' => 'string',  'description' => 'Execution mode: schedule, provoke, or always.'],
                    'schedule'    => ['type' => 'string',  'description' => 'Cron expression (for schedule mode).'],
                    'trigger'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'List of trigger conditions (for provoke mode).'],
                    'is_active'   => ['type' => 'boolean', 'description' => 'Enable or disable the scenario.'],
                    'description' => ['type' => 'string',  'description' => 'Description of the scenario\'s purpose.'],
                ],
                'required' => ['scenario_id'],
            ],
        ],
        [
            'name'        => 'plugins_list',
            'description' => 'List all installed Jeedom plugins with their version and active state.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
        [
            'name'        => 'plugin_market_list',
            'description' => 'Search plugins on the Jeedom Market. Returns a paginated list of available plugins.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'search'        => ['type' => 'string',  'description' => 'Filter by plugin name.'],
                    'category'      => ['type' => 'string',  'description' => 'Filter by category (e.g. automation, security, energy).'],
                    'certification' => ['type' => 'string',  'description' => 'Filter by certification level.', 'enum' => ['Officiel', 'Conseillé', 'Premium', 'Partenaire', 'Legacy']],
                    'cost'          => ['type' => 'string',  'description' => 'Filter by cost.', 'enum' => ['free', 'paying']],
                    'channel'       => ['type' => 'string',  'description' => 'Release channel to search (default: stable).', 'enum' => ['stable', 'beta']],
                    'limit'         => ['type' => 'integer', 'description' => 'Maximum number of results (default 20). Use 0 for no limit.'],
                    'offset'        => ['type' => 'integer', 'description' => 'Number of results to skip (default 0).'],
                ],
                'required' => [],
            ],
        ],
        [
            'name'        => 'plugin_install',
            'description' => 'Install or update a plugin from the Jeedom Market.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'plugin_id' => ['type' => 'string', 'description' => 'Plugin identifier on the Jeedom Market.'],
                    'version'   => ['type' => 'string', 'description' => 'Version channel to install: stable (default) or beta.', 'enum' => ['stable', 'beta']],
                ],
                'required' => ['plugin_id'],
            ],
        ],
        [
            'name'        => 'plugin_get_config',
            'description' => 'Get detailed configuration for an installed plugin: log level, daemon state, dependency state, and plugin-specific config keys with their current values (discovered from plugin_info/configuration.php).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'plugin_id' => ['type' => 'string', 'description' => 'Plugin identifier.'],
                ],
                'required' => ['plugin_id'],
            ],
        ],
        [
            'name'        => 'plugin_set_config',
            'description' => 'Update plugin settings: log level and/or daemon auto-restart mode.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'plugin_id'          => ['type' => 'string',  'description' => 'Plugin identifier.'],
                    'log_level'              => ['type' => 'string',  'description' => 'Log verbosity level.', 'enum' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'default']],
                    'daemon_auto_restart'    => ['type' => 'boolean', 'description' => 'Enable or disable automatic daemon restart.'],
                    'dependency_auto_install' => ['type' => 'boolean', 'description' => 'Enable or disable automatic dependency installation.'],
                ],
                'required' => ['plugin_id'],
            ],
        ],
        [
            'name'        => 'plugin_set_plugin_config',
            'description' => 'Set plugin-specific configuration values (e.g. Meross login/password, API keys). Use plugin_get_config first to discover available keys.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'plugin_id' => ['type' => 'string', 'description' => 'Plugin identifier.'],
                    'config'    => ['type' => 'object', 'description' => 'Key/value pairs to save. Keys are validated against those declared in the plugin configuration page.'],
                ],
                'required' => ['plugin_id', 'config'],
            ],
        ],
        [
            'name'        => 'plugin_dependency_install',
            'description' => 'Trigger dependency installation for a plugin. Installation runs in background; use plugin_get_config to monitor progress.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'plugin_id' => ['type' => 'string', 'description' => 'Plugin identifier.'],
                ],
                'required' => ['plugin_id'],
            ],
        ],
        [
            'name'        => 'plugin_daemon_action',
            'description' => 'Start, stop or restart a plugin daemon.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'plugin_id' => ['type' => 'string', 'description' => 'Plugin identifier.'],
                    'action'    => ['type' => 'string', 'description' => 'Action to perform.', 'enum' => ['start', 'stop', 'restart']],
                ],
                'required' => ['plugin_id', 'action'],
            ],
        ],
        [
            'name'        => 'plugin_uninstall',
            'description' => 'Uninstall a Jeedom plugin. Removes all associated devices, configuration and plugin files. This action is irreversible.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'plugin_id' => ['type' => 'string', 'description' => 'Plugin identifier to uninstall.'],
                ],
                'required' => ['plugin_id'],
            ],
        ],
        [
            'name'        => 'plugin_set_active',
            'description' => 'Enable or disable an installed Jeedom plugin.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'plugin_id' => ['type' => 'string',  'description' => 'Plugin identifier.'],
                    'active'    => ['type' => 'boolean', 'description' => 'true to enable, false to disable.'],
                ],
                'required' => ['plugin_id', 'active'],
            ],
        ],
        [
            'name'        => 'logs_list',
            'description' => 'List available Jeedom log files with their size and last modification date.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
        ],
        [
            'name'        => 'log_read',
            'description' => 'Read the last N lines of a Jeedom log file. May contain sensitive data (API keys, passwords).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'log'       => ['type' => 'string',  'description' => 'Log file name (from logs_list).'],
                    'lines'     => ['type' => 'integer', 'description' => 'Number of lines to return (default 100).'],
                    'offset'    => ['type' => 'integer', 'description' => 'Number of lines to skip from the end before reading (default 0). Use to paginate backwards.'],
                    'min_level' => ['type' => 'string',  'description' => 'Minimum severity level to include: DEBUG, INFO, WARNING, ERROR, CRITICAL.', 'enum' => ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL']],
                    'search'    => ['type' => 'string',  'description' => 'Case-insensitive string filter — only lines containing this text are returned.'],
                ],
                'required' => ['log'],
            ],
        ],
        [
            'name'        => 'scenario_create',
            'description' => 'Create a new Jeedom scenario.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'name'        => ['type' => 'string',  'description' => 'Display name of the scenario.'],
                    'mode'        => ['type' => 'string',  'description' => 'Execution mode: schedule (cron-based), provoke (trigger-based), or always.'],
                    'schedule'    => ['type' => 'string',  'description' => 'Cron expression, required when mode is schedule.'],
                    'trigger'     => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'List of trigger conditions, used when mode is provoke.'],
                    'is_active'   => ['type' => 'boolean', 'description' => 'Whether the scenario is active (default: true).'],
                    'description' => ['type' => 'string',  'description' => 'Optional description of the scenario\'s purpose.'],
                ],
                'required' => ['name', 'mode'],
            ],
        ],
    ];

    // Append extension tools from active plugins (served from cache)
    $ext = ext_cache_get();
    foreach ($ext['tools'] as $tool) {
        $pluginId = $ext['routing'][$tool['name']] ?? null;
        if ($pluginId && acl_allowed("ext_{$pluginId}", 'execution')) {
            $tools[] = $tool;
        }
    }

    return $tools;
}

// ---------------------------------------------------------------------------
// Tool dispatcher
// ---------------------------------------------------------------------------

function mcp_call_tool(string $name, array $args): array {
    try {
        switch ($name) {
            case 'acl_list':            return tool_result(tool_acl_list());
            case 'devices_list':        return tool_result(tool_devices_list($args['categories'] ?? null, $args['room_ids'] ?? null, intval($args['limit'] ?? 50), intval($args['offset'] ?? 0), isset($args['include_state']) ? (bool)$args['include_state'] : true, isset($args['include_actions']) ? (bool)$args['include_actions'] : true, isset($args['include_hidden']) ? (bool)$args['include_hidden'] : false, isset($args['include_historical']) ? (bool)$args['include_historical'] : false));
            case 'device_set_description': return tool_result(tool_device_set_description((int)($args['equipment_id'] ?? 0), (string)($args['description'] ?? '')));
            case 'device_update':       return tool_result(tool_device_update((int)($args['equipment_id'] ?? 0), $args));
            case 'device_get_history':  return tool_result(tool_device_get_history($args['command_id'] ?? null, $args['equipment_id'] ?? null, $args['command_name'] ?? null, $args['start'] ?? null, $args['end'] ?? null, $args['aggregate'] ?? 'stats', $args['group_by'] ?? 'day'));
            case 'devices_states':      return tool_result(tool_devices_states($args['equipment_ids'] ?? null, $args['categories'] ?? null, $args['room_ids'] ?? null));
            case 'command_execute':     return tool_result(tool_command_execute($args['commands'] ?? []));
            case 'rooms_list':          return tool_result(tool_rooms_list(intval($args['limit'] ?? 50), intval($args['offset'] ?? 0)));
            case 'room_set_description': return tool_result(tool_room_set_description((int)($args['room_id'] ?? 0), (string)($args['description'] ?? '')));
            case 'room_create':         return tool_result(tool_room_create((string)($args['name'] ?? ''), $args['description'] ?? null, $args['surface'] ?? null, isset($args['orientation']) ? (int)$args['orientation'] : null, isset($args['parent_id']) ? (int)$args['parent_id'] : null));
            case 'room_update':         return tool_result(tool_room_update((int)($args['room_id'] ?? 0), $args));
            case 'room_delete':         return tool_result(tool_room_delete((int)($args['room_id'] ?? 0)));
            case 'scenarios_list':      return tool_result(tool_scenarios_list(intval($args['limit'] ?? 50), intval($args['offset'] ?? 0)));
            case 'scenario_run':        return tool_result(tool_scenario_run((int)($args['scenario_id'] ?? 0)));
            case 'scenario_delete':     return tool_result(tool_scenario_delete((int)($args['scenario_id'] ?? 0)));
            case 'scenario_get_actions': return tool_result(tool_scenario_get_actions((int)($args['scenario_id'] ?? 0)));
            case 'scenario_set_actions': return tool_result(tool_scenario_set_actions((int)($args['scenario_id'] ?? 0), $args['elements'] ?? []));
            case 'scenario_set_description': return tool_result(tool_scenario_set_description((int)($args['scenario_id'] ?? 0), (string)($args['description'] ?? '')));
            case 'scenario_update':     return tool_result(tool_scenario_update($args));
            case 'scenario_create':     return tool_result(tool_scenario_create($args));
            case 'plugins_list':        return tool_result(tool_plugins_list());
            case 'plugin_get_config':   return tool_result(tool_plugin_get_config((string)($args['plugin_id'] ?? '')));
            case 'plugin_set_plugin_config': return tool_result(tool_plugin_set_plugin_config((string)($args['plugin_id'] ?? ''), (array)($args['config'] ?? [])));
            case 'plugin_set_config':   return tool_result(tool_plugin_set_config((string)($args['plugin_id'] ?? ''), $args['log_level'] ?? null, isset($args['daemon_auto_restart']) ? (bool)$args['daemon_auto_restart'] : null, isset($args['dependency_auto_install']) ? (bool)$args['dependency_auto_install'] : null));
            case 'plugin_market_list': return tool_result(tool_plugin_market_list($args['search'] ?? null, $args['category'] ?? null, $args['certification'] ?? null, $args['cost'] ?? null, $args['channel'] ?? 'stable', intval($args['limit'] ?? 20), intval($args['offset'] ?? 0)));
            case 'plugin_install':      return tool_result(tool_plugin_install((string)($args['plugin_id'] ?? ''), (string)($args['version'] ?? 'stable')));
            case 'plugin_uninstall':    return tool_result(tool_plugin_uninstall((string)($args['plugin_id'] ?? '')));
            case 'plugin_set_active':   return tool_result(tool_plugin_set_active((string)($args['plugin_id'] ?? ''), (bool)($args['active'] ?? true)));
            case 'plugin_dependency_install': return tool_result(tool_plugin_dependency_install((string)($args['plugin_id'] ?? '')));
            case 'plugin_daemon_action': return tool_result(tool_plugin_daemon_action((string)($args['plugin_id'] ?? ''), (string)($args['action'] ?? '')));
            case 'logs_list':           return tool_result(tool_logs_list());
            case 'log_read':            return tool_result(tool_log_read((string)($args['log'] ?? ''), intval($args['lines'] ?? 100), intval($args['offset'] ?? 0), $args['min_level'] ?? null, $args['search'] ?? null));
            default:
                if (strncmp($name, 'ext_', 4) === 0) return ext_call_tool($name, $args);
                return tool_error('Unknown tool: ' . $name);
        }
    } catch (Exception $e) {
        return tool_error($e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Extension tool routing
// ---------------------------------------------------------------------------

function ext_call_tool(string $name, array $args): array {
    try {
        $cache = ext_cache_get();
        if (!isset($cache['routing'][$name])) {
            // Cache may be stale — force rebuild and retry once
            $cache = ext_cache_build();
            if (!isset($cache['routing'][$name])) {
                return tool_error("Unknown extension tool: {$name}");
            }
        }
        $pluginId = $cache['routing'][$name];
        acl_check("ext_{$pluginId}", 'execution');
        $file = __DIR__ . "/../../{$pluginId}/mcp/McpExtension.php";
        if (!file_exists($file)) return tool_error("McpExtension.php not found for plugin '{$pluginId}'");
        require_once $file;
        $class = ucfirst($pluginId) . 'McpExtension';
        if (!class_exists($class) || !method_exists($class, 'callTool')) {
            return tool_error("Class {$class} does not implement callTool()");
        }
        $unprefixed = substr($name, strlen("ext_{$pluginId}_"));
        return $class::callTool($unprefixed, $args);
    } catch (Exception $e) {
        return tool_error($e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Tool implementations
// ---------------------------------------------------------------------------

function fmt_scenario(object $s): array {
    $trigger = $s->getTrigger();
    if (is_string($trigger)) {
        $decoded = json_decode($trigger, true);
        $trigger = is_array($decoded) ? $decoded : [$trigger];
    }
    $trigger = array_values(array_filter((array)$trigger));

    return [
        'id'          => intval($s->getId()),
        'name'        => $s->getName() ?? '',
        'group'       => $s->getGroup() ?: null,
        'description' => $s->getDescription() ?: null,
        'is_active'   => $s->getIsActive() == 1,
        'state'       => $s->getState() ?: null,
        'mode'        => $s->getMode() ?? '',
        'schedule'    => $s->getSchedule() ?: null,
        'trigger'     => $trigger ?: null,
        'last_launch' => $s->getLastLaunch() ?: null,
    ];
}

function active_categories($raw): array {
    if (!is_array($raw)) return [];
    return array_keys(array_filter($raw, function($v) { return $v == 1; }));
}

function cast_info_value(string $subType, $raw) {
    if ($raw === null || $raw === '') return null;
    switch ($subType) {
        case 'binary':  return $raw == 1;
        case 'numeric': return is_numeric($raw) ? floatval($raw) : null;
        default:        return (string)$raw;
    }
}

function fmt_state_map(array $cmds): object {
    $state = [];
    foreach ($cmds as $cmd) {
        if ($cmd->getType() !== 'info') continue;
        $name  = $cmd->getName() ?? '';
        $value = cast_info_value($cmd->getSubType() ?? '', $cmd->getCache('value'));
        if ($value === null) continue;
        if (array_key_exists($name, $state)) {
            $suffixed = $name . '_' . intval($cmd->getId());
            log::add('jeedomMCP', 'warning', "Duplicate info command name '{$name}' on equipment {$cmd->getEqLogic_id()} — using '{$suffixed}'");
            $state[$suffixed] = $value;
        } else {
            $state[$name] = $value;
        }
    }
    return (object)$state;
}

function fmt_actions(array $cmds): array {
    $actions = [];
    foreach ($cmds as $cmd) {
        if ($cmd->getType() !== 'action') continue;
        $actions[] = [
            'id'      => intval($cmd->getId()),
            'name'    => $cmd->getName() ?? '',
            'subType' => $cmd->getSubType() ?? 'other',
        ];
    }
    return $actions;
}

function tool_acl_list(): array {
    $mode = config::byKey('acl_mode', 'jeedomMCP', 'read_execute');

    // tool => [domain, operation]
    $tool_map = [
        'devices_list'             => ['devices',    'read'],
        'devices_states'           => ['devices',    'read'],
        'device_get_history'              => ['devices',    'read'],
        'command_execute'          => ['devices',    'execution'],
        'device_set_description'   => ['devices',    'set_description'],
        'device_update'            => ['devices',    'update'],
        'rooms_list'               => ['rooms',      'read'],
        'room_set_description'     => ['rooms',      'set_description'],
        'room_create'              => ['rooms',      'create'],
        'room_update'              => ['rooms',      'update'],
        'room_delete'              => ['rooms',      'delete'],
        'scenarios_list'           => ['scenarios',  'read'],
        'scenario_get_actions'     => ['scenarios',  'read'],
        'scenario_run'             => ['scenarios',  'execution'],
        'scenario_set_description' => ['scenarios',  'set_description'],
        'scenario_create'          => ['scenarios',  'create'],
        'scenario_update'          => ['scenarios',  'update'],
        'scenario_set_actions'     => ['scenarios',  'update'],
        'scenario_delete'          => ['scenarios',  'delete'],
        'plugins_list'             => ['admin_plugins', 'read'],
        'plugin_get_config'        => ['admin_plugins', 'read'],
        'plugin_market_list'       => ['admin_plugins', 'read'],
        'plugin_install'           => ['admin_plugins', 'create'],
        'plugin_uninstall'         => ['admin_plugins', 'delete'],
        'plugin_set_active'        => ['admin_plugins', 'update'],
        'plugin_set_config'        => ['admin_plugins', 'update'],
        'plugin_set_plugin_config'  => ['admin_plugins', 'update'],
        'plugin_dependency_install' => ['admin_plugins', 'execution'],
        'plugin_daemon_action'     => ['admin_plugins', 'execution'],
        'logs_list'                => ['admin_logs',    'read'],
        'log_read'                 => ['admin_logs',    'read'],
    ];

    $authorized = ['acl_list']; // always accessible
    foreach ($tool_map as $tool => $domain_op) {
        if (acl_allowed($domain_op[0], $domain_op[1])) $authorized[] = $tool;
    }

    // Add authorized extension tools
    $ext = ext_cache_get();
    foreach ($ext['routing'] as $toolName => $pluginId) {
        if (acl_allowed("ext_{$pluginId}", 'execution')) $authorized[] = $toolName;
    }

    return ['mode' => $mode, 'authorized_tools' => $authorized];
}

function tool_devices_list(?array $categories = null, ?array $room_ids = null, int $limit = 50, int $offset = 0, bool $include_state = true, bool $include_actions = true, bool $include_hidden = false, bool $include_historical = false): array {
    acl_check('devices', 'read');

    $room_ids_int = null;
    if (!empty($room_ids)) {
        $room_ids_int = array_map('intval', $room_ids);
    }

    $commands_by_eq = [];
    if ($include_state || $include_actions || $include_historical) {
        foreach (cmd::all() as $cmd) {
            $commands_by_eq[$cmd->getEqLogic_id()][] = $cmd;
        }
    }

    $all = [];
    foreach (eqLogic::all() as $eq) {
        if ($eq->getIsEnable() != 1) continue;
        if (!$include_hidden && $eq->getIsVisible() != 1) continue;
        $eq_cats = active_categories($eq->getCategory());
        if (!empty($categories) && empty(array_intersect($categories, $eq_cats))) continue;
        if ($room_ids_int !== null && !in_array(intval($eq->getObject_id()), $room_ids_int, true)) continue;
        $item = ['id' => intval($eq->getId()), 'name' => $eq->getName() ?? ''];
        if ($eq->getComment())      $item['description'] = $eq->getComment();
        if ($eq->getObject_id())    $item['room_id']     = intval($eq->getObject_id());
        if (!empty($eq_cats))       $item['categories']  = $eq_cats;
        if ($eq->getIsVisible() != 1) $item['is_visible'] = false;
        $cmds = $commands_by_eq[$eq->getId()] ?? [];
        if ($include_state)   $item['state']   = fmt_state_map($cmds);
        if ($include_actions) $item['actions']  = fmt_actions($cmds);
        if ($include_historical) {
            $hist = [];
            foreach ($cmds as $cmd) {
                if ($cmd->getType() === 'info' && $cmd->getIsHistorized()) {
                    $hist[] = $cmd->getName() ?? '';
                }
            }
            if (!empty($hist)) $item['historical'] = $hist;
        }
        $all[] = $item;
    }

    $total = count($all);
    $items = $limit > 0 ? array_slice($all, $offset, $limit) : array_slice($all, $offset);
    return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => $items];
}


function fmt_equipment(eqLogic $eq): array {
    $item = ['id' => intval($eq->getId()), 'name' => $eq->getName() ?? ''];
    if ($eq->getComment())                        $item['description'] = $eq->getComment();
    if ($eq->getObject_id())                      $item['room_id']     = intval($eq->getObject_id());
    $cats = active_categories($eq->getCategory());
    if (!empty($cats))                            $item['categories']  = $cats;
    if ($eq->getIsVisible() != 1)                 $item['is_visible']  = false;
    return $item;
}

function tool_device_set_description(int $equipment_id, string $description): array {
    acl_check('devices', 'set_description');
    $eq = eqLogic::byId($equipment_id);
    if (!is_object($eq)) throw new Exception("Equipment {$equipment_id} not found");
    $eq->setComment($description);
    $eq->save();
    return fmt_equipment($eq);
}

function tool_device_update(int $equipment_id, array $args): array {
    acl_check('devices', 'update');
    if ($equipment_id === 0) throw new Exception('equipment_id is required');
    $eq = eqLogic::byId($equipment_id);
    if (!is_object($eq)) throw new Exception("Equipment {$equipment_id} not found");
    $valid_categories = ['heating', 'security', 'energy', 'light', 'opening', 'automatism', 'multimedia', 'default'];
    if (array_key_exists('name', $args)) {
        if ((string)$args['name'] === '') throw new Exception('name cannot be empty');
        $eq->setName((string)$args['name']);
    }
    if (array_key_exists('room_id', $args)) {
        $room_id = (int)$args['room_id'];
        if ($room_id !== 0 && !is_object(jeeObject::byId($room_id))) throw new Exception("Room {$room_id} not found");
        $eq->setObject_id($room_id ?: null);
    }
    if (array_key_exists('categories', $args)) {
        $cats = (array)$args['categories'];
        foreach ($cats as $c) {
            if (!in_array($c, $valid_categories, true)) throw new Exception("Invalid category: {$c}");
        }
        foreach ($valid_categories as $key) {
            $eq->setCategory($key, in_array($key, $cats, true) ? 1 : 0);
        }
    }
    $eq->save();
    return fmt_equipment($eq);
}

function tool_devices_states(?array $equipment_ids, ?array $categories, ?array $room_ids): array {
    acl_check('devices', 'read');
    if ($equipment_ids === null && $categories === null && $room_ids === null) {
        throw new Exception('Provide at least one of: equipment_ids, categories, room_ids');
    }

    $eq_map = [];
    foreach (eqLogic::all() as $eq) {
        $id = (int)$eq->getId();
        if ($equipment_ids !== null && !in_array($id, $equipment_ids, true)) continue;
        if ($categories !== null) {
            $match = false;
            foreach ($categories as $cat) { if ($eq->getCategory($cat)) { $match = true; break; } }
            if (!$match) continue;
        }
        if ($room_ids !== null && !in_array((int)$eq->getObject_id(), $room_ids, true)) continue;
        $eq_map[$id] = $eq;
    }

    if ($equipment_ids !== null) {
        $missing = array_diff($equipment_ids, array_keys($eq_map));
        if (!empty($missing)) throw new Exception('Equipment not found: ' . implode(', ', $missing));
    }

    $commands_by_eq = [];
    foreach (cmd::all() as $cmd) {
        $eq_id = (int)$cmd->getEqLogic_id();
        if (isset($eq_map[$eq_id])) $commands_by_eq[$eq_id][] = $cmd;
    }

    $result = [];
    foreach ($eq_map as $id => $eq) {
        $result[] = ['id' => $id, 'state' => fmt_state_map($commands_by_eq[$id] ?? [])];
    }
    return $result;
}

function tool_device_get_history($raw_command_id, $raw_equipment_id, $raw_command_name, ?string $start, ?string $end, string $aggregate, string $group_by): array {
    acl_check('devices', 'read');

    if ($raw_command_id !== null) {
        $cmd = cmd::byId((int)$raw_command_id);
        if (!is_object($cmd)) throw new Exception("Command {$raw_command_id} not found");
        if (!$cmd->getIsHistorized()) throw new Exception("Command {$raw_command_id} is not historized");
    } elseif ($raw_equipment_id !== null && $raw_command_name !== null) {
        $eq = eqLogic::byId((int)$raw_equipment_id);
        if (!is_object($eq)) throw new Exception("Equipment {$raw_equipment_id} not found");
        $cmd = null;
        foreach ($eq->getCmd('info') as $c) {
            if ($c->getName() === (string)$raw_command_name) { $cmd = $c; break; }
        }
        if (!is_object($cmd)) throw new Exception("Command '{$raw_command_name}' not found on equipment {$raw_equipment_id}");
        if (!$cmd->getIsHistorized()) throw new Exception("Command '{$raw_command_name}' is not historized");
    } else {
        throw new Exception('Provide either command_id, or both equipment_id and command_name');
    }

    $command_id = intval($cmd->getId());
    $start_dt = $start ? date('Y-m-d H:i:s', strtotime($start)) : date('Y-m-d H:i:s', strtotime('-7 days'));
    $end_dt   = $end   ? date('Y-m-d H:i:s', strtotime($end))   : date('Y-m-d H:i:s');

    $base = [
        'command_id'   => $command_id,
        'command_name' => $cmd->getName(),
        'unit'       => $cmd->getUnite() ?: null,
        'start'      => $start_dt,
        'end'        => $end_dt,
    ];

    if ($aggregate === 'stats') {
        $stats = history::getStatistique($command_id, $start_dt, $end_dt);
        return array_merge($base, [
            'aggregate' => 'stats',
            'stats' => [
                'avg'      => isset($stats['avg'])   ? round((float)$stats['avg'],   4) : null,
                'min'      => isset($stats['min'])   ? round((float)$stats['min'],   4) : null,
                'max'      => isset($stats['max'])   ? round((float)$stats['max'],   4) : null,
                'sum'      => isset($stats['sum'])   ? round((float)$stats['sum'],   4) : null,
                'count'    => isset($stats['count']) ? (int)$stats['count']              : 0,
                'last'     => $stats['last'] ?? null,
            ],
        ]);
    }

    if ($aggregate === 'raw') {
        $rows = history::all($command_id, $start_dt, $end_dt);
        $points = [];
        foreach ($rows as $row) {
            $points[] = ['datetime' => $row->getDatetime(), 'value' => $row->getValue()];
        }
        return array_merge($base, ['aggregate' => 'raw', 'count' => count($points), 'points' => $points]);
    }

    // Time-series aggregation: avg, min, max, sum grouped by hour or day
    $group_by = in_array($group_by, ['hour', 'day'], true) ? $group_by : 'day';
    $groupingType = $aggregate . '::' . $group_by;
    $rows = history::all($command_id, $start_dt, $end_dt, $groupingType);
    $points = [];
    foreach ($rows as $row) {
        $points[] = ['datetime' => $row->getDatetime(), 'value' => $row->getValue()];
    }
    return array_merge($base, ['aggregate' => $aggregate, 'group_by' => $group_by, 'count' => count($points), 'points' => $points]);
}

function tool_command_execute(array $commands): array {
    acl_check('devices', 'execution');
    if (empty($commands)) throw new Exception('commands is required');

    $affected_eq = [];
    foreach ($commands as $entry) {
        $ids     = isset($entry['ids']) ? (array)$entry['ids'] : [(int)($entry['id'] ?? 0)];
        $value   = $entry['value'] ?? null;
        $options = ($value !== null) ? ['slider' => $value] : [];
        foreach ($ids as $command_id) {
            $cmd = cmd::byId($command_id);
            if (!is_object($cmd)) throw new Exception("Command {$command_id} not found");
            $cmd->execCmd($options);
            $eq_id = intval($cmd->getEqLogic_id());
            if (!isset($affected_eq[$eq_id])) $affected_eq[$eq_id] = true;
        }
    }

    $result = [];
    foreach (array_keys($affected_eq) as $eq_id) {
        $cmds     = cmd::byEqLogicId($eq_id);
        $result[] = ['id' => $eq_id, 'state' => fmt_state_map($cmds)];
    }
    return $result;
}

function tool_rooms_list(int $limit = 50, int $offset = 0): array {
    acl_check('rooms', 'read');
    $all = [];
    foreach (jeeObject::all() as $obj) {
        $all[] = fmt_room($obj);
    }
    $total = count($all);
    $items = $limit > 0 ? array_slice($all, $offset, $limit) : array_slice($all, $offset);
    return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => $items];
}

function tool_room_create(string $name, ?string $description, ?string $surface, ?int $orientation, ?int $parent_id): array {
    acl_check('rooms', 'create');
    if ($parent_id !== null) {
        $parent = jeeObject::byId($parent_id);
        if (!is_object($parent)) throw new Exception("Parent room {$parent_id} not found");
    }
    $obj = new jeeObject();
    $obj->setName($name);
    if ($description !== null) $obj->setConfiguration('description', $description);
    if ($surface !== null)     $obj->setConfiguration('info::space', $surface);
    if ($orientation !== null) $obj->setConfiguration('info::orientation', (string)$orientation);
    if ($parent_id !== null)   $obj->setFather_id($parent_id);
    $obj->save();
    if (!$obj->getId()) {
        return ['error' => 'Room creation failed: no ID returned'];
    }
    return fmt_room($obj);
}

function tool_room_update(int $room_id, array $args): array {
    acl_check('rooms', 'update');
    $obj = jeeObject::byId($room_id);
    if (!is_object($obj)) {
        return ['error' => "Room {$room_id} not found"];
    }
    if (isset($args['name']))        $obj->setName($args['name']);
    if (isset($args['description'])) $obj->setConfiguration('description', $args['description']);
    if (isset($args['surface']))     $obj->setConfiguration('info::space', (string)$args['surface']);
    if (array_key_exists('orientation', $args)) {
        $obj->setConfiguration('info::orientation', $args['orientation'] === null ? '' : (string)$args['orientation']);
    }
    if (array_key_exists('parent_id', $args)) {
        $new_parent_id = (int)$args['parent_id'];
        if ($new_parent_id !== 0) {
            $parent = jeeObject::byId($new_parent_id);
            if (!is_object($parent)) throw new Exception("Parent room {$new_parent_id} not found");
        }
        $obj->setFather_id($new_parent_id === 0 ? null : $new_parent_id);
    }
    if (array_key_exists('icon', $args)) {
        $icon_html = ($args['icon'] === '' || $args['icon'] === null) ? '' : '<i class="' . $args['icon'] . '"></i>';
        $obj->setDisplay('icon', $icon_html);
    }
    $obj->save();
    return fmt_room($obj);
}

function tool_room_delete(int $room_id): array {
    acl_check('rooms', 'delete');
    $obj = jeeObject::byId($room_id);
    if (!is_object($obj)) {
        return ['error' => "Room {$room_id} not found"];
    }
    $obj->remove();
    return ['success' => true, 'room_id' => $room_id];
}

function extract_icon(string $html): ?string {
    if (preg_match('/<i\s+class="([^"]+)"\s*><\/i>/', $html, $m)) return $m[1];
    return null;
}

function fmt_room(jeeObject $obj): array {
    $orientation_raw = $obj->getConfiguration('info::orientation');
    return [
        'id'          => intval($obj->getId()),
        'name'        => $obj->getName() ?? '',
        'description' => $obj->getConfiguration('description') ?: null,
        'icon'        => extract_icon($obj->getDisplay('icon') ?? ''),
        'surface'     => $obj->getConfiguration('info::space') ?: null,
        'orientation' => ($orientation_raw !== '' && $orientation_raw !== null) ? intval($orientation_raw) : null,
        'parent_id'   => $obj->getFather_id() ? intval($obj->getFather_id()) : null,
    ];
}

function tool_room_set_description(int $room_id, string $description): array {
    acl_check('rooms', 'set_description');
    $obj = jeeObject::byId($room_id);
    if (!is_object($obj)) {
        return ['error' => "Room {$room_id} not found"];
    }
    $obj->setConfiguration('description', $description);
    $obj->save();
    return fmt_room($obj);
}

function tool_scenarios_list(int $limit = 50, int $offset = 0): array {
    acl_check('scenarios', 'read');
    $all = [];
    foreach (scenario::all() as $s) {
        $all[] = fmt_scenario($s);
    }
    $total = count($all);
    $items = $limit > 0 ? array_slice($all, $offset, $limit) : array_slice($all, $offset);
    return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => $items];
}

function tool_scenario_run(int $scenario_id): array {
    acl_check('scenarios', 'execution');
    $s = scenario::byId($scenario_id);
    if (!is_object($s)) {
        return ['error' => "Scenario {$scenario_id} not found"];
    }
    $s->launch();
    return ['success' => true, 'scenario_id' => $scenario_id];
}

function tool_scenario_delete(int $scenario_id): array {
    acl_check('scenarios', 'delete');
    $s = scenario::byId($scenario_id);
    if (!is_object($s)) {
        return ['error' => "Scenario {$scenario_id} not found"];
    }
    $s->remove();
    return ['success' => true, 'scenario_id' => $scenario_id];
}

function tool_scenario_get_actions(int $scenario_id): array {
    acl_check('scenarios', 'read');
    $s = scenario::byId($scenario_id);
    if (!is_object($s)) {
        return ['error' => "Scenario {$scenario_id} not found"];
    }
    $export = $s->export('array');
    if (!is_array($export)) {
        return ['error' => "Could not export scenario {$scenario_id}"];
    }
    return ['scenario_id' => $scenario_id, 'elements' => $export['elements'] ?? []];
}

function tool_scenario_set_actions(int $scenario_id, array $elements): array {
    acl_check('scenarios', 'update');
    $s = scenario::byId($scenario_id);
    if (!is_object($s)) {
        return ['error' => "Scenario {$scenario_id} not found"];
    }
    $element_list = [];
    foreach ($elements as $element_ajax) {
        $element_list[] = scenarioElement::saveAjaxElement($element_ajax);
    }
    $s->setScenarioElement($element_list);
    $s->save();
    return fmt_scenario($s);
}

function tool_scenario_set_description(int $scenario_id, string $description): array {
    acl_check('scenarios', 'set_description');
    $s = scenario::byId($scenario_id);
    if (!is_object($s)) {
        return ['error' => "Scenario {$scenario_id} not found"];
    }
    $s->setDescription($description);
    $s->save();
    return fmt_scenario($s);
}

function tool_scenario_update(array $args): array {
    acl_check('scenarios', 'update');
    $scenario_id = (int)($args['scenario_id'] ?? 0);
    $s = scenario::byId($scenario_id);
    if (!is_object($s)) {
        return ['error' => "Scenario {$scenario_id} not found"];
    }
    if (isset($args['name']))        $s->setName($args['name']);
    if (isset($args['mode']))        $s->setMode($args['mode']);
    if (isset($args['schedule']))    $s->setSchedule($args['schedule']);
    if (isset($args['trigger']))     $s->setTrigger($args['trigger']);
    if (isset($args['is_active']))   $s->setIsActive($args['is_active'] ? 1 : 0);
    if (isset($args['description'])) $s->setDescription($args['description']);
    $s->save();
    return fmt_scenario($s);
}

function tool_scenario_create(array $args): array {
    acl_check('scenarios', 'create');
    $name = (string)($args['name'] ?? '');
    $mode = (string)($args['mode'] ?? '');
    if ($name === '' || $mode === '') {
        return ['error' => 'name and mode are required'];
    }

    $s = new scenario();
    $s->setName($name);
    $s->setMode($mode);
    $s->setIsActive(isset($args['is_active']) ? ($args['is_active'] ? 1 : 0) : 1);
    $s->setDescription($args['description'] ?? '');
    if (isset($args['schedule'])) $s->setSchedule($args['schedule']);
    if (isset($args['trigger']))  $s->setTrigger($args['trigger']);
    $s->save();

    if (!$s->getId()) {
        return ['error' => 'Scenario creation failed: no ID returned'];
    }
    return fmt_scenario($s);
}

// ---------------------------------------------------------------------------
// Admin — Plugins
// ---------------------------------------------------------------------------

function tool_plugins_list(): array {
    acl_check('admin_plugins', 'read');
    // Refresh extension cache — plugin list may have changed
    ext_cache_build();
    $result = [];
    foreach (plugin::listPlugin() as $p) {
        $u       = update::byLogicalId($p->getId());
        $version = is_object($u) ? ($u->getLocalVersion() ?: null) : null;
        $item = [
            'id'        => $p->getId(),
            'name'      => $p->getName() ?? '',
            'version'   => $version,
            'is_active' => $p->isActive() == 1,
        ];
        if ($p->getDescription()) $item['description'] = strip_tags($p->getDescription());
        $result[] = $item;
    }
    return $result;
}

function tool_plugin_market_list(?string $search = null, ?string $category = null, ?string $certification = null, ?string $cost = null, string $channel = 'stable', int $limit = 20, int $offset = 0): array {
    acl_check('admin_plugins', 'read');
    $filter = [
        'type'          => 'plugin',
        'status'        => in_array($channel, ['stable', 'beta']) ? $channel : 'stable',
        'name'          => $search ?: null,
        'categorie'     => $category ?: null,
        'certification' => $certification ?: null,
        'cost'          => $cost ?: null,
    ];
    $markets = repo_market::byFilter($filter);
    $total   = count($markets);
    $page    = $limit > 0 ? array_slice($markets, $offset, $limit) : array_slice($markets, $offset);
    $items   = [];
    foreach ($page as $m) {
        $installed = is_object(update::byLogicalId($m->getLogicalId()));
        $item = [
            'id'            => $m->getLogicalId(),
            'name'          => $m->getName(),
            'author'        => $m->getAuthor(),
            'category'      => $m->getCategorie() ?: null,
            'is_free'       => $m->getCost() == 0,
            'cost'          => $m->getCost() > 0 ? floatval($m->getCost()) : null,
            'rating'        => $m->getRating('average'),
            'installed'     => $installed,
        ];
        if ($m->getCertification()) $item['certification'] = $m->getCertification();
        if ($m->getDescription())   $item['description']   = strip_tags($m->getDescription());
        $items[] = $item;
    }
    return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => $items];
}

function plugin_log_level_name(string $plugin_id): string {
    $cfg = config::byKey('log::level::' . $plugin_id, 'core');
    if (!is_array($cfg) || !empty($cfg['default'])) return 'default';
    $map = [100 => 'debug', 200 => 'info', 250 => 'notice', 300 => 'warning', 400 => 'error', 500 => 'critical'];
    foreach ($map as $num => $name) {
        if (!empty($cfg[$num])) return $name;
    }
    return 'default';
}

function tool_plugin_get_config(string $plugin_id): array {
    acl_check('admin_plugins', 'read');
    if ($plugin_id === '') throw new Exception('plugin_id is required');
    $plugin = plugin::byId($plugin_id);
    if (!is_object($plugin)) throw new Exception('Plugin not found: ' . $plugin_id);
    $upd = update::byLogicalId($plugin_id);
    $result = [
        'id'          => $plugin->getId(),
        'name'        => $plugin->getName(),
        'version'     => is_object($upd) ? ($upd->getLocalVersion() ?: null) : null,
        'category'    => $plugin->getCategory() ?: null,
        'active'      => (bool)$plugin->isActive(),
        'log_level'   => plugin_log_level_name($plugin_id),
    ];
    if ($plugin->getHasDependency()) {
        $dep = $plugin->dependancy_info();
        $dependency = [
            'state'        => $dep['state'] ?? 'nok',
            'auto_install' => (bool)$dep['auto'],
            'last_launch'  => $dep['last_launch'] ?? null,
        ];
        if (($dep['state'] ?? '') === 'in_progress') {
            $dependency['progression'] = isset($dep['progression']) ? (int)$dep['progression'] : 0;
            $dependency['duration']    = isset($dep['duration'])    ? (int)$dep['duration']    : 0;
        }
        $result['dependency'] = $dependency;
    }
    if ($plugin->getHasOwnDeamon()) {
        $daemon = $plugin->deamon_info();
        $result['daemon'] = [
            'state'        => $daemon['state'] ?? 'nok',
            'auto_restart' => (bool)$daemon['auto'],
            'launchable'   => ($daemon['launchable'] ?? 'nok') === 'ok',
            'last_launch'  => $daemon['last_launch'] ?? null,
        ];
        if (!empty($daemon['launchable_message'])) {
            $result['daemon']['message'] = $daemon['launchable_message'];
        }
    }

    // Discover plugin-level config keys by parsing plugin_info/configuration.php
    $configFile = dirname(__FILE__) . '/../../../plugins/' . $plugin_id . '/plugin_info/configuration.php';
    if (file_exists($configFile)) {
        $html = file_get_contents($configFile);
        preg_match_all('/data-l1key=["\']([^"\']+)["\']/', $html, $matches);
        $keys = array_values(array_unique($matches[1]));
        if (!empty($keys)) {
            $config = [];
            foreach ($keys as $key) {
                $config[$key] = config::byKey($key, $plugin_id, null);
            }
            $result['config'] = $config;
        }
    }

    return $result;
}

function tool_plugin_set_config(string $plugin_id, ?string $log_level, ?bool $daemon_auto_restart, ?bool $dependency_auto_install = null): array {
    acl_check('admin_plugins', 'update');
    if ($plugin_id === '') throw new Exception('plugin_id is required');
    $plugin = plugin::byId($plugin_id);
    if (!is_object($plugin)) throw new Exception('Plugin not found: ' . $plugin_id);
    $changed = [];
    if ($log_level !== null) {
        $valid = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500];
        if ($log_level === 'default') {
            config::save('log::level::' . $plugin_id, ['default' => 1], 'core');
        } elseif (isset($valid[$log_level])) {
            $cfg = ['default' => 0];
            foreach ($valid as $n => $num) { $cfg[$num] = ($n === $log_level) ? 1 : 0; }
            config::save('log::level::' . $plugin_id, $cfg, 'core');
        } else {
            throw new Exception('Invalid log_level: ' . $log_level);
        }
        $changed['log_level'] = $log_level;
    }
    if ($daemon_auto_restart !== null) {
        if (!$plugin->getHasOwnDeamon()) throw new Exception('Plugin ' . $plugin_id . ' has no daemon');
        $plugin->deamon_changeAutoMode($daemon_auto_restart ? 1 : 0);
        $changed['daemon_auto_restart'] = $daemon_auto_restart;
    }
    if ($dependency_auto_install !== null) {
        if (!$plugin->getHasDependency()) throw new Exception('Plugin ' . $plugin_id . ' has no dependencies');
        $plugin->dependancy_changeAutoMode($dependency_auto_install ? 1 : 0);
        $changed['dependency_auto_install'] = $dependency_auto_install;
    }
    return array_merge(['success' => true, 'plugin_id' => $plugin_id], $changed);
}

function tool_plugin_set_plugin_config(string $plugin_id, array $config): array {
    acl_check('admin_plugins', 'update');
    if ($plugin_id === '') throw new Exception('plugin_id is required');
    $plugin = plugin::byId($plugin_id);
    if (!is_object($plugin)) throw new Exception('Plugin not found: ' . $plugin_id);
    if (empty($config)) throw new Exception('config must not be empty');

    // Validate keys against those declared in plugin_info/configuration.php
    $configFile = dirname(__FILE__) . '/../../../plugins/' . $plugin_id . '/plugin_info/configuration.php';
    if (file_exists($configFile)) {
        $html = file_get_contents($configFile);
        preg_match_all('/data-l1key=["\']([^"\']+)["\']/', $html, $matches);
        $valid_keys = array_unique($matches[1]);
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $valid_keys, true)) throw new Exception("Unknown config key '{$key}' for plugin {$plugin_id}");
        }
    }

    foreach ($config as $key => $value) {
        config::save($key, $value, $plugin_id);
    }
    return ['success' => true, 'plugin_id' => $plugin_id, 'updated' => array_keys($config)];
}

function tool_plugin_install(string $plugin_id, string $version = 'stable'): array {
    acl_check('admin_plugins', 'create');
    if ($plugin_id === '') throw new Exception('plugin_id is required');
    if (!in_array($version, ['stable', 'beta'], true)) $version = 'stable';
    $u = update::byLogicalId($plugin_id);
    if (!is_object($u)) {
        $u = new update();
        $u->setLogicalId($plugin_id);
        $u->setType('plugin');
        $u->setSource('market');
        $u->save();
    }
    $u->setConfiguration('version', $version);
    $u->save();
    $u->doUpdate();
    $plugin = plugin::byId($plugin_id);
    return [
        'success'   => true,
        'plugin_id' => $plugin_id,
        'channel'   => $version,
        'version'   => $u->getLocalVersion() ?: null,
    ];
}

function tool_plugin_uninstall(string $plugin_id): array {
    acl_check('admin_plugins', 'delete');
    if ($plugin_id === '') throw new Exception('plugin_id is required');
    $u = update::byLogicalId($plugin_id);
    if (!is_object($u)) throw new Exception('Plugin not found: ' . $plugin_id);
    if ($u->getType() === 'core') throw new Exception('Cannot uninstall the Jeedom core');
    $u->deleteObjet();
    return ['success' => true, 'plugin_id' => $plugin_id];
}

function tool_plugin_dependency_install(string $plugin_id): array {
    acl_check('admin_plugins', 'execution');
    if ($plugin_id === '') throw new Exception('plugin_id is required');
    $plugin = plugin::byId($plugin_id);
    if (!is_object($plugin)) throw new Exception('Plugin not found: ' . $plugin_id);
    if (!$plugin->getHasDependency()) throw new Exception('Plugin ' . $plugin_id . ' has no dependencies');
    $plugin->dependancy_install(true);
    return ['success' => true, 'plugin_id' => $plugin_id, 'state' => 'in_progress'];
}

function tool_plugin_daemon_action(string $plugin_id, string $action): array {
    acl_check('admin_plugins', 'execution');
    if ($plugin_id === '') throw new Exception('plugin_id is required');
    if (!in_array($action, ['start', 'stop', 'restart'], true)) throw new Exception('action must be start, stop or restart');
    $plugin = plugin::byId($plugin_id);
    if (!is_object($plugin)) throw new Exception('Plugin not found: ' . $plugin_id);
    if (!$plugin->getHasOwnDeamon()) throw new Exception('Plugin ' . $plugin_id . ' has no daemon');
    if ($action === 'stop') {
        $plugin->deamon_stop();
    } else {
        $plugin->deamon_start($action === 'restart');
    }
    $daemon = $plugin->deamon_info();
    return [
        'success'   => true,
        'plugin_id' => $plugin_id,
        'action'    => $action,
        'state'     => $daemon['state'] ?? 'nok',
    ];
}

function tool_plugin_set_active(string $plugin_id, bool $active): array {
    acl_check('admin_plugins', 'update');
    if ($plugin_id === '') throw new Exception('plugin_id is required');
    $plugin = plugin::byId($plugin_id);
    if (!is_object($plugin)) throw new Exception('Plugin not found: ' . $plugin_id);
    $plugin->setIsEnable($active ? 1 : 0);
    // Invalidate extension cache — active plugin set has changed
    ext_cache_invalidate();
    return [
        'success'   => true,
        'plugin_id' => $plugin_id,
        'active'    => $active,
    ];
}

// ---------------------------------------------------------------------------
// Admin — Logs
// ---------------------------------------------------------------------------

$LOG_LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3, 'CRITICAL' => 4];

function log_max_level(string $path): ?string {
    global $LOG_LEVELS;
    $max = -1;
    $max_name = null;
    $handle = fopen($path, 'r');
    if (!$handle) return null;
    while (($line = fgets($handle)) !== false) {
        if (preg_match('/\[(DEBUG|INFO|WARNING|ERROR|CRITICAL)\]/i', $line, $m)) {
            $lvl = strtoupper($m[1]);
            if ($LOG_LEVELS[$lvl] > $max) {
                $max      = $LOG_LEVELS[$lvl];
                $max_name = $lvl;
            }
        }
    }
    fclose($handle);
    return $max_name;
}

function tool_logs_list(): array {
    acl_check('admin_logs', 'read');
    $result = [];
    foreach (log::liste() as $name) {
        $path = log::getPathToLog($name);
        $item = ['name' => $name];
        if (file_exists($path)) {
            $item['size']      = filesize($path);
            $item['modified']  = date('Y-m-d H:i:s', filemtime($path));
            $item['max_level'] = log_max_level($path);
        }
        $result[] = $item;
    }
    return $result;
}

function tool_log_read(string $log, int $lines = 100, int $offset = 0, ?string $min_level = null, ?string $search = null): array {
    global $LOG_LEVELS;
    acl_check('admin_logs', 'read');
    if ($log === '') throw new Exception('log is required');
    $path = log::getPathToLog($log);
    if (!file_exists($path)) throw new Exception("Log '{$log}' not found");
    $min_rank = ($min_level !== null) ? ($LOG_LEVELS[strtoupper($min_level)] ?? 0) : -1;
    $all = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($min_rank >= 0) {
        $all = array_values(array_filter($all, function ($line) use ($min_rank) {
            global $LOG_LEVELS;
            if (preg_match('/\[(DEBUG|INFO|WARNING|ERROR|CRITICAL)\]/i', $line, $m)) {
                return $LOG_LEVELS[strtoupper($m[1])] >= $min_rank;
            }
            return false;
        }));
    }
    if ($search !== null && $search !== '') {
        $all = array_values(array_filter($all, function ($line) use ($search) {
            return stripos($line, $search) !== false;
        }));
    }
    $total = count($all);
    // Paginate from the end: offset skips lines from the end, then take $lines
    $slice = array_values(array_slice($all, -($offset + $lines), $lines));
    return [
        'log'    => $log,
        'total'  => $total,
        'offset' => $offset,
        'lines'  => $slice,
    ];
}
