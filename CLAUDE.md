# CLAUDE.md — Investment Portfolio Tracker

This document describes the codebase structure, conventions, and development workflows for AI assistants working on this project.

## Project Overview

A personal investment portfolio tracking web application for two clients (David, Jen) across three account types (SIPP, ISA, Fund & Share). It ingests CSV exports from Hargreaves Lansdown (HL), tracks transactions, fetches live/historical prices from Yahoo Finance, and provides portfolio valuation and performance reporting.

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | PHP 7+ (no framework) |
| Database | MySQL/MariaDB via PDO |
| Frontend | HTML/CSS/Vanilla JS (inline in PHP) |
| Data Fetching | Python 3 (`yfinance`, `mysql.connector`) |
| Scheduling | System cron |

## Repository Structure

Non-public files (`.env`, `CLAUDE.md`) live at the repo root, above the web root:

```
investments.davidappleyard.net/    ← repo root (NOT web-accessible)
├── .env                           # DB credentials & secrets (gitignored)
├── .env.example                   # Template for .env
├── CLAUDE.md                      # This file
│
└── public_html/                   ← web root (served by Apache/Nginx)
    ├── index.php                  # Main application (~3,700 lines) — dashboard, reports, CSV import
    ├── login.php                  # Login page
    ├── auth.php                   # Authentication helpers + table creation (users, remember_tokens)
    ├── change_password.php        # Password change form
    ├── settings.php               # Ticker/allocation management
    ├── load_env.php               # Loads .env from repo root (one level above public_html)
    │
    ├── python/
    │   ├── fetch_daily_prices.py      # Cron: fetch latest prices after market close (weekdays 18:00)
    │   ├── fetch_dividend_yields.py   # Cron: fetch dividend yields (weekdays 19:00)
    │   ├── fetch_historical_prices.py # One-time: backfill historical price data
    │   ├── fetch_prices.py            # Utility: manual price fetching
    │   ├── mcp_server.py              # Remote MCP server for Claude.ai integration
    │   ├── requirements-mcp.txt       # Python deps for MCP server (mcp, uvicorn, mysql-connector)
    │   ├── investment-mcp.service     # Systemd unit file for the MCP server
    │   └── MCP_SETUP.md               # Full deployment guide for the MCP server
    │
    ├── cron/
    │   └── daily_update_historical_values.php  # Cron: calculate and store daily account valuations
    │
    └── one-off-scripts/
        ├── backfill_dividend_tickers.php        # One-time: populate ticker/dividend data
        ├── backfill_historical_values.php       # One-time: backfill account value history
        └── check_historical_progress.php        # Diagnostic: verify historical data completeness
```

## Database Schema

Key tables (all prefixed with `hl_`):

| Table | Purpose |
|---|---|
| `hl_transactions` | All investment transactions (Buy, Sell, Dividend, etc.) |
| `hl_tickers` | Ticker matching text from HL CSVs |
| `hl_ticker_symbols` | Extended ticker info: `yahoo_symbol`, `currency`, `allocation` |
| `hl_prices_latest` | Current price per ticker |
| `hl_prices_historical` | Daily historical prices |
| `hl_yield_latest` | Latest dividend yields |
| `hl_import_batches` | CSV import tracking for deduplication |
| `hl_account_values_historical` | Daily account balance snapshots |
| `users` | Authentication (auto-created by `auth.php`) |
| `remember_tokens` | Persistent "remember me" session tokens (auto-created) |

Database credentials are stored in `.env` at the repo root (above `public_html/`) and loaded by `load_env.php` into PHP constants. Python scripts read the same values from environment variables:

```python
DB_HOST = os.getenv("DB_HOST", "localhost")
# DB_NAME, DB_USER, DB_PASS follow the same pattern
```

## Development Workflow

### Running the Application

No build step required. The application runs directly under a PHP web server (Apache/Nginx) or PHP CLI's built-in server:

```bash
cd public_html
php -S localhost:8080
# Then open http://localhost:8080/index.php
```

Ensure the `investments` MySQL database exists and the DB user has full access to it. Tables are created automatically on first load by `auth.php`.

The web server's document root must be set to `public_html/`. The `.env` file lives one level above at the repo root — `load_env.php` resolves it via `dirname(__DIR__)`.

### Cron Job Setup

Configure these cron jobs on the server for automated data updates:

```bash
# Daily prices — weekdays at 18:00
0 18 * * 1-5 python3 /path/to/python/fetch_daily_prices.py >> /logs/daily_prices.log 2>&1

# Dividend yields — weekdays at 19:00
0 19 * * 1-5 python3 /path/to/python/fetch_dividend_yields.py >> /logs/yields_cron_daily.log 2>&1

# Account value snapshots — daily (runs for yesterday's date by default)
0 0 * * * php /path/to/cron/daily_update_historical_values.php >> /logs/daily_update.log 2>&1
```

The PHP cron script also accepts a date argument:

```bash
php cron/daily_update_historical_values.php 2024-01-15
```

### Data Initialisation (New Instance)

1. Create the MySQL database: `CREATE DATABASE investments;`
2. Start the web server and load `index.php` — auth tables are auto-created.
3. Set up ticker symbols in `settings.php`.
4. Import transaction CSV files from the HL portal via the import UI in `index.php`.
5. Run `python3 python/fetch_historical_prices.py` (one-time backfill).
6. Verify data with `one-off-scripts/check_historical_progress.php`.

## Code Conventions

### PHP

- **Naming:** Snake_case for functions (`calculate_cash_for_account()`, `enforce_secure_auth()`), UPPERCASE for DB constants.
- **Database:** Always use PDO with parameterized prepared statements — never interpolate user input into SQL.
- **Output:** Always wrap user-controlled data in `htmlspecialchars()` before echoing to prevent XSS.
- **Auth:** Call `enforce_secure_auth()` (from `auth.php`) at the top of every protected page.
- **File structure:** Helper functions are defined at the top of each file; HTML output comes at the bottom.
- **Error display:** Only enable `display_errors` temporarily during debugging — disable it in production.

### Python

- **Naming:** Snake_case throughout.
- **Rate limiting:** Include a 1–2 second sleep between Yahoo Finance API calls to avoid throttling.
- **Logging:** Use `log_and_print()` / `prepend_log_block()` helpers for consistent log output.
- **Error handling:** Use try-except blocks; log errors gracefully without crashing the entire script.
- **Docstrings:** Document the purpose, arguments, and return values of each function.

### JavaScript

- Vanilla JS only — no frameworks or external dependencies.
- Scripts are inline within PHP files (no separate `.js` files).
- Use `navigator.clipboard.writeText()` for clipboard operations with `document.execCommand('copy')` as a fallback.

### SQL Queries

- All queries use PDO prepared statements with named or positional placeholders.
- Prefer explicit column lists over `SELECT *` in production queries.

## Security Notes

- **SQL injection:** Protected via PDO prepared statements — maintain this always.
- **XSS:** All output uses `htmlspecialchars()` — do not bypass this.
- **Passwords:** Hashed with PHP's `password_hash(PASSWORD_DEFAULT)`.
- **Credentials:** DB credentials are currently hardcoded. When refactoring, move them to a `.env` file or server environment variables and exclude from version control.
- **CSRF:** No CSRF tokens are currently implemented on forms — be aware of this gap if adding sensitive write operations.
- **Anti-indexing:** `X-Robots-Tag: noindex, nofollow` headers are set on authenticated pages.
- **Default credentials:** First-run creates admin/admin123 — document this clearly and prompt immediate change.

## No Build, Test, or Lint Tooling

There is no test suite, linter, or build pipeline. When making changes:

- Test manually via the browser and/or PHP CLI.
- For Python scripts, run them directly and inspect log output.
- For data integrity, use `one-off-scripts/check_historical_progress.php` as a diagnostic tool.

## Remote MCP Server (Claude.ai Integration)

`python/mcp_server.py` exposes portfolio data to Claude.ai via the Model Context
Protocol. It runs as a persistent systemd service on the server and is proxied
through Apache over HTTPS.

**Full setup guide:** `python/MCP_SETUP.md`

Key facts for AI assistants:
- Runs on `127.0.0.1:8765`, Apache proxies `/mcp-<secret-token>` → `http://127.0.0.1:8765/mcp`
- Uses `mcp[cli]` + `uvicorn`; requires Python 3.10+ (built from source on Debian 11)
- Virtualenv at `/opt/investment-mcp-venv`
- Systemd service: `investment-mcp`
- After any change to `mcp_server.py`: `git pull && systemctl restart investment-mcp`
- `get_daily_gain_loss` uses live prices for today + `hl_account_values_historical` for baseline — same logic as the dashboard widget. `get_account_performance` uses historical snapshots only (lags until midnight cron).

## Git Workflow

- Default branch: `main`
- Commit messages are short and descriptive (no prefix convention enforced).
- Feature branches follow the pattern `claude/<description>-<id>` when created by AI assistants.
- There is no CI/CD pipeline — pushes go directly to the hosting environment.
- **When the user approves pushing** (e.g. "good to go", "push it", "ship it"), automatically stage all changed files, write a descriptive commit message, commit, and push to `origin main` — no need to ask for further confirmation at each step.
