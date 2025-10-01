#!/usr/bin/env python3
import os
import sys
import time
import datetime as dt

import pytz
import yfinance as yf
import mysql.connector

# --- Config: prefer env vars in production
DB_HOST = os.getenv("DB_HOST", "localhost")
DB_NAME = os.getenv("DB_NAME", "investments")
DB_USER = os.getenv("DB_USER", "root")
DB_PASS = os.getenv("DB_PASS", "gN6mCgrP!Gi6z9gxp")

UTC = pytz.UTC

def db_conn():
    return mysql.connector.connect(
        host=DB_HOST, user=DB_USER, password=DB_PASS, database=DB_NAME, autocommit=True
    )

def fetch_active_symbols(cursor):
    cursor.execute("""
        SELECT ticker, yahoo_symbol, currency
        FROM hl_ticker_symbols
        WHERE is_active = 1
        ORDER BY ticker
    """)
    return cursor.fetchall()  # list of tuples

def get_latest_price(symbol):
    """
    Use yfinance to get the latest close/last trade price.
    Strategy: try '1d'/'1m' intraday tail, then fallback to fast_info or last close.
    Returns (price, asof_utc) or (None, None) if unavailable.
    """
    try:
        t = yf.Ticker(symbol)

        # Try intraday 1-minute latest candle
        hist = t.history(period="1d", interval="1m")
        if not hist.empty:
            last = hist.tail(1).iloc[0]
            price = float(last.get("Close"))
            ts = last.name.to_pydatetime()
            # Ensure UTC:
            if ts.tzinfo is None:
                ts = UTC.localize(ts)
            ts_utc = ts.astimezone(UTC)
            return price, ts_utc

        # Fallbacks:
        fi = getattr(t, "fast_info", None)
        if fi:
            # fast_info has last_price sometimes
            price = getattr(fi, "last_price", None)
            if price is not None:
                return float(price), dt.datetime.now(tz=UTC)

        # Fallback to last close daily
        hist = t.history(period="5d", interval="1d")
        if not hist.empty:
            last = hist.tail(1).iloc[0]
            price = float(last.get("Close"))
            ts = last.name.to_pydatetime()
            if ts.tzinfo is None:
                ts = UTC.localize(ts)
            ts_utc = ts.astimezone(UTC)
            return price, ts_utc

    except Exception as e:
        print(f"[WARN] {symbol}: {e}", file=sys.stderr)

    return None, None

def upsert_price(cursor, ticker, symbol, currency, price, asof_utc):
    cursor.execute("""
        INSERT INTO hl_prices_latest (ticker, yahoo_symbol, price, currency, asof_utc, source)
        VALUES (%s, %s, %s, %s, %s, 'yfinance')
        ON DUPLICATE KEY UPDATE
            yahoo_symbol = VALUES(yahoo_symbol),
            price        = VALUES(price),
            currency     = VALUES(currency),
            asof_utc     = VALUES(asof_utc),
            source       = 'yfinance';
    """, (ticker, symbol, price, currency, asof_utc.strftime("%Y-%m-%d %H:%M:%S")))

def main():
    conn = db_conn()
    cur = conn.cursor()
    rows = fetch_active_symbols(cur)
    if not rows:
        print("No active symbols found; exiting.")
        return

    print(f"Fetching {len(rows)} symbols...")
    for ticker, symbol, currency in rows:
        price, asof_utc = get_latest_price(symbol)
        if price is None:
            print(f"[MISS] {ticker} ({symbol})")
            continue
        upsert_price(cur, ticker, symbol, currency, price, asof_utc)
        print(f"[OK] {ticker}={price} {currency} @ {asof_utc.isoformat()}")

    cur.close()
    conn.close()

if __name__ == "__main__":
    main()