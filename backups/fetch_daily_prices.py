#!/usr/bin/env python3
"""
Daily Price Fetcher
Fetches end-of-day prices for all active tickers for the current trading day.
This script is designed to be run daily via cron after market close (6pm).

Usage: python3 fetch_daily_prices.py
Cron example: 0 18 * * 1-5 /path/to/python3 /path/to/fetch_daily_prices.py >> /path/to/logs/daily_prices.log 2>&1
"""

import os
import sys
import time
import datetime as dt
from typing import List, Tuple, Optional

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

def fetch_active_symbols(cursor) -> List[Tuple[str, str, str]]:
    """Get all active ticker symbols from the database."""
    cursor.execute("""
        SELECT ticker, yahoo_symbol, currency
        FROM hl_ticker_symbols
        WHERE is_active = 1
        ORDER BY ticker
    """)
    return cursor.fetchall()

def get_current_trading_day() -> dt.date:
    """
    Get the current trading day (today, or Friday if today is weekend).
    This is a simple implementation - for production you might want to use
    a proper trading calendar library.
    """
    today = dt.date.today()
    
    # If today is Saturday (5) or Sunday (6), get Friday's data
    if today.weekday() >= 5:  # Weekend
        days_back = today.weekday() - 4  # Saturday=1, Sunday=2
        return today - dt.timedelta(days=days_back)
    else:
        return today

def get_daily_price(symbol: str, target_date: dt.date) -> Optional[Tuple[float, dt.datetime]]:
    """
    Fetch the closing price for a symbol on a specific date.
    Returns (price, timestamp) or (None, None) if unavailable.
    """
    try:
        ticker = yf.Ticker(symbol)
        
        # Get data for a range around the target date to ensure we get the right day
        start_date = target_date - dt.timedelta(days=5)
        end_date = target_date + dt.timedelta(days=2)
        
        start_dt = dt.datetime.combine(start_date, dt.time())
        end_dt = dt.datetime.combine(end_date, dt.time())
        
        hist = ticker.history(start=start_dt, end=end_dt, interval="1d")
        
        if hist.empty:
            return None, None
        
        # Find the row for our target date
        for date, row in hist.iterrows():
            if date.date() == target_date:
                price = float(row['Close'])
                # Convert to UTC timestamp
                timestamp = dt.datetime.combine(target_date, dt.time(16, 0))  # Assume 4 PM close
                timestamp = UTC.localize(timestamp)
                return price, timestamp
        
        return None, None
        
    except Exception as e:
        print(f"[ERROR] Failed to fetch price for {symbol} on {target_date}: {e}")
        return None, None

def store_daily_price(cursor, ticker: str, yahoo_symbol: str, currency: str, 
                     price: float, trade_date: dt.date) -> bool:
    """
    Store a daily price in the historical prices table.
    Returns True if successful, False otherwise.
    """
    try:
        cursor.execute("""
            INSERT INTO hl_prices_historical (ticker, yahoo_symbol, price, currency, trade_date)
            VALUES (%s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                price = VALUES(price),
                yahoo_symbol = VALUES(yahoo_symbol)
        """, (ticker, yahoo_symbol, price, currency, trade_date))
        
        return True
        
    except Exception as e:
        print(f"[ERROR] Failed to store price for {ticker}: {e}")
        return False

def update_latest_price(cursor, ticker: str, yahoo_symbol: str, currency: str, 
                       price: float, timestamp: dt.datetime) -> bool:
    """
    Update the latest prices table with the new daily price.
    Returns True if successful, False otherwise.
    """
    try:
        cursor.execute("""
            INSERT INTO hl_prices_latest (ticker, yahoo_symbol, price, currency, asof_utc, source)
            VALUES (%s, %s, %s, %s, %s, 'yfinance_daily')
            ON DUPLICATE KEY UPDATE
                yahoo_symbol = VALUES(yahoo_symbol),
                price        = VALUES(price),
                currency     = VALUES(currency),
                asof_utc     = VALUES(asof_utc),
                source       = 'yfinance_daily'
        """, (ticker, yahoo_symbol, price, currency, timestamp.strftime("%Y-%m-%d %H:%M:%S")))
        
        return True
        
    except Exception as e:
        print(f"[ERROR] Failed to update latest price for {ticker}: {e}")
        return False

def main():
    """Main function to fetch and store daily prices."""
    print(f"Daily Price Fetcher - {dt.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)
    
    # Get the target date (current trading day)
    target_date = get_current_trading_day()
    print(f"Target date: {target_date} ({target_date.strftime('%A')})")
    
    # Connect to database
    try:
        conn = db_conn()
        cursor = conn.cursor()
    except Exception as e:
        print(f"[ERROR] Failed to connect to database: {e}")
        return 1
    
    # Get active symbols
    try:
        symbols = fetch_active_symbols(cursor)
        if not symbols:
            print("No active symbols found; exiting.")
            return 0
    except Exception as e:
        print(f"[ERROR] Failed to fetch symbols: {e}")
        return 1
    
    print(f"Found {len(symbols)} active symbols")
    print()
    
    successful_count = 0
    failed_count = 0
    
    # Process each symbol
    for i, (ticker, yahoo_symbol, currency) in enumerate(symbols, 1):
        print(f"[{i}/{len(symbols)}] Processing {ticker} ({yahoo_symbol})...")
        
        # Fetch daily price
        price, timestamp = get_daily_price(yahoo_symbol, target_date)
        
        if price is None:
            print(f"  [MISS] No price data for {target_date}")
            failed_count += 1
            continue
        
        # Store in historical prices table
        hist_success = store_daily_price(cursor, ticker, yahoo_symbol, currency, price, target_date)
        
        # Update latest prices table
        latest_success = update_latest_price(cursor, ticker, yahoo_symbol, currency, price, timestamp)
        
        if hist_success and latest_success:
            print(f"  [OK] Price: {price} {currency} @ {timestamp.strftime('%Y-%m-%d %H:%M:%S')} UTC")
            successful_count += 1
        else:
            print(f"  [ERROR] Failed to store price data")
            failed_count += 1
        
        # Small delay to be respectful to yfinance
        if i < len(symbols):
            time.sleep(1)
    
    # Summary
    print()
    print("=" * 60)
    print("SUMMARY")
    print(f"Target date: {target_date}")
    print(f"Symbols processed: {successful_count}/{len(symbols)}")
    print(f"Successful: {successful_count}")
    print(f"Failed: {failed_count}")
    print(f"Completed at: {dt.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    
    cursor.close()
    conn.close()
    
    return 0 if failed_count == 0 else 1

if __name__ == "__main__":
    sys.exit(main())
