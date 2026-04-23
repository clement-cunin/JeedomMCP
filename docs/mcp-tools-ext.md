# MCP extension tools reference

Tools exposed by embedded extensions shipped with JeedomMCP.

Extension tools follow the naming convention `ext_{pluginId}_{toolName}` and are subject to their own ACL domain (`ext_{pluginId}.execution`).

For the extension system documentation, see [mcp-plugin-extension.md](mcp-plugin-extension.md).

---

## weather (Open-Meteo)

### `ext_weather_current`

Get current weather conditions at the configured home location or a given place. Calls Open-Meteo directly — no Jeedom Weather plugin required, no API key needed. Falls back to the Jeedom system location (Administration → Configuration → Localisation) when no location is provided.

> **ACL**: `ext_weather.execution`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `location` | string | no | City name (e.g. `"Paris"`) or `"lat,lon"` (e.g. `"48.85,2.35"`). Defaults to Jeedom system location. |

**Returns**:

```json
{
  "location": { "lat": 47.32, "lon": 5.04, "name": "Dijon, France" },
  "time": "2026-04-23T14:30",
  "condition": "Partly cloudy",
  "weather_code": 2,
  "temperature": 18.4,
  "feels_like": 17.1,
  "humidity": 52,
  "wind_speed": 14.2,
  "wind_gusts": 22.0,
  "wind_direction": 270,
  "precipitation": 0.0,
  "uv_index": 4.2,
  "pressure": 1015.3,
  "sunrise": "2026-04-23T06:24",
  "sunset": "2026-04-23T20:51",
  "units": { "temperature": "°C", "wind_speed": "km/h", "precipitation": "mm", "pressure": "hPa" }
}
```

Throws if no location is provided and no home location is configured in Jeedom.

---

### `ext_weather_forecast`

Get a daily weather forecast for the next 1–7 days. Same location resolution as `ext_weather_current`.

> **ACL**: `ext_weather.execution`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `location` | string | no | City name or `"lat,lon"`. Defaults to Jeedom system location. |
| `days` | integer | no | Number of days to forecast (1–7). Defaults to 4. |

**Returns**:

```json
{
  "location": { "lat": 47.32, "lon": 5.04, "name": "Dijon, France" },
  "units": { "temperature": "°C", "precipitation": "mm", "wind_speed": "km/h" },
  "forecast": [
    {
      "date": "2026-04-23",
      "condition": "Partly cloudy",
      "weather_code": 2,
      "temperature_max": 21.0,
      "temperature_min": 10.5,
      "precipitation": 0.0,
      "precipitation_probability": 5,
      "wind_speed_max": 18.3,
      "wind_gusts_max": 30.1,
      "uv_index_max": 5.0,
      "sunrise": "2026-04-23T06:24",
      "sunset": "2026-04-23T20:51"
    }
  ]
}
```

---

## MerosSync

### `ext_MerosSync_sync`

Trigger a MerosSync synchronization to refresh device states from the Meross cloud.

> **ACL**: `ext_MerosSync.execution`

**Parameters**: none

**Returns**:

```json
{ "success": true, "message": "MerosSync synchronization triggered successfully." }
```

---

## openzwave (Z-Wave)

### `ext_openzwave_mode_inclusion`

Put the Z-Wave controller in inclusion mode to add a new device to the network. Trigger the pairing sequence on the device after calling this. The mode stays active until a device pairs or until `ext_openzwave_cancel` is called.

> **ACL**: `ext_openzwave.execution`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `secure` | boolean | no | If `true`, include with security (S0). Defaults to `false`. |

**Returns**:

```json
{ "success": true, "secure": false, "message": "Z-Wave controller is now in inclusion mode. Trigger the pairing sequence on your device." }
```

---

### `ext_openzwave_mode_exclusion`

Put the Z-Wave controller in exclusion mode to remove a device from the network. Trigger the reset or exclusion sequence on the device after calling this.

> **ACL**: `ext_openzwave.execution`

**Parameters**: none

**Returns**:

```json
{ "success": true, "message": "Z-Wave controller is now in exclusion mode. Trigger the reset or exclusion sequence on your device." }
```

---

### `ext_openzwave_cancel`

Cancel the current Z-Wave controller operation (inclusion or exclusion mode).

> **ACL**: `ext_openzwave.execution`

**Parameters**: none

**Returns**:

```json
{ "success": true, "message": "Z-Wave controller operation cancelled." }
```

---

### `ext_openzwave_health`

Return the health status of the Z-Wave network and per-node information.

> **ACL**: `ext_openzwave.execution`

**Parameters**: none

**Returns**:

```json
{
  "network_state": 7,
  "network_ready": true,
  "node_count": 3,
  "nodes": {
    "1": {
      "name": "Z-Wave Controller",
      "is_failed": false,
      "is_awake": true,
      "battery_level": null,
      "neighbour_count": 2
    },
    "5": {
      "name": "Fibaro Wall Plug",
      "is_failed": false,
      "is_awake": true,
      "battery_level": null,
      "neighbour_count": 1
    },
    "8": {
      "name": "Fibaro Motion Sensor",
      "is_failed": true,
      "is_awake": false,
      "battery_level": 12,
      "neighbour_count": 0
    }
  }
}
```

`network_state` values: below 7 = controller busy, 7+ = ready.

---

### `ext_openzwave_node_remove_failed`

Force-remove a dead or burned-out Z-Wave node that can no longer be excluded normally. Checks that the node is marked as failed by the controller before proceeding — returns a refusal message if not.

> **ACL**: `ext_openzwave.execution`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `node_id` | integer | yes | The Z-Wave node ID to force-remove. Use `ext_openzwave_health` to find node IDs. |

**Returns** (success):

```json
{ "success": true, "node_id": 8, "message": "Force-remove of node 8 sent to the controller." }
```

**Returns** (node not failed):

```json
{ "success": false, "node_id": 5, "message": "Node 5 is not marked as failed by the controller. Use mode_exclusion to remove it normally." }
```

---

### `ext_openzwave_node_config_get`

Get all configuration parameters of a Z-Wave node (Command Class 112). Returns each parameter with its index, label, current value, and allowed values for list-type parameters.

> **ACL**: `ext_openzwave.execution`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `node_id` | integer | yes | The Z-Wave node ID. |

**Returns**:

```json
{
  "node_id": 5,
  "params": {
    "1": { "label": "Motion sensitivity", "value": 8, "allowed_values": null },
    "2": { "label": "Motion blind time", "value": 15, "allowed_values": null },
    "24": { "label": "Operating mode", "value": "Default", "allowed_values": ["Default", "Always On", "Night mode"] }
  }
}
```

---

### `ext_openzwave_node_config_set`

Set a configuration parameter on a Z-Wave node (Command Class 112). For sleeping devices the change is queued and applied on next wake-up.

> **ACL**: `ext_openzwave.execution`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `node_id` | integer | yes | The Z-Wave node ID. |
| `index` | integer | yes | The parameter index to set. |
| `value` | string | yes | The value to set (e.g. `"1"`, `"true"`, or the list label). |

**Returns**:

```json
{ "success": true, "node_id": 5, "index": 1, "value": "10", "message": "Configuration parameter 1 set on node 5. Sleeping devices will apply the change on next wake-up." }
```

---

## JeedomConnect

### `ext_JeedomConnect_devices_list`

List all JeedomConnect instances and their paired mobile devices.

> **ACL**: `ext_JeedomConnect.execution`

**Parameters**: none

**Returns**:

```json
{
  "count": 1,
  "devices": [
    {
      "equipment_id": 74,
      "name": "Clément",
      "is_enable": true,
      "device_name": "Pixel 8",
      "platform": "android",
      "last_seen": 1743800000,
      "app_state": "active",
      "has_token": true
    }
  ]
}
```

---

### `ext_JeedomConnect_notifications_list`

List available push notification configurations for a JeedomConnect instance. Use this to discover `notification_id` values before calling `send_notification`.

> **ACL**: `ext_JeedomConnect.execution`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `equipment_id` | integer | yes | Equipment ID from `devices_list`. |

**Returns**:

```json
{
  "equipment_id": 74,
  "notifications": [
    { "id": "defaultNotif", "name": "Notification", "channel": "default" },
    { "id": "alertNotif",   "name": "Alerte",        "channel": "high"    }
  ]
}
```

---

### `ext_JeedomConnect_send_notification`

Send a push notification to a JeedomConnect mobile device.

> **ACL**: `ext_JeedomConnect.execution`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `equipment_id` | integer | yes | Equipment ID from `devices_list`. |
| `message` | string | yes | Notification message body. |
| `notification_id` | string | no | Notification config ID from `notifications_list`. Defaults to `"defaultNotif"`. |
| `title` | string | no | Notification title override. |

**Returns**:

```json
{ "success": true, "equipment_id": 74, "notification_id": "defaultNotif", "message": "La lumière du salon est allumée depuis 2h." }
```

---

### `ext_JeedomConnect_get_geofences`

Return configured geofences for a JeedomConnect instance with the current inside/outside status of the paired device.

> **ACL**: `ext_JeedomConnect.execution`

**Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `equipment_id` | integer | yes | Equipment ID from `devices_list`. |

**Returns**:

```json
{
  "equipment_id": 74,
  "count": 2,
  "geofences": [
    { "identifier": "Home", "name": "Maison", "latitude": 48.8566, "longitude": 2.3522, "radius_m": 200.0, "inside": true },
    { "identifier": "Work", "name": "Bureau", "latitude": 48.8600, "longitude": 2.3400, "radius_m": 500.0, "inside": false }
  ]
}
```