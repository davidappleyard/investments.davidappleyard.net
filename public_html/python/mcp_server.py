#!/usr/bin/env python3
"""
Investment Portfolio MCP Server
================================
Exposes read-only portfolio data as MCP tools for Claude.ai (and any MCP-compatible
client) via the Streamable HTTP transport.

QUICK START
-----------
1. Install dependencies:
       pip install "mcp[cli]" mysql-connector-python uvicorn

2. Add to your .env:
       MCP_PORT=8765
       MCP_HOST=127.0.0.1

3. Test locally:
       cd /path/to/investments.davidappleyard.net
       source .env && python3 python/mcp_server.py

4. Run as a persistent service — copy investment-mcp.service to /etc/systemd/system/,
   edit the paths, then:
       sudo systemctl enable --now investment-mcp

5. Add a nginx location block (replace SECRET with a long random token, e.g. from
   `openssl rand -hex 16`):

       location /mcp-SECRET/ {
           proxy_pass         http://127.0.0.1:8765/;
           proxy_http_version 1.1;
           proxy_set_header   Connection "";
           proxy_set_header   Host $host;
           proxy_buffering    off;
       }

6. Register in Claude.ai → Settings → Connectors:
       https://investments.davidappleyard.net/mcp-SECRET/

TOOLS EXPOSED
-------------
  get_portfolio_summary    — Current value by account (holdings + cash)
  get_holdings             — Per-ticker detail with unrealised gain/loss
  get_account_performance  — Historical gain/loss over a date range
  get_transactions         — Filterable transaction log
  get_dividend_income      — Dividend income with optional grouping
  get_allocation_breakdown — Portfolio breakdown by asset allocation category
"""

import os
import datetime as dt
from typing import Optional

import mysql.connector
from mcp.server.fastmcp import FastMCP

# ── Config ────────────────────────────────────────────────────────────────────

DB_HOST  = os.getenv("DB_HOST", "localhost")
DB_NAME  = os.getenv("DB_NAME", "investments")
DB_USER  = os.getenv("DB_USER", "root")
DB_PASS  = os.getenv("DB_PASS")
MCP_PORT = int(os.getenv("MCP_PORT", "8765"))
MCP_HOST = os.getenv("MCP_HOST", "127.0.0.1")

if DB_PASS is None:
    raise RuntimeError(
        "DB_PASS environment variable must be set. "
        "See .env.example for the full list of required variables."
    )

CLIENTS       = ("David", "Jen")
ACCOUNT_TYPES = ("SIPP", "ISA", "Fund & Share")

mcp = FastMCP("Investment Portfolio")

# ── DB helpers ────────────────────────────────────────────────────────────────

def db_conn():
    """Open a fresh read-only database connection."""
    return mysql.connector.connect(
        host=DB_HOST, user=DB_USER, password=DB_PASS,
        database=DB_NAME, autocommit=True,
    )


def to_gbp(price: float, currency: str) -> float:
    """
    Normalise a price to GBP.
    UK-listed stocks are often quoted in GBp (pence sterling), so divide by 100.
    Other currencies (USD, EUR) are left as-is — the DB doesn't store FX rates.
    """
    return price / 100.0 if currency == "GBp" else price


# ── Filter helpers ────────────────────────────────────────────────────────────

def validate(client: Optional[str], account: Optional[str]) -> None:
    if client is not None and client not in CLIENTS:
        raise ValueError(f"client must be one of {CLIENTS}")
    if account is not None and account not in ACCOUNT_TYPES:
        raise ValueError(f"account_type must be one of {ACCOUNT_TYPES}")


def conditions(
    client: Optional[str],
    account: Optional[str],
    alias: str = "",
) -> tuple[list[str], list]:
    """
    Return ([sql_condition, ...], [param, ...]) for client/account filters.
    Pass alias="t" if the table has an alias in the query (e.g. "t.client_name = %s").
    """
    prefix = f"{alias}." if alias else ""
    clauses, params = [], []
    if client:
        clauses.append(f"{prefix}client_name = %s")
        params.append(client)
    if account:
        clauses.append(f"{prefix}account_type = %s")
        params.append(account)
    return clauses, params


def where_from(clauses: list[str]) -> str:
    """Turn a list of SQL conditions into a WHERE ... fragment (or empty string)."""
    return ("WHERE " + " AND ".join(clauses)) if clauses else ""


def and_from(clauses: list[str]) -> str:
    """Turn a list of SQL conditions into an AND ... fragment (or empty string)."""
    return ("AND " + " AND ".join(clauses)) if clauses else ""


# ── Tools ─────────────────────────────────────────────────────────────────────

@mcp.tool()
def get_portfolio_summary(
    client: Optional[str] = None,
    account_type: Optional[str] = None,
) -> dict:
    """
    Current portfolio value broken down by account.

    Returns holdings value (at latest prices) plus estimated cash balance
    for each client/account combination, and a grand total in GBP.

    Args:
        client: Filter by "David" or "Jen". Omit for combined view.
        account_type: Filter by "SIPP", "ISA", or "Fund & Share". Omit for all.
    """
    validate(client, account_type)
    conn = db_conn()
    cur  = conn.cursor(dictionary=True)
    try:
        f_clauses, f_params = conditions(client, account_type, alias="t")

        # Net holdings per account/ticker
        cur.execute(f"""
            SELECT t.client_name,
                   t.account_type,
                   t.ticker,
                   SUM(CASE WHEN t.type = 'Buy' THEN t.quantity ELSE -t.quantity END) AS net_qty
            FROM hl_transactions t
            WHERE t.type IN ('Buy', 'Sell')
            {and_from(f_clauses)}
            GROUP BY t.client_name, t.account_type, t.ticker
            HAVING net_qty > 0
        """, f_params)
        holdings = cur.fetchall()

        # Latest prices
        cur.execute("SELECT ticker, price, currency FROM hl_prices_latest")
        prices = {r["ticker"]: r for r in cur.fetchall()}

        # Cash balance per account
        c_clauses, c_params = conditions(client, account_type)
        cur.execute(f"""
            SELECT client_name,
                   account_type,
                   SUM(CASE
                       WHEN type IN ('Deposit','Interest','Sell','Dividend','Loyalty Payment') THEN value_gbp
                       WHEN type IN ('Buy','Withdrawal','Fee')                                THEN -ABS(value_gbp)
                       ELSE 0
                   END) AS cash
            FROM hl_transactions
            {where_from(c_clauses)}
            GROUP BY client_name, account_type
        """, c_params)
        cash_map = {
            (r["client_name"], r["account_type"]): float(r["cash"] or 0)
            for r in cur.fetchall()
        }

        # Aggregate
        h_totals: dict = {}
        for h in holdings:
            key    = (h["client_name"], h["account_type"])
            ticker = h["ticker"]
            if ticker in prices:
                p   = prices[ticker]
                val = float(h["net_qty"]) * to_gbp(float(p["price"]), p["currency"])
                h_totals[key] = h_totals.get(key, 0.0) + val

        accounts = []
        grand    = 0.0
        for key in sorted(set(h_totals) | set(cash_map)):
            c_name, acct = key
            h_val  = round(h_totals.get(key, 0.0), 2)
            c_val  = round(cash_map.get(key, 0.0),  2)
            total  = round(h_val + c_val, 2)
            grand += total
            accounts.append({
                "client":       c_name,
                "account":      acct,
                "holdings_gbp": h_val,
                "cash_gbp":     c_val,
                "total_gbp":    total,
            })

        return {
            "accounts":        accounts,
            "grand_total_gbp": round(grand, 2),
            "as_of":           dt.date.today().isoformat(),
        }
    finally:
        cur.close()
        conn.close()


@mcp.tool()
def get_daily_gain_loss(
    client: Optional[str] = None,
    account_type: Optional[str] = None,
) -> dict:
    """
    Today's gain or loss compared to the previous trading day.

    Mirrors the 'Gain/Loss Today' widget on the dashboard: today's value is
    calculated in real-time from live prices (hl_prices_latest), while the
    baseline is the most recent snapshot in hl_account_values_historical
    (falls back to Friday if yesterday was a weekend/holiday).

    Any deposits or withdrawals made today are excluded so they don't inflate
    or deflate the gain/loss figure.

    Args:
        client: Filter by "David" or "Jen". Omit for combined view.
        account_type: Filter by "SIPP", "ISA", or "Fund & Share". Omit for all.
    """
    validate(client, account_type)
    today     = dt.date.today()
    yesterday = today - dt.timedelta(days=1)

    conn = db_conn()
    cur  = conn.cursor(dictionary=True)
    try:
        f_clauses, f_params = conditions(client, account_type, alias="t")
        c_clauses, c_params = conditions(client, account_type)

        # ── Today's value (live prices) ──────────────────────────────────────
        cur.execute(f"""
            SELECT t.client_name, t.account_type, t.ticker,
                   SUM(CASE WHEN t.type = 'Buy' THEN t.quantity ELSE -t.quantity END) AS net_qty
            FROM hl_transactions t
            WHERE t.type IN ('Buy', 'Sell')
            {and_from(f_clauses)}
            GROUP BY t.client_name, t.account_type, t.ticker
            HAVING net_qty > 0
        """, f_params)
        holdings = cur.fetchall()

        cur.execute("SELECT ticker, price, currency FROM hl_prices_latest")
        prices = {r["ticker"]: r for r in cur.fetchall()}

        cur.execute(f"""
            SELECT client_name, account_type,
                   SUM(CASE
                       WHEN type IN ('Deposit','Interest','Sell','Dividend','Loyalty Payment') THEN value_gbp
                       WHEN type IN ('Buy','Withdrawal','Fee') THEN -ABS(value_gbp)
                       ELSE 0
                   END) AS cash
            FROM hl_transactions
            {where_from(c_clauses)}
            GROUP BY client_name, account_type
        """, c_params)
        cash_map = {(r["client_name"], r["account_type"]): float(r["cash"] or 0)
                    for r in cur.fetchall()}

        holdings_value = 0.0
        for h in holdings:
            ticker = h["ticker"]
            if ticker in prices:
                p = prices[ticker]
                holdings_value += float(h["net_qty"]) * to_gbp(float(p["price"]), p["currency"])

        total_cash = sum(cash_map.values())
        current_total = holdings_value + total_cash

        # ── Today's deposits/withdrawals (excluded from gain/loss) ───────────
        d_today_clauses = list(c_clauses) + [
            "type IN ('Deposit', 'Withdrawal')",
            "trade_date = %s",
        ]
        cur.execute(f"""
            SELECT COALESCE(SUM(value_gbp), 0) AS net
            FROM hl_transactions
            {where_from(d_today_clauses)}
        """, c_params + [today.isoformat()])
        today_deposits = float(cur.fetchone()["net"] or 0)

        # ── Yesterday's value (most recent snapshot on or before yesterday) ──
        cur.execute(f"""
            SELECT MAX(trade_date) AS latest
            FROM hl_account_values_historical
            WHERE trade_date <= %s
            {and_from(c_clauses)}
        """, [yesterday.isoformat()] + c_params)
        row = cur.fetchone()
        baseline_date = row["latest"] if row else None

        baseline_total = 0.0
        if baseline_date:
            cur.execute(f"""
                SELECT SUM(total_value_gbp) AS total
                FROM hl_account_values_historical
                WHERE trade_date = %s
                {and_from(c_clauses)}
            """, [baseline_date] + c_params)
            baseline_total = float(cur.fetchone()["total"] or 0)

        gain_loss = (current_total - today_deposits) - baseline_total
        pct       = (gain_loss / baseline_total * 100) if baseline_total else 0.0

        return {
            "current_value_gbp":   round(current_total, 2),
            "baseline_value_gbp":  round(baseline_total, 2),
            "baseline_date":       baseline_date.isoformat() if baseline_date else None,
            "today_deposits_gbp":  round(today_deposits, 2),
            "gain_loss_gbp":       round(gain_loss, 2),
            "gain_loss_pct":       round(pct, 4),
            "as_of":               today.isoformat(),
        }
    finally:
        cur.close()
        conn.close()


@mcp.tool()
def get_holdings(
    client: Optional[str] = None,
    account_type: Optional[str] = None,
) -> dict:
    """
    Detailed current holdings with unrealised gain/loss per position.

    For each position: ticker, fund name, quantity, average cost, current price,
    current value, unrealised gain/loss (£ and %), allocation category,
    and dividend yield.

    Args:
        client: Filter by "David" or "Jen". Omit for both.
        account_type: Filter by "SIPP", "ISA", or "Fund & Share". Omit for all.
    """
    validate(client, account_type)
    conn = db_conn()
    cur  = conn.cursor(dictionary=True)
    try:
        f_clauses, f_params = conditions(client, account_type, alias="t")

        cur.execute(f"""
            SELECT
                t.client_name,
                t.account_type,
                t.ticker,
                MAX(t.description)                                                        AS description,
                SUM(CASE WHEN t.type = 'Buy' THEN t.quantity  ELSE 0            END)     AS total_bought_qty,
                SUM(CASE WHEN t.type = 'Buy' THEN t.quantity  ELSE -t.quantity  END)     AS net_qty,
                SUM(CASE WHEN t.type = 'Buy' THEN t.value_gbp ELSE 0            END)     AS total_cost_gbp,
                s.target_allocation,
                p.price     AS latest_price,
                p.currency,
                y.dividend_yield
            FROM hl_transactions t
            LEFT JOIN hl_ticker_symbols s ON t.ticker COLLATE utf8mb4_unicode_ci = s.ticker COLLATE utf8mb4_unicode_ci
            LEFT JOIN hl_prices_latest  p ON t.ticker COLLATE utf8mb4_unicode_ci = p.ticker COLLATE utf8mb4_unicode_ci
            LEFT JOIN hl_yield_latest   y ON t.ticker COLLATE utf8mb4_unicode_ci = y.ticker COLLATE utf8mb4_unicode_ci
            WHERE t.type IN ('Buy', 'Sell')
            {and_from(f_clauses)}
            GROUP BY t.client_name, t.account_type, t.ticker,
                     s.target_allocation, p.price, p.currency, y.dividend_yield
            HAVING net_qty > 0
            ORDER BY t.client_name, t.account_type, t.ticker
        """, f_params)
        rows = cur.fetchall()

        holdings     = []
        total_value  = 0.0
        total_cost   = 0.0

        for r in rows:
            net_qty       = float(r["net_qty"])
            total_bought  = float(r["total_bought_qty"] or 0)
            cost_gbp      = float(r["total_cost_gbp"]   or 0)
            avg_cost      = cost_gbp / total_bought if total_bought else 0.0

            raw_price     = float(r["latest_price"]) if r["latest_price"] is not None else None
            currency      = r["currency"] or "GBP"
            price_gbp     = to_gbp(raw_price, currency) if raw_price is not None else None

            current_value = net_qty * price_gbp  if price_gbp  is not None else None
            cost_basis    = net_qty * avg_cost

            if current_value is not None:
                unreal_gbp = round(current_value - cost_basis, 2)
                unreal_pct = round(unreal_gbp / cost_basis * 100, 2) if cost_basis > 0 else None
                total_value += current_value
            else:
                unreal_gbp = unreal_pct = None

            total_cost += cost_basis

            holdings.append({
                "client":               r["client_name"],
                "account":              r["account_type"],
                "ticker":               r["ticker"],
                "description":          r["description"],
                "quantity":             round(net_qty, 4),
                "avg_cost_gbp":         round(avg_cost, 4),
                "latest_price":         round(raw_price, 4) if raw_price is not None else None,
                "price_currency":       currency,
                "current_value_gbp":    round(current_value, 2) if current_value is not None else None,
                "cost_basis_gbp":       round(cost_basis, 2),
                "unrealised_gain_gbp":  unreal_gbp,
                "unrealised_gain_pct":  unreal_pct,
                "allocation":           r["target_allocation"],
                "dividend_yield_pct":   round(float(r["dividend_yield"]), 2) if r["dividend_yield"] else None,
            })

        return {
            "holdings":                 holdings,
            "total_current_value_gbp":  round(total_value, 2),
            "total_cost_basis_gbp":     round(total_cost,  2),
            "total_unrealised_gain_gbp": round(total_value - total_cost, 2),
            "as_of":                    dt.date.today().isoformat(),
        }
    finally:
        cur.close()
        conn.close()


@mcp.tool()
def get_account_performance(
    client: Optional[str] = None,
    account_type: Optional[str] = None,
    date_from: Optional[str] = None,
    date_to: Optional[str] = None,
) -> dict:
    """
    Portfolio performance over a date range using stored daily valuations.

    Compares the portfolio value at the start vs end of the period, and adjusts
    for any deposits or withdrawals to show true investment gain/loss.

    Args:
        client: Filter by "David" or "Jen". Omit for combined view.
        account_type: Filter by "SIPP", "ISA", or "Fund & Share". Omit for all.
        date_from: Start of period (YYYY-MM-DD). Defaults to start of current UK tax year.
        date_to: End of period (YYYY-MM-DD). Defaults to today.
    """
    validate(client, account_type)
    today          = dt.date.today()
    tax_year_start = dt.date(today.year if today.month >= 4 else today.year - 1, 4, 6)
    date_from      = date_from or tax_year_start.isoformat()
    date_to        = date_to   or today.isoformat()

    conn = db_conn()
    cur  = conn.cursor(dictionary=True)
    try:
        h_clauses, h_params = conditions(client, account_type)

        def value_at(date: str) -> float:
            """Portfolio value on the most recent date with data on or before `date`."""
            cur.execute(f"""
                SELECT MAX(trade_date) AS latest
                FROM hl_account_values_historical
                WHERE trade_date <= %s
                {and_from(h_clauses)}
            """, [date] + h_params)
            row = cur.fetchone()
            latest = row["latest"] if row else None
            if not latest:
                return 0.0
            cur.execute(f"""
                SELECT SUM(total_value_gbp) AS total
                FROM hl_account_values_historical
                WHERE trade_date = %s
                {and_from(h_clauses)}
            """, [latest] + h_params)
            row = cur.fetchone()
            return float(row["total"] or 0)

        start_value = value_at(date_from)
        end_value   = value_at(date_to)

        # Net deposits/withdrawals strictly within the period
        d_clauses = list(h_clauses) + [
            "type IN ('Deposit', 'Withdrawal')",
            "trade_date > %s",
            "trade_date <= %s",
        ]
        cur.execute(f"""
            SELECT SUM(value_gbp) AS net_deposits
            FROM hl_transactions
            {where_from(d_clauses)}
        """, h_params + [date_from, date_to])
        row          = cur.fetchone()
        net_deposits = float(row["net_deposits"] or 0)

        gain_loss     = end_value - start_value - net_deposits
        gain_loss_pct = round(gain_loss / start_value * 100, 2) if start_value > 0 else None

        return {
            "date_from":        date_from,
            "date_to":          date_to,
            "start_value_gbp":  round(start_value,  2),
            "end_value_gbp":    round(end_value,    2),
            "net_deposits_gbp": round(net_deposits, 2),
            "gain_loss_gbp":    round(gain_loss,    2),
            "gain_loss_pct":    gain_loss_pct,
        }
    finally:
        cur.close()
        conn.close()


@mcp.tool()
def get_transactions(
    client: Optional[str] = None,
    account_type: Optional[str] = None,
    ticker: Optional[str] = None,
    transaction_type: Optional[str] = None,
    date_from: Optional[str] = None,
    date_to: Optional[str] = None,
    limit: int = 50,
) -> dict:
    """
    Filterable transaction history.

    Args:
        client: Filter by "David" or "Jen". Omit for both.
        account_type: Filter by "SIPP", "ISA", or "Fund & Share". Omit for all.
        ticker: Filter by ticker symbol (e.g. "VWRL").
        transaction_type: Filter by type — "Buy", "Sell", "Dividend", "Deposit",
                          "Withdrawal", "Interest", "Fee", etc.
        date_from: Earliest trade date (YYYY-MM-DD). Omit for no lower bound.
        date_to: Latest trade date (YYYY-MM-DD). Omit for today.
        limit: Maximum rows to return (default 50, max 500).
    """
    validate(client, account_type)
    limit = min(max(1, limit), 500)

    conn = db_conn()
    cur  = conn.cursor(dictionary=True)
    try:
        clauses, params = conditions(client, account_type)

        if ticker:
            clauses.append("ticker = %s")
            params.append(ticker.upper())
        if transaction_type:
            clauses.append("type = %s")
            params.append(transaction_type)
        if date_from:
            clauses.append("trade_date >= %s")
            params.append(date_from)
        if date_to:
            clauses.append("trade_date <= %s")
            params.append(date_to)

        cur.execute(f"""
            SELECT trade_date, client_name, account_type, type,
                   ticker, description, quantity, price_per_share, value_gbp
            FROM hl_transactions
            {where_from(clauses)}
            ORDER BY trade_date DESC, id DESC
            LIMIT %s
        """, params + [limit])

        rows = cur.fetchall()
        transactions = []
        for r in rows:
            transactions.append({
                "date":            r["trade_date"].isoformat() if hasattr(r["trade_date"], "isoformat") else str(r["trade_date"]),
                "client":          r["client_name"],
                "account":         r["account_type"],
                "type":            r["type"],
                "ticker":          r["ticker"],
                "description":     r["description"],
                "quantity":        float(r["quantity"])        if r["quantity"]        is not None else None,
                "price_per_share": float(r["price_per_share"]) if r["price_per_share"] is not None else None,
                "value_gbp":       float(r["value_gbp"])       if r["value_gbp"]       is not None else None,
            })

        return {
            "transactions": transactions,
            "count":        len(transactions),
            "limit_applied": limit,
        }
    finally:
        cur.close()
        conn.close()


@mcp.tool()
def get_dividend_income(
    client: Optional[str] = None,
    account_type: Optional[str] = None,
    date_from: Optional[str] = None,
    date_to: Optional[str] = None,
    group_by: Optional[str] = None,
) -> dict:
    """
    Dividend income over a date range, with optional grouping.

    Args:
        client: Filter by "David" or "Jen". Omit for both.
        account_type: Filter by "SIPP", "ISA", or "Fund & Share". Omit for all.
        date_from: Start date (YYYY-MM-DD). Defaults to start of current UK tax year.
        date_to: End date (YYYY-MM-DD). Defaults to today.
        group_by: Optional breakdown — "ticker", "month", or "year".
                  Omit for a single total.
    """
    validate(client, account_type)

    today          = dt.date.today()
    tax_year_start = dt.date(today.year if today.month >= 4 else today.year - 1, 4, 6)
    date_from      = date_from or tax_year_start.isoformat()
    date_to        = date_to   or today.isoformat()

    if group_by is not None and group_by not in ("ticker", "month", "year"):
        raise ValueError("group_by must be 'ticker', 'month', 'year', or omitted")

    conn = db_conn()
    cur  = conn.cursor(dictionary=True)
    try:
        clauses, params = conditions(client, account_type)
        clauses += ["type = 'Dividend'", "trade_date >= %s", "trade_date <= %s"]
        params  += [date_from, date_to]
        wh       = where_from(clauses)

        if group_by == "ticker":
            cur.execute(f"""
                SELECT ticker, MAX(description) AS description,
                       SUM(value_gbp) AS total_gbp, COUNT(*) AS payments
                FROM hl_transactions {wh}
                GROUP BY ticker
                ORDER BY total_gbp DESC
            """, params)
            breakdown = [
                {
                    "ticker":      r["ticker"],
                    "description": r["description"],
                    "total_gbp":   round(float(r["total_gbp"]), 2),
                    "payments":    r["payments"],
                }
                for r in cur.fetchall()
            ]
        elif group_by == "month":
            cur.execute(f"""
                SELECT DATE_FORMAT(trade_date, '%Y-%m') AS month,
                       SUM(value_gbp) AS total_gbp, COUNT(*) AS payments
                FROM hl_transactions {wh}
                GROUP BY month
                ORDER BY month
            """, params)
            breakdown = [
                {
                    "month":     r["month"],
                    "total_gbp": round(float(r["total_gbp"]), 2),
                    "payments":  r["payments"],
                }
                for r in cur.fetchall()
            ]
        elif group_by == "year":
            cur.execute(f"""
                SELECT YEAR(trade_date) AS year,
                       SUM(value_gbp) AS total_gbp, COUNT(*) AS payments
                FROM hl_transactions {wh}
                GROUP BY year
                ORDER BY year
            """, params)
            breakdown = [
                {
                    "year":      int(r["year"]),
                    "total_gbp": round(float(r["total_gbp"]), 2),
                    "payments":  r["payments"],
                }
                for r in cur.fetchall()
            ]
        else:
            breakdown = None

        # Grand total
        cur.execute(f"""
            SELECT SUM(value_gbp) AS total_gbp, COUNT(*) AS payments
            FROM hl_transactions {wh}
        """, params)
        row = cur.fetchone()

        result = {
            "date_from":  date_from,
            "date_to":    date_to,
            "total_gbp":  round(float(row["total_gbp"] or 0), 2),
            "payments":   int(row["payments"] or 0),
        }
        if breakdown is not None:
            result["breakdown"] = breakdown

        return result
    finally:
        cur.close()
        conn.close()


@mcp.tool()
def get_allocation_breakdown(
    client: Optional[str] = None,
    account_type: Optional[str] = None,
) -> dict:
    """
    Portfolio breakdown by asset allocation category (e.g. Global Equity, Bonds, Cash).

    Allocation categories come from the hl_ticker_symbols table.
    Holdings without an allocation are grouped under "Unclassified".

    Args:
        client: Filter by "David" or "Jen". Omit for combined view.
        account_type: Filter by "SIPP", "ISA", or "Fund & Share". Omit for all.
    """
    validate(client, account_type)
    conn = db_conn()
    cur  = conn.cursor(dictionary=True)
    try:
        f_clauses, f_params = conditions(client, account_type, alias="t")

        # Net quantity per ticker (current holdings only)
        cur.execute(f"""
            SELECT t.ticker,
                   SUM(CASE WHEN t.type = 'Buy' THEN t.quantity ELSE -t.quantity END) AS net_qty,
                   COALESCE(s.target_allocation, 'Unclassified') AS allocation
            FROM hl_transactions t
            LEFT JOIN hl_ticker_symbols s ON t.ticker COLLATE utf8mb4_unicode_ci = s.ticker COLLATE utf8mb4_unicode_ci
            WHERE t.type IN ('Buy', 'Sell')
            {and_from(f_clauses)}
            GROUP BY t.ticker, s.target_allocation
            HAVING net_qty > 0
        """, f_params)
        holdings = cur.fetchall()

        # Latest prices
        cur.execute("SELECT ticker, price, currency FROM hl_prices_latest")
        prices = {r["ticker"]: r for r in cur.fetchall()}

        # Aggregate by allocation
        alloc_totals: dict[str, float] = {}
        grand = 0.0
        for h in holdings:
            ticker = h["ticker"]
            alloc  = h["allocation"] or "Unclassified"
            if ticker in prices:
                p   = prices[ticker]
                val = float(h["net_qty"]) * to_gbp(float(p["price"]), p["currency"])
                alloc_totals[alloc] = alloc_totals.get(alloc, 0.0) + val
                grand += val

        breakdown = [
            {
                "allocation":   alloc,
                "value_gbp":    round(val, 2),
                "percentage":   round(val / grand * 100, 1) if grand > 0 else 0.0,
            }
            for alloc, val in sorted(alloc_totals.items(), key=lambda x: -x[1])
        ]

        return {
            "breakdown":       breakdown,
            "total_value_gbp": round(grand, 2),
            "as_of":           dt.date.today().isoformat(),
        }
    finally:
        cur.close()
        conn.close()


# ── Entry point ───────────────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn
    print(f"Starting Investment Portfolio MCP server on {MCP_HOST}:{MCP_PORT}")
    app = mcp.streamable_http_app()
    uvicorn.run(app, host=MCP_HOST, port=MCP_PORT, log_level="warning")
