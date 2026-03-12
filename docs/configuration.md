# Configuration reference

## Plugin configuration page

Accessible via **Plugins → Home automation → JeedomMCP**.

### Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| **Transport mode** | `http` | MCP transport: `Streamable HTTP` (recommended, stateless) or `SSE` (legacy, requires client reconnection after daemon restart). |
| **MCP server port** | `8765` | TCP port the Python daemon listens on (loopback only). Must match the Apache2 reverse proxy config. |
| **MCP API key** | *(auto-generated)* | Authentication key to provide in your MCP client config. Can be regenerated via the ↺ button. |

---

## Daemon arguments (`server.py`)

The Python daemon is launched by Jeedom with the following arguments (managed automatically):

| Argument | Type | Description |
|----------|------|-------------|
| `--loglevel` | string | Log level: `debug`, `info`, `warning`, `error` |
| `--transport` | string | MCP transport: `http` (default) or `sse` |
| `--port` | int | MCP server port (default: `8765`) |
| `--apikey` | string | MCP authentication key for clients |
| `--base_url` | string | Public base URL of the MCP server (e.g. `https://example.com/mcp`) |
| `--jeedom_url` | string | Jeedom internal API URL |
| `--jeedom_apikey` | string | Jeedom API key (for internal API calls) |
| `--pid` | string | PID file path |

---

## Apache2 configuration

Add the following block inside the HTTPS `VirtualHost` in `/etc/apache2/sites-enabled/000-default-le-ssl.conf`:

```apache
# JeedomMCP — MCP server reverse proxy
# The /mcp/ prefix is stripped before forwarding to the Python server
<Location /mcp/>
    ProxyPass http://127.0.0.1:8765/ flushpackets=on
    ProxyPassReverse http://127.0.0.1:8765/
    RequestHeader set Connection ""
    SetEnv proxy-initial-not-buffered 1
</Location>
```

> If you change the MCP port in the plugin, update the `ProxyPass` directive accordingly, then `sudo systemctl reload apache2`.

---

## MCP client configuration

The plugin configuration page generates a ready-to-copy `.mcp.json` snippet based on your current transport and API key.

### Streamable HTTP (default)

```json
{
  "mcpServers": {
    "jeedom": {
      "type": "http",
      "url": "https://your-jeedom.duckdns.org/mcp/mcp",
      "headers": {
        "X-API-Key": "YOUR_MCP_API_KEY"
      }
    }
  }
}
```

### SSE (legacy)

```json
{
  "mcpServers": {
    "jeedom": {
      "type": "sse",
      "url": "https://your-jeedom.duckdns.org/mcp/sse",
      "headers": {
        "X-API-Key": "YOUR_MCP_API_KEY"
      }
    }
  }
}
```

### Verifying the connection

In Claude Code, the `/mcp` command displays the status of connected MCP servers.
The `jeedom` server should appear with status **connected** and the list of available tools.

---

## Security

### MCP API key

- Auto-generated at installation (48 alphanumeric characters)
- Transmitted in the `X-API-Key` HTTP header with each request
- Regeneratable from the config page (invalidates existing connections)
- Never commit it to a public repository

### Recommendations

- Use a per-project `.mcp.json` rather than a global config to limit exposure
- Add `.mcp.json` to your `.gitignore` if it contains the key in plain text
- The daemon only listens on `127.0.0.1` — all external access goes through Apache2 + TLS

---

## Logs

Plugin logs are accessible in Jeedom under:

```
Analysis → Logs → JeedomMCP
```

Available log levels (configurable in Jeedom):

| Level | Usage |
|-------|-------|
| `debug` | Detail of every MCP call and Jeedom API request |
| `info` | Start/stop, MCP tool calls |
| `warning` | Unauthorized access attempts, non-critical errors |
| `error` | Blocking errors |
