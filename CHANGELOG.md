# Changelog

## [Unreleased]

- Pure PHP MCP server (`streamable-http` transport, no daemon)
- Full device toolset: `devices_list`, `devices_states`, `command_execute`, `device_set_description`
- Full room toolset: `rooms_list`, `room_create`, `room_update`, `room_delete`, `room_set_description`
- Full scenario toolset: `scenarios_list`, `scenario_run`, `scenario_create`, `scenario_update`, `scenario_delete`, `scenario_set_description`, `scenario_get_actions`, `scenario_set_actions`
- API key authentication via `X-API-Key` header
- Plugin configuration page with API key management and `.mcp.json` snippet
- ACL system: configurable per-domain, per-operation permissions from the plugin settings page
- ACL modes: Read & Execute (default), Read Execute & Set description, Full access, Custom
- `acl_list` tool — returns current mode and list of authorized tool names
- Equipment categories returned as array (`["light"]`) instead of raw map
- Optional `categories` filter on `devices_list` and `devices_states`
- Icon field in room responses, modifiable via `room_update`
- Pagination (`limit` / `offset`) on all list tools
- `devices_list` returns `state` map and `actions` per device with optional `include_state` / `include_actions` flags
- `devices_states` lightweight bulk refresh — requires `equipment_ids`, returns `[{id, state}]` only
- `command_execute` accepts a `commands` array of `{id|ids, value?}` entries for per-command values in a single call
- `devices_list` accepts optional `room_ids` filter to return only devices belonging to specific rooms
- `devices_list` excludes hidden devices (`is_visible=false`) by default; opt-in via `include_hidden=true`
- Info commands as a typed `state` map (`binary` → `bool`, `numeric` → `float`)
- Action commands in an `actions` array — `logicalId` and redundant fields removed
- `devices_list`: `room_id` replaces `object_id`, `object_name` removed, null/default fields omitted
- State maps omit null-valued fields; empty state returns `{}` instead of `[]`
