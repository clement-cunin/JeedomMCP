# Configuration reference

## Plugin configuration page

Accessible via **Plugins → Home automation → JeedomMCP**.

### Parameters

| Parameter | Description |
|-----------|-------------|
| **MCP API key** | Authentication key to provide in your MCP client config. Auto-generated on activation. Can be regenerated via the ↺ button (invalidates existing connections). |

---

## MCP endpoint

The MCP server is a PHP file served directly by Apache — no daemon or reverse proxy required.

| Environment | URL |
|-------------|-----|
| Direct access | `https://your-jeedom.duckdns.org/plugins/JeedomMCP/api/mcp.php` |

Health check (no auth required):

```bash
curl -s https://your-jeedom.duckdns.org/plugins/JeedomMCP/api/mcp.php
# {"status":"ok","server":"JeedomMCP-PHP"}
```

---

## MCP client configuration

The plugin configuration page generates a ready-to-copy `.mcp.json` snippet.

```json
{
  "mcpServers": {
    "jeedom": {
      "type": "http",
      "url": "https://your-jeedom.duckdns.org/plugins/JeedomMCP/api/mcp.php",
      "headers": {
        "X-API-Key": "YOUR_MCP_API_KEY"
      }
    }
  }
}
```

In Claude Code, run `/mcp` to verify the `jeedom` server appears as **connected** with the list of available tools.

---

## Security

### MCP API key

- Auto-generated at activation (48 hex characters)
- Transmitted in the `X-API-Key` HTTP header with each request
- Regeneratable from the config page (invalidates existing connections)
- Never commit it to a public repository

### Recommendations

- Use a per-project `.mcp.json` rather than a global config to limit exposure
- Add `.mcp.json` to your `.gitignore` if it contains the key in plain text
- All traffic goes through Apache2 + TLS (HTTPS)

---

## Logs

PHP errors are logged in the Apache error log:

```bash
sudo tail -f /var/log/apache2/error.log
```
