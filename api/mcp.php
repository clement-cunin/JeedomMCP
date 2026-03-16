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

function acl_check(string $domain, string $operation): void {
    $mode = config::byKey('acl_mode', 'JeedomMCP', 'read_execute');
    switch ($mode) {
        case 'full':
            return;
        case 'read_execute_describe':
            if (in_array($operation, ['read', 'execution', 'set_description'])) return;
            break;
        case 'custom':
            $allowed = config::byKey("acl_{$domain}_{$operation}", 'JeedomMCP', '0');
            if ($allowed == 1) return;
            break;
        case 'read_execute':
        default:
            if (in_array($operation, ['read', 'execution'])) return;
            break;
    }
    throw new Exception("Operation '{$operation}' on '{$domain}' is not authorized");
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

$api_key = config::byKey('mcpApiKey', 'JeedomMCP');
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
                    'include_state'   => ['type' => 'boolean', 'description' => 'Include the state map for each device (default true).'],
                    'include_actions' => ['type' => 'boolean', 'description' => 'Include the actions array for each device (default true).'],
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
            'name'        => 'devices_states',
            'description' => 'Bulk refresh the state of a specific set of equipment. Returns only {id, state} per device — use devices_list for full discovery.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'equipment_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'List of equipment IDs to refresh.',
                    ],
                ],
                'required' => ['equipment_ids'],
            ],
        ],
        [
            'name'        => 'command_execute',
            'description' => 'Execute an action command on a Jeedom equipment.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'command_id' => ['type' => 'integer', 'description' => 'Command ID obtained from devices_list actions.'],
                    'value'      => ['type' => 'string',  'description' => 'Optional value for slider or text commands.'],
                ],
                'required' => ['command_id'],
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
}

// ---------------------------------------------------------------------------
// Tool dispatcher
// ---------------------------------------------------------------------------

function mcp_call_tool(string $name, array $args): array {
    try {
        switch ($name) {
            case 'acl_list':            return tool_result(tool_acl_list());
            case 'devices_list':        return tool_result(tool_devices_list($args['categories'] ?? null, intval($args['limit'] ?? 50), intval($args['offset'] ?? 0), isset($args['include_state']) ? (bool)$args['include_state'] : true, isset($args['include_actions']) ? (bool)$args['include_actions'] : true));
            case 'device_set_description': return tool_result(tool_device_set_description((int)($args['equipment_id'] ?? 0), (string)($args['description'] ?? '')));
            case 'devices_states':      return tool_result(tool_devices_states($args['equipment_ids'] ?? []));
            case 'command_execute':     return tool_result(tool_command_execute((int)($args['command_id'] ?? 0), $args['value'] ?? null));
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
            default:                    return tool_error('Unknown tool: ' . $name);
        }
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

function fmt_state_map(array $cmds): array {
    $state = [];
    foreach ($cmds as $cmd) {
        if ($cmd->getType() !== 'info') continue;
        $name  = $cmd->getName() ?? '';
        $value = cast_info_value($cmd->getSubType() ?? '', $cmd->getCache('value'));
        if (array_key_exists($name, $state)) {
            $suffixed = $name . '_' . intval($cmd->getId());
            log::add('JeedomMCP', 'warning', "Duplicate info command name '{$name}' on equipment {$cmd->getEqLogic_id()} — using '{$suffixed}'");
            $state[$suffixed] = $value;
        } else {
            $state[$name] = $value;
        }
    }
    return $state;
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
    $mode = config::byKey('acl_mode', 'JeedomMCP', 'read_execute');

    $mode_ops = [
        'read_execute'          => ['read', 'execution'],
        'read_execute_describe' => ['read', 'execution', 'set_description'],
        'full'                  => ['read', 'execution', 'set_description', 'create', 'update', 'delete'],
    ];

    // tool => [domain, operation]
    $tool_map = [
        'devices_list'           => ['devices',   'read'],
        'devices_states'         => ['devices',   'read'],
        'command_execute'        => ['devices',   'execution'],
        'device_set_description' => ['devices',   'set_description'],
        'rooms_list'             => ['rooms',     'read'],
        'room_set_description'   => ['rooms',     'set_description'],
        'room_create'            => ['rooms',     'create'],
        'room_update'            => ['rooms',     'update'],
        'room_delete'            => ['rooms',     'delete'],
        'scenarios_list'         => ['scenarios', 'read'],
        'scenario_get_actions'   => ['scenarios', 'read'],
        'scenario_run'           => ['scenarios', 'execution'],
        'scenario_set_description' => ['scenarios', 'set_description'],
        'scenario_create'        => ['scenarios', 'create'],
        'scenario_update'        => ['scenarios', 'update'],
        'scenario_set_actions'   => ['scenarios', 'update'],
        'scenario_delete'        => ['scenarios', 'delete'],
    ];

    $allowed_ops = $mode_ops[$mode] ?? ['read', 'execution'];

    $authorized = ['acl_list']; // always accessible
    foreach ($tool_map as $tool => $domain_op) {
        $domain = $domain_op[0];
        $op     = $domain_op[1];
        if ($mode === 'custom') {
            $ok = config::byKey("acl_{$domain}_{$op}", 'JeedomMCP', '0') == 1;
        } else {
            $ok = in_array($op, $allowed_ops);
        }
        if ($ok) $authorized[] = $tool;
    }

    return ['mode' => $mode, 'authorized_tools' => $authorized];
}

function tool_devices_list(?array $categories = null, int $limit = 50, int $offset = 0, bool $include_state = true, bool $include_actions = true): array {
    acl_check('devices', 'read');
    $object_map = [];
    foreach (jeeObject::all() as $obj) {
        $object_map[$obj->getId()] = $obj->getName();
    }

    $commands_by_eq = [];
    if ($include_state || $include_actions) {
        foreach (cmd::all() as $cmd) {
            $commands_by_eq[$cmd->getEqLogic_id()][] = $cmd;
        }
    }

    $all = [];
    foreach (eqLogic::all() as $eq) {
        if ($eq->getIsEnable() != 1) continue;
        $eq_cats = active_categories($eq->getCategory());
        if (!empty($categories) && empty(array_intersect($categories, $eq_cats))) continue;
        $item = [
            'id'          => intval($eq->getId()),
            'name'        => $eq->getName() ?? '',
            'description' => $eq->getComment() ?: null,
            'object_id'   => $eq->getObject_id() ? intval($eq->getObject_id()) : null,
            'object_name' => $object_map[$eq->getObject_id()] ?? null,
            'categories'  => $eq_cats,
            'is_visible'  => $eq->getIsVisible() == 1,
        ];
        $cmds = $commands_by_eq[$eq->getId()] ?? [];
        if ($include_state)   $item['state']   = fmt_state_map($cmds);
        if ($include_actions) $item['actions']  = fmt_actions($cmds);
        $all[] = $item;
    }

    $total = count($all);
    $items = $limit > 0 ? array_slice($all, $offset, $limit) : array_slice($all, $offset);
    return ['total' => $total, 'offset' => $offset, 'limit' => $limit, 'items' => $items];
}


function fmt_equipment(eqLogic $eq): array {
    return [
        'id'          => intval($eq->getId()),
        'name'        => $eq->getName() ?? '',
        'description' => $eq->getComment() ?: null,
        'object_id'   => $eq->getObject_id() ? intval($eq->getObject_id()) : null,
        'categories'  => active_categories($eq->getCategory()),
        'is_visible'  => $eq->getIsVisible() == 1,
    ];
}

function tool_device_set_description(int $equipment_id, string $description): array {
    acl_check('devices', 'set_description');
    $eq = eqLogic::byId($equipment_id);
    if (!is_object($eq)) throw new Exception("Equipment {$equipment_id} not found");
    $eq->setComment($description);
    $eq->save();
    return fmt_equipment($eq);
}

function tool_devices_states(array $equipment_ids): array {
    acl_check('devices', 'read');
    if (empty($equipment_ids)) throw new Exception('equipment_ids is required');

    $found = [];
    $eq_map = [];
    foreach (eqLogic::all() as $eq) {
        if (in_array((int)$eq->getId(), $equipment_ids)) {
            $found[]                  = (int)$eq->getId();
            $eq_map[$eq->getId()]     = $eq;
        }
    }
    $missing = array_diff($equipment_ids, $found);
    if (!empty($missing)) throw new Exception('Equipment not found: ' . implode(', ', $missing));

    $commands_by_eq = [];
    foreach (cmd::all() as $cmd) {
        $eq_id = $cmd->getEqLogic_id();
        if (isset($eq_map[$eq_id])) $commands_by_eq[$eq_id][] = $cmd;
    }

    $result = [];
    foreach ($equipment_ids as $id) {
        $cmds     = $commands_by_eq[$id] ?? [];
        $result[] = ['id' => $id, 'state' => fmt_state_map($cmds)];
    }
    return $result;
}

function tool_command_execute(int $command_id, ?string $value): array {
    acl_check('devices', 'execution');
    $cmd = cmd::byId($command_id);
    if (!is_object($cmd)) throw new Exception("Command {$command_id} not found");
    $options = ($value !== null) ? ['slider' => $value] : [];
    $cmd->execCmd($options);

    $eq = $cmd->getEqLogic();
    if (!is_object($eq)) throw new Exception("Equipment for command {$command_id} not found");
    $cmds = cmd::byEqLogicId($eq->getId());
    return ['id' => intval($eq->getId()), 'state' => fmt_state_map($cmds)];
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
