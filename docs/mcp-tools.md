# MCP tools reference

List of tools exposed by the JeedomMCP server.

---

## `acl_list`

Returns the current ACL mode and all authorized operations. Call this first to know which tools are available before attempting write operations.

**Parameters**: none

**Returns**:

```json
{
  "mode": "read_execute",
  "authorized_tools": [
    "acl_list",
    "devices_list",
    "device_state",
    "devices_states",
    "command_execute",
    "rooms_list",
    "scenarios_list",
    "scenario_get_actions",
    "scenario_run"
  ]
}
```

| Field | Description |
|-------|-------------|
| `mode` | Active ACL mode: `read_execute`, `read_execute_describe`, `full`, or `custom` |
| `authorized_tools` | List of tool names the client is currently allowed to call. `acl_list` is always included. |

---

## `devices_list`

Discovery tool. Lists all enabled Jeedom equipment with their current state and available actions. Returns a paginated response.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `categories` | string[] | no | Filter by category. Valid values: `heating`, `security`, `energy`, `light`, `opening`, `automatism`, `multimedia`, `default` |
| `room_ids` | int[] | no | Filter by room — returns only equipment whose `room_id` is in this list |
| `include_state` | bool | no | Include the `state` map per device (default: `true`) |
| `include_actions` | bool | no | Include the `actions` array per device (default: `true`) |
| `limit` | int | no | Maximum number of items to return (default: 50). Use 0 for no limit. |
| `offset` | int | no | Number of items to skip (default: 0). |

**Returns**: paginated object

```json
{
  "total": 87,
  "offset": 0,
  "limit": 50,
  "items": [
    {
      "id": 42,
      "name": "Living room light",
      "description": "Ceiling light, Z-Wave dimmer",
      "object_id": 12,
      "object_name": "Living room",
      "categories": ["light"],
      "is_visible": true,
      "state": {
        "State": true,
        "Brightness": 75
      },
      "actions": [
        {"id": 103, "name": "On",             "subType": "other"},
        {"id": 104, "name": "Off",            "subType": "other"},
        {"id": 105, "name": "Set brightness", "subType": "slider"}
      ]
    }
  ]
}
```

| Field | Description |
|-------|-------------|
| `state` | Map of info command name → typed value (`bool`, `float`, `string`, or `null`). Omitted if `include_state=false`. |
| `actions` | List of executable commands. `subType` indicates whether `command_execute` requires a `value` (`slider`, `color`, `message`) or not (`other`). Omitted if `include_actions=false`. |

---

## `device_set_description`

Sets the description (comment field) of a Jeedom equipment.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `equipment_id` | int | yes | Equipment ID (from `devices_list`) |
| `description` | string | yes | Description text explaining the equipment's purpose or location |

**Returns**: updated equipment object

```json
{ "id": 42, "name": "Living room light", "description": "Main living room ceiling light", "object_id": "12", "categories": ["light"], "is_visible": true }
```

---

## `devices_states`

Lightweight bulk refresh tool. Returns only the current state for a specific set of equipment. Use `devices_list` for full discovery (metadata + actions).

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `equipment_ids` | int[] | yes | IDs of the equipment to refresh. |

**Returns**: array of `{id, state}` in the same order as the input IDs

```json
[
  {"id": 42, "state": {"State": true, "Brightness": 75}},
  {"id": 43, "state": {"Temperature": 21.5, "Humidity": 58.0}}
]
```

---

## `command_execute`

Executes one or more action commands. Multiple IDs can be passed in a single call to act on several devices at once (e.g. turn off all lights).

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `command_ids` | int[] | yes | One or more command IDs (from `devices_list` actions) |
| `value` | string | no | Value for `slider`, `color`, `message` subTypes — applied to all commands in the batch |

**Returns**: array of updated states, one entry per affected equipment (same shape as `devices_states`)

```json
[
  {"id": 42, "state": {"State": false}},
  {"id": 43, "state": {"State": false}}
]
```

---

## `rooms_list`

Lists all rooms (Jeedom objects) in the home. Returns a paginated response.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `limit` | int | no | Maximum number of items to return (default: 50). Use 0 for no limit. |
| `offset` | int | no | Number of items to skip (default: 0). |

**Returns**:

```json
{
  "total": 12,
  "offset": 0,
  "limit": 50,
  "items": [
    {
      "id": 2,
      "name": "Salon",
      "description": "Pièce principale avec accès cuisine",
      "icon": "icon maison-sofa5",
      "surface": "30",
      "orientation": 180,
      "parent_id": null
    }
  ]
}
```

| Field | Description |
|-------|-------------|
| `description` | Free-text description, or `null` |
| `icon` | CSS icon class (e.g. `"icon maison-wc"` or `"fas fa-home"`), or `null` |
| `surface` | Floor area in square metres, or `null` |
| `orientation` | Orientation as degrees — one of 8 values: `0`=N, `45`=NE, `90`=E, `135`=SE, `180`=S, `225`=SW, `270`=W, `315`=NW, or `null` |
| `parent_id` | ID of the parent room, or `null` for top-level rooms |

---

## `room_create`

Creates a new room in the Jeedom home.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | yes | Display name of the room |
| `description` | string | no | Description text explaining the room |
| `icon` | string | no | CSS icon class (e.g. `"icon maison-wc"` or `"fas fa-home"`) |
| `surface` | string | no | Floor area in square metres (e.g. `"15.5"`) |
| `orientation` | string | no | Orientation in degrees — one of: `0`, `45`, `90`, `135`, `180`, `225`, `270`, `315` |
| `parent_id` | int | no | Parent room ID for nested rooms (from `rooms_list`) |

**Returns**:

```json
{ "id": 12, "name": "Bureau", "description": null, "icon": null, "surface": "12", "orientation": 0, "parent_id": 1 }
```

---

## `room_update`

Updates a Jeedom room. Only provided fields are modified.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `room_id` | int | yes | Room ID (from `rooms_list`) |
| `name` | string | no | New display name |
| `description` | string | no | New description text |
| `icon` | string | no | CSS icon class (e.g. `"icon maison-wc"`). Pass `""` to clear |
| `surface` | string | no | Floor area in square metres |
| `orientation` | string | no | Orientation in degrees — one of: `0`, `45`, `90`, `135`, `180`, `225`, `270`, `315` |
| `parent_id` | int | no | New parent room ID. Pass `0` to move to top level |

**Returns**:

```json
{ "id": 2, "name": "Salon", "description": "Pièce principale", "icon": "icon maison-sofa5", "surface": "30", "orientation": 180, "parent_id": null }
```

---

## `room_delete`

Permanently deletes a Jeedom room.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `room_id` | int | yes | Room ID (from `rooms_list`) |

**Returns**:

```json
{ "success": true, "room_id": 12 }
```

---

## `room_set_description`

Sets the description of a Jeedom room.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `room_id` | int | yes | Room ID (from `rooms_list`) |
| `description` | string | yes | Description text explaining the room |

**Returns**:

**Returns**: updated room object

```json
{ "id": 2, "name": "Salon", "description": "Pièce principale avec accès cuisine", "icon": null, "surface": "35", "orientation": 180, "parent_id": 14 }
```

---

## `scenarios_list`

Lists all Jeedom scenarios. Returns a paginated response.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `limit` | int | no | Maximum number of items to return (default: 50). Use 0 for no limit. |
| `offset` | int | no | Number of items to skip (default: 0). |

**Returns**:

```json
{
  "total": 8,
  "offset": 0,
  "limit": 50,
  "items": [
    {
      "id": 5,
      "name": "Evening mode",
      "group": "Ambiance",
      "description": "Turns on lights and sets heating at sunset",
      "is_active": true,
      "state": "stop",
      "mode": "schedule",
      "schedule": "30 21 * * *",
      "trigger": null,
      "last_launch": "2026-03-11 21:30:03"
    }
  ]
}
```

| Field | Description |
|-------|-------------|
| `mode` | `schedule` (cron-based), `provoke` (trigger-based), or `always` |
| `schedule` | Cron expression(s) — string or array when multiple schedules are defined |
| `trigger` | List of trigger conditions, or `null` if none |
| `last_launch` | Datetime of last execution, or `null` if never run |

---

## `scenario_get_actions`

Gets the full action blocks of a Jeedom scenario (elements, sub-elements, expressions).

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `scenario_id` | int | yes | Scenario ID (from `scenarios_list`) |

**Returns**:

```json
{
  "scenario_id": 3,
  "elements": [
    {
      "type": "action",
      "order": "0",
      "subElements": [
        {
          "type": "action",
          "subtype": "action",
          "expressions": [
            {
              "type": "action",
              "expression": "#[Entrée][Couloir][Mode Jour]#",
              "options": { "enable": "1", "background": "0" },
              "order": "0"
            }
          ]
        }
      ]
    }
  ]
}
```

| Field | Description |
|-------|-------------|
| `elements` | Top-level blocks of the scenario |
| `subElements` | Action or condition groups within a block |
| `expressions` | Individual actions or conditions within a group |
| `expression` | Jeedom tag (`#[room][device][cmd]#`), code snippet, or condition |

---

## `scenario_set_actions`

Replaces the action blocks of a Jeedom scenario. The `elements` structure mirrors the output of `scenario_get_actions`.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `scenario_id` | int | yes | Scenario ID (from `scenarios_list`) |
| `elements` | object[] | yes | Full list of action blocks to save (replaces existing blocks) |

**Returns**:

**Returns**: updated scenario object (same structure as `scenarios_list`)

```json
{ "id": 3, "name": "Evening mode", "group": null, "description": "", "is_active": true, "state": "stop", "mode": "schedule", "schedule": "30 21 * * *", "trigger": null, "last_launch": null }
```

---

## `scenario_set_description`

Sets the description of a Jeedom scenario.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `scenario_id` | int | yes | Scenario ID (from `scenarios_list`) |
| `description` | string | yes | Description text explaining the scenario's purpose |

**Returns**:

**Returns**: updated scenario object

```json
{ "id": 5, "name": "Evening mode", "group": "Ambiance", "description": "Turns off all lights and locks doors at bedtime", "is_active": true, "state": "stop", "mode": "schedule", "schedule": "30 21 * * *", "trigger": null, "last_launch": "2026-03-11 21:30:03" }
```

---

## `scenario_update`

Updates fields of an existing Jeedom scenario. Only provided fields are modified.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `scenario_id` | int | yes | Scenario ID (from `scenarios_list`) |
| `name` | string | no | New display name |
| `mode` | string | no | `schedule`, `provoke`, or `always` |
| `schedule` | string | no | Cron expression (for schedule mode) |
| `trigger` | string[] | no | Trigger conditions (for provoke mode) |
| `is_active` | bool | no | Enable or disable the scenario |
| `description` | string | no | Description of the scenario's purpose |

**Returns**:

**Returns**: updated scenario object

```json
{ "id": 5, "name": "Evening mode", "group": "Ambiance", "description": "", "is_active": false, "state": "stop", "mode": "schedule", "schedule": "0 23 * * *", "trigger": null, "last_launch": "2026-03-11 21:30:03" }
```

---

## `scenario_create`

Creates a new Jeedom scenario.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | string | yes | Display name of the scenario |
| `mode` | string | yes | `schedule` (cron-based), `provoke` (trigger-based), or `always` |
| `schedule` | string | no | Cron expression — required when `mode` is `schedule` |
| `trigger` | string[] | no | Trigger conditions (command IDs or expressions) — used when `mode` is `provoke` |
| `is_active` | bool | no | Whether the scenario is active (default: `true`) |
| `description` | string | no | Description of the scenario's purpose |

**Returns**:

```json
{
  "id": 12,
  "name": "Morning routine",
  "group": null,
  "description": "",
  "is_active": true,
  "state": null,
  "mode": "schedule",
  "schedule": "30 7 * * *",
  "trigger": null,
  "last_launch": null
}
```

---

## `scenario_delete`

Permanently deletes a Jeedom scenario.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `scenario_id` | int | yes | Scenario ID (from `scenarios_list`) |

**Returns**:

```json
{ "success": true, "scenario_id": 12 }
```

---

## `scenario_run`

Triggers a Jeedom scenario.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `scenario_id` | int | yes | Scenario ID (from `scenarios_list`) |

**Returns**:

```json
{ "success": true, "scenario_id": 5 }
```

---

## Error handling

All tools return a JSON object. On error, the `error` field is present:

```json
{ "error": "Description of the error" }
```

Common errors:

| Error | Likely cause |
|-------|-------------|
| `Equipment not found` | Invalid or disabled equipment ID |
| `Command not found` | Invalid command ID |
| `Jeedom API error` | Invalid Jeedom API key or Jeedom unreachable |
