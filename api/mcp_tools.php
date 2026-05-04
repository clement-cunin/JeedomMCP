<?php
/**
 * JeedomMCP static tool definitions.
 *
 * This file is the single source of truth for tool schemas.
 * It is loaded by mcp.php (which appends ext_ tools) and consumed directly
 * by Alfred's benchmark MockMCPRegistry for offline testing.
 *
 * Return value: array of MCP tool descriptors (name, description, inputSchema).
 * Note: ext_* tools are NOT included here — they are dynamic and appended at runtime.
 */
return [
    [
        'name'        => 'devices_list',
        'description' => 'List Jeedom equipment with their current state and available actions. By default only enabled devices are returned — use include_inactive=true to also include disabled ones. Use with_plugin_info=true to also get managed_by (plugin name, e.g. "openzwave") and plugin_id (plugin-internal identifier, e.g. the Z-Wave node ID). Use include_state=false or include_actions=false to reduce response size when only metadata is needed.',
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
                'managed_by'         => ['type' => 'string',  'description' => 'Filter by plugin name (e.g. "openzwave"). Only used when with_plugin_info=true.'],
                'with_plugin_info'   => ['type' => 'boolean', 'description' => 'If true, include managed_by (plugin name) and plugin_id (plugin-internal identifier) for each device. Default false.'],
                'include_hidden'     => ['type' => 'boolean', 'description' => 'Include devices hidden in the Jeedom UI (is_visible=false). Default false.'],
                'include_inactive'   => ['type' => 'boolean', 'description' => 'Include disabled devices (is_active=false). When true, each inactive device has is_active=false in the response. Default false.'],
                'include_state'      => ['type' => 'boolean', 'description' => 'Include the state map for each device (default false).'],
                'include_actions'    => ['type' => 'boolean', 'description' => 'Include the actions array for each device (default false).'],
                'include_historical' => ['type' => 'boolean', 'description' => 'Include an "available_historical" array listing the names of historized info commands. Use these names with device_get_history(equipment_id, command_name). Default false.'],
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
        'description' => 'Update a device\'s metadata: name, room assignment, categories, visibility, and/or active state. Only provided fields are modified.',
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
                'is_visible'   => ['type' => 'boolean', 'description' => 'Whether the device is visible in the Jeedom UI. Set to true to show a newly synced device.'],
                'is_active'    => ['type' => 'boolean', 'description' => 'Whether the device is enabled in Jeedom. Set to false to disable, true to re-enable.'],
            ],
            'required' => ['equipment_id'],
        ],
    ],
    [
        'name'        => 'device_delete',
        'description' => 'Permanently delete a Jeedom equipment and all its commands.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'equipment_id' => ['type' => 'integer', 'description' => 'Equipment ID obtained from devices_list.'],
            ],
            'required' => ['equipment_id'],
        ],
    ],
    [
        'name'        => 'device_get_history',
        'description' => 'Query the history of a device command (sensor values, power consumption, states…). Use devices_list with include_historical=true to discover command names available for history queries.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'equipment_id'   => ['type' => 'integer', 'description' => 'Equipment ID (from devices_list).'],
                'command_name'   => ['type' => 'string',  'description' => 'Name of the historized command (from the available_historical array in devices_list).'],
                'start'          => ['type' => 'string',  'description' => 'Start datetime, ISO 8601 or YYYY-MM-DD. Defaults to 7 days ago.'],
                'end'            => ['type' => 'string',  'description' => 'End datetime, ISO 8601 or YYYY-MM-DD. Defaults to now.'],
                'aggregate'      => ['type' => 'string',  'description' => '"stats" (default): single summary (avg/min/max/sum/count). "avg"/"min"/"max"/"sum": time series grouped by group_by. "raw": all individual points.', 'enum' => ['raw', 'stats', 'avg', 'min', 'max', 'sum']],
                'group_by'       => ['type' => 'string',  'description' => 'Time bucket for series aggregates. Ignored for "raw" and "stats".', 'enum' => ['hour', 'day']],
            ],
            'required' => [],
        ],
    ],
    [
        'name'        => 'device_get_commands',
        'description' => 'Return all commands (info and action) for a given equipment, with their IDs, names, types, and subTypes.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'equipment_id' => ['type' => 'integer', 'description' => 'Equipment ID obtained from devices_list.'],
                'type'         => ['type' => 'string',  'description' => 'Filter by command type. Omit to return all commands.', 'enum' => ['info', 'action']],
            ],
            'required' => ['equipment_id'],
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
        'description' => 'Execute one or more action commands with optional per-command values. Returns {"success": true} on success. To read updated device states after execution, use devices_states.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'commands' => [
                    'type'        => 'array',
                    'description' => 'List of commands to execute. Each entry has an "id" (int) and an optional "value" string.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'id'    => ['type' => 'integer', 'description' => 'Command ID.'],
                            'value' => ['type' => 'string', 'description' => 'Value for slider, color or message subTypes.'],
                        ],
                        'required' => ['id'],
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
                'plugin_id'               => ['type' => 'string',  'description' => 'Plugin identifier.'],
                'log_level'               => ['type' => 'string',  'description' => 'Log verbosity level.', 'enum' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'default']],
                'daemon_auto_restart'     => ['type' => 'boolean', 'description' => 'Enable or disable automatic daemon restart.'],
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
        'description' => 'List available Jeedom log files with their size, last modification date, format ("jeedom" = structured, supports min_level; "raw" = unstructured, use search instead), and highest log level seen.',
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
        'name'        => 'updates_list',
        'description' => 'List available updates for Jeedom core and installed plugins. Call this before update_apply to discover what can be updated.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
    ],
    [
        'name'        => 'update_apply',
        'description' => 'Apply a pending update for Jeedom core or a plugin. Use updates_list first to confirm the item has status "update". This executes code on the system.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'logical_id' => ['type' => 'string', 'description' => 'The logical_id of the item to update, as returned by updates_list (e.g. "jeedom" for core, or the plugin id).'],
            ],
            'required' => ['logical_id'],
        ],
    ],
    [
        'name'        => 'messages_list',
        'description' => 'List all messages in the Jeedom message center (alerts, plugin errors, Z-Wave failures, etc.).',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
    ],
    [
        'name'        => 'message_remove',
        'description' => 'Acknowledge (remove) a single message from the Jeedom message center by its ID.',
        'inputSchema' => [
            'type'       => 'object',
            'properties' => [
                'message_id' => ['type' => 'integer', 'description' => 'Message ID obtained from messages_list.'],
            ],
            'required' => ['message_id'],
        ],
    ],
    [
        'name'        => 'message_remove_all',
        'description' => 'Acknowledge (remove) all messages from the Jeedom message center at once.',
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
    ],
];
