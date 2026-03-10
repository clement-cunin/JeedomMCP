# Installation guide

## Prerequisites

| Element | Minimum version |
|---------|----------------|
| Jeedom | 4.4 |
| Python | 3.9 |
| Apache2 | any recent version |

> Apache2 is already installed on any standard Jeedom installation (Raspberry Pi, etc.).

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

---

## Step 3 — Install Python dependencies

On the plugin configuration page (**Plugins → Home automation → JeedomMCP**):

1. Check that the **Dependencies** status is shown in red/orange
2. Click **Install dependencies**
3. Wait for the installation to complete (visible in `JeedomMCP_update` logs)

To verify manually on the server:

```bash
python3 -c "import fastmcp, requests; print('OK')"
```

If automatic installation fails, install manually:

```bash
pip3 install -r /var/www/html/plugins/JeedomMCP/resources/mcp_server/requirements.txt
```

---

## Step 4 — Configure Apache2 reverse proxy

The Python daemon listens on `127.0.0.1:8765` (not directly exposed).
Apache2 acts as a reverse proxy to expose it over HTTPS.

Enable the required modules:

```bash
sudo a2enmod proxy proxy_http headers
sudo systemctl reload apache2
```

Add the following block **inside your HTTPS `VirtualHost`**, in the Jeedom Apache2 site config (usually `/etc/apache2/sites-enabled/000-default-le-ssl.conf`):

```apache
# JeedomMCP — MCP server reverse proxy
ProxyPass /mcp/ http://127.0.0.1:8765/mcp/
ProxyPassReverse /mcp/ http://127.0.0.1:8765/mcp/

# Required for SSE (Server-Sent Events)
<Location /mcp/>
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"
    SetEnv proxy-nokeepalive 1
    SetEnv proxy-sendchunks 1
    SetEnv force-proxy-request-1.0 0
</Location>
```

Reload Apache2:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

---

## Step 5 — Start the daemon

On the plugin configuration page:

1. Verify that dependencies are **OK** (green badge)
2. Click **Start**
3. The status should switch to **Running** (green badge)

To check the logs:

```
Plugins → JeedomMCP → Logs (JeedomMCP tab)
```

---

## Step 6 — Verify the MCP endpoint

Test that the server is reachable:

```bash
curl -s https://your-jeedom.duckdns.org/health
# Should return: ok

curl -s -H "X-API-Key: YOUR_KEY" \
     https://your-jeedom.duckdns.org/mcp/sse
# Should initiate an SSE connection
```

---

## Step 7 — Configure your MCP client

### Per-project (`.mcp.json`)

Create a `.mcp.json` file at the root of your project:

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

The MCP API key is visible (and copyable) on the Jeedom plugin configuration page.

---

## Troubleshooting

### Daemon does not start

Check the plugin logs:

```
Plugins → JeedomMCP → Logs (JeedomMCP tab)
```

Test the daemon manually on the server:

```bash
python3 /var/www/html/plugins/JeedomMCP/resources/mcp_server/server.py \
  --loglevel debug \
  --port 8765 \
  --jeedom_apikey <JEEDOM_API_KEY> \
  --apikey test123 \
  --pid /tmp/test.pid
```

### Apache2 returns 502

The daemon is not running or is listening on a different port.
Check the port in the plugin config and in the Apache2 site config.
