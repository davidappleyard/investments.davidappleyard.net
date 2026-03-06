# MCP Server — Setup & Deployment Guide

This documents how to deploy the remote MCP server that exposes portfolio data
to Claude.ai (and other MCP clients) via a secret HTTPS endpoint.

## What it does

`mcp_server.py` runs a Streamable HTTP MCP server on `127.0.0.1:8765`. Apache
proxies a secret public URL to it. Claude.ai connects using a custom connector
and can then answer questions like:

- "What's my portfolio value?"
- "What's my gain/loss today?"
- "Show me my ISA holdings"
- "How much dividend income did I earn last year?"

Tools exposed:

| Tool | Description |
|---|---|
| `get_daily_gain_loss` | Today's P&L vs previous close — mirrors the dashboard widget |
| `get_portfolio_summary` | Current value by account using live prices |
| `get_holdings` | Per-ticker detail with unrealised gain/loss |
| `get_account_performance` | Performance over a date range (uses historical snapshots) |
| `get_transactions` | Filterable transaction log |
| `get_dividend_income` | Dividend income grouped by ticker/month/year |
| `get_allocation_breakdown` | Value split by allocation category |

Security model: no authentication. The secret URL path acts as the token.
Keep the path private.

---

## Prerequisites

- Python 3.10 or newer (see note below for Debian 11)
- A virtualenv at `/opt/investment-mcp-venv`
- Apache with `mod_proxy` and `mod_proxy_http` enabled
- `.env` file with `DB_*` credentials and optionally `MCP_HOST` / `MCP_PORT`

### Python version on Debian 11 (Bullseye)

Debian 11 ships Python 3.9, which is too old for the `mcp` package (requires
3.10+). Build Python 3.11 from source:

```bash
apt install -y build-essential zlib1g-dev libncurses5-dev libgdbm-dev \
    libnss3-dev libssl-dev libreadline-dev libffi-dev libsqlite3-dev wget

cd /tmp
wget https://www.python.org/ftp/python/3.11.9/Python-3.11.9.tgz
tar -xf Python-3.11.9.tgz
cd Python-3.11.9
./configure --enable-optimizations
make -j$(nproc)
make altinstall        # installs as python3.11, doesn't touch system python3
```

`python3.11` will be at `/usr/local/bin/python3.11`. Add to PATH if needed:

```bash
export PATH="/usr/local/bin:$PATH"
```

---

## Step 1 — Create the virtualenv and install dependencies

```bash
python3.11 -m venv /opt/investment-mcp-venv
/opt/investment-mcp-venv/bin/pip install -r \
    /var/www/html/investments.davidappleyard.net/public_html/python/requirements-mcp.txt
```

`requirements-mcp.txt` contains:
```
mcp[cli]>=1.0.0
mysql-connector-python>=8.0.0
uvicorn>=0.30.0
```

---

## Step 2 — Configure .env

Add these to `.env` (defaults shown; usually no change needed):

```bash
# MCP_HOST=127.0.0.1
# MCP_PORT=8765
```

The server reads `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` from `.env` too —
these should already be set for the main app.

---

## Step 3 — Test the server manually

```bash
cd /var/www/html/investments.davidappleyard.net/public_html
/opt/investment-mcp-venv/bin/python python/mcp_server.py
```

In another terminal:
```bash
curl -s http://127.0.0.1:8765/mcp -X POST \
    -H "Content-Type: application/json" \
    -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{}},"id":1}'
```

Expect a JSON response. Ctrl-C to stop.

---

## Step 4 — Install the systemd service

The service file is at `python/investment-mcp.service`. Copy and enable:

```bash
cp /var/www/html/investments.davidappleyard.net/public_html/python/investment-mcp.service \
    /etc/systemd/system/

systemctl daemon-reload
systemctl start investment-mcp
systemctl enable investment-mcp
systemctl status investment-mcp
```

The service file key settings:
```ini
ExecStart=/opt/investment-mcp-venv/bin/python \
    /var/www/html/investments.davidappleyard.net/public_html/python/mcp_server.py
EnvironmentFile=/var/www/html/investments.davidappleyard.net/public_html/.env
User=www-data
```

To view logs: `journalctl -u investment-mcp -f`

---

## Step 5 — Enable Apache proxy modules

```bash
/usr/sbin/a2enmod proxy proxy_http
```

If `a2enmod` is not in PATH, use the full path above. Alternatively create
symlinks manually:

```bash
ln -sf /etc/apache2/mods-available/proxy.load /etc/apache2/mods-enabled/
ln -sf /etc/apache2/mods-available/proxy.conf /etc/apache2/mods-enabled/
ln -sf /etc/apache2/mods-available/proxy_http.load /etc/apache2/mods-enabled/
```

---

## Step 6 — Add Apache reverse proxy config

Choose a secret token (e.g. a UUID or random hex string). Add these two lines
inside the `<VirtualHost _default_:443>` block in
`/etc/apache2/sites-enabled/investments.davidappleyard.net.conf`, just before
`</VirtualHost>`:

```apache
ProxyPass        /mcp-YOUR_SECRET_TOKEN  http://127.0.0.1:8765/mcp  flushpackets=on
ProxyPassReverse /mcp-YOUR_SECRET_TOKEN  http://127.0.0.1:8765/mcp
```

No trailing slashes — FastMCP redirects `/mcp/` → `/mcp` and the trailing-slash
version causes a 307 that leaks the internal address.

Reload Apache:
```bash
apachectl configtest && service apache2 reload
```

Test:
```bash
curl -sv https://investments.davidappleyard.net/mcp-YOUR_SECRET_TOKEN 2>&1 | grep "< HTTP"
# Expect: HTTP/1.1 406 Not Acceptable  (correct — plain GET rejected by MCP)
```

A 406 means the proxy is working. Claude.ai sends proper POST requests.

---

## Step 7 — Register in Claude.ai

1. Go to **Settings → Connectors → Add custom connector**
2. Enter the full URL: `https://investments.davidappleyard.net/mcp-YOUR_SECRET_TOKEN`
3. Authentication: **None**
4. Save

---

## Deploying code changes

After any change to `mcp_server.py`:

```bash
cd /var/www/html/investments.davidappleyard.net/public_html
git pull
systemctl restart investment-mcp
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `307 Temporary Redirect` to internal URL | Trailing slash in ProxyPass | Remove trailing slashes from both ProxyPass lines |
| `502 Bad Gateway` | MCP server not running | `systemctl start investment-mcp` |
| `404 Not Found` | ProxyPass path doesn't match URL | Check secret token matches in config and URL |
| `406 Not Acceptable` on GET | Normal — MCP needs POST | Not an error |
| Tool list stale in Claude.ai | Old code running | `git pull && systemctl restart investment-mcp` |
| `DB_PASS not set` error in logs | EnvironmentFile not loading | Check path in service file matches actual `.env` location |
