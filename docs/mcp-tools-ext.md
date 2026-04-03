# MCP extension tools reference

Tools exposed by embedded extensions shipped with JeedomMCP.

Extension tools follow the naming convention `ext_{pluginId}_{toolName}` and are subject to their own ACL domain (`ext_{pluginId}.execution`).

For the extension system documentation, see [mcp-plugin-extension.md](mcp-plugin-extension.md).

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