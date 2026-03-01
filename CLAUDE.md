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

```
investments.davidappleyard.net/
├── index.php                      # Main application (~3,700 lines) — dashboard, reports, CSV import
├── login.php                      # Login page
├── auth.php                       # Authentication helpers + table creation (users, remember_tokens)
├── change_password.php            # Password change form
├── settings.php                   # Ticker/allocation management
│
├── python/
│   ├── fetch_daily_prices.py      # Cron: fetch latest prices after market close (weekdays 18:00)
│   ├── fetch_dividend_yields.py   # Cron: fetch dividend yields (weekdays 19:00)
│   ├── fetch_historical_prices.py # One-time: backfill historical price data
│   └── fetch_prices.py            # Utility: manual price fetching
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

Database connection constants are defined at the top of each PHP file:

```php
const DB_HOST = 'localhost';
const DB_NAME = 'investments';
const DB_USER = 'root';
const DB_PASS = 'gN6mCgrP!Gi6z9gxp';
const DB_CHARSET = 'utf8mb4';
```

Python scripts support environment variable overrides:
```python
DB_HOST = os.getenv("DB_HOST", "localhost")
# DB_NAME, DB_USER, DB_PASS follow the same pattern
```

## Development Workflow

### Running the Application

No build step required. The application runs directly under a PHP web server (Apache/Nginx) or PHP CLI's built-in server:

```bash
php -S localhost:8080
# Then open http://localhost:8080/index.php
```

Ensure the `investments` MySQL database exists and the DB user has full access to it. Tables are created automatically on first load by `auth.php`.

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

## Git Workflow

- Default branch: `master`
- Commit messages are short and descriptive (no prefix convention enforced).
- Feature branches follow the pattern `claude/<description>-<id>` when created by AI assistants.
- There is no CI/CD pipeline — pushes go directly to the hosting environment.
