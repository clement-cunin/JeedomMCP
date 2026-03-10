# MCP tools reference

List of tools exposed by the JeedomMCP server.

---

## `list_devices`

Lists all enabled Jeedom equipment.

**Parameters**: none

**Returns**: JSON array of equipment

```json
[
  {
    "id": 42,
    "name": "Living room light",
    "object_id": "12",
    "object_name": "Living room",
    "category": "light",
    "is_enable": true,
    "is_visible": true
  }
]
```

---

## `get_device_state`

Gets the current state of a specific equipment and all its info commands.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `equipment_id` | int | yes | Equipment ID (from `list_devices`) |

**Returns**:

```json
{
  "equipment_id": 42,
  "name": "Living room light",
  "commands": [
    { "id": 101, "name": "State", "logicalId": "state", "value": "1" },
    { "id": 102, "name": "Brightness", "logicalId": "brightness", "value": "75" }
  ]
}
```

---

## `execute_command`

Executes an action command on a Jeedom equipment.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `command_id` | int | yes | Command ID (from `get_device_state`) |
| `value` | string | no | Value for slider/text commands |

**Returns**:

```json
{ "success": true, "command_id": 103 }
```

**Error**:

```json
{ "error": "Command 103 not found" }
```

---

## `list_scenarios`

Lists all Jeedom scenarios.

**Parameters**: none

**Returns**:

```json
[
  {
    "id": 5,
    "name": "Evening mode",
    "group": "Ambiance",
    "state": "stop",
    "is_active": true
  }
]
```

---

## `run_scenario`

Triggers a Jeedom scenario.

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `scenario_id` | int | yes | Scenario ID (from `list_scenarios`) |

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
