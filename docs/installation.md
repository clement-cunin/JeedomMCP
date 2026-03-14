# Installation guide

## Prerequisites

| Element | Minimum version |
|---------|----------------|
| Jeedom | 4.4 |
| PHP | 7.4 |
| Apache2 | any recent version |

> Apache2 and PHP are already installed on any standard Jeedom installation (Raspberry Pi, etc.).
> No Python, no daemon, no reverse proxy configuration required.

---

## Step 1 — Copy the plugin to Jeedom

Via git directly on the server:

```bash
ssh pi@<JEEDOM_IP>
cd /var/www/html/plugins
git clone https://github.com/clement-cunin/JeedomMCP.git
```

Set permissions:

```bash
sudo chown -R www-data:www-data /var/www/html/plugins/JeedomMCP
sudo chmod -R 755 /var/www/html/plugins/JeedomMCP
```

---

## Step 2 — Enable the plugin in Jeedom

1. Log in to your Jeedom interface
2. Go to **Plugins → Plugin management**
3. Find **JeedomMCP** in the list and click **Enable**

An MCP API key is automatically generated on first activation.

---

## Step 3 — Retrieve your API key

Go to **Plugins → Home automation → JeedomMCP**:

1. Copy the **MCP API key** (click the copy button)
2. The `.mcp.json` snippet is pre-filled and ready to copy

---

## Step 4 — Configure your MCP client

Create a `.mcp.json` file at the root of your project:

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

The exact URL and key are shown on the plugin configuration page.

---

## Step 5 — Verify the connection

Test that the endpoint is reachable:

```bash
curl -s https://your-jeedom.duckdns.org/plugins/JeedomMCP/api/mcp.php
# Should return: {"status":"ok","server":"JeedomMCP-PHP"}
```

In Claude Code, run `/mcp` — the `jeedom` server should appear as **connected**.

---

## Troubleshooting

### 401 Unauthorized

The `X-API-Key` header is missing or incorrect. Check the key on the plugin configuration page.

### 404 Not Found

The plugin path is wrong or file permissions prevent Apache from serving the file.
Check: `sudo chown -R www-data:www-data /var/www/html/plugins/JeedomMCP`

### 500 Internal Server Error

A PHP error occurred. Check the Apache error log:

```bash
sudo tail -50 /var/log/apache2/error.log
```
