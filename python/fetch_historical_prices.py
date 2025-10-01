#!/usr/bin/env python3
"""
Historical Price Fetcher
Fetches end-of-day prices for all active tickers from 2015-06-26 to yesterday.
This is a one-time script to populate historical data.

Usage: python3 fetch_historical_prices.py
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

def get_historical_prices(symbol: str, start_date: dt.date, end_date: dt.date) -> Optional[yf.Ticker]:
    """
    Fetch historical prices for a symbol between start and end dates.
    Returns the yfinance Ticker object with history data, or None if failed.
    """
    try:
        ticker = yf.Ticker(symbol)
        
        # Fetch daily historical data
        # yfinance expects datetime objects, not date objects
        start_dt = dt.datetime.combine(start_date, dt.time())
        end_dt = dt.datetime.combine(end_date, dt.time())
        
        hist = ticker.history(start=start_dt, end=end_dt, interval="1d")
        
        if hist.empty:
            print(f"[WARN] No historical data for {symbol}")
            return None
            
        return ticker
        
    except Exception as e:
        print(f"[ERROR] Failed to fetch historical data for {symbol}: {e}")
        return None

def store_historical_prices(cursor, ticker: str, yahoo_symbol: str, currency: str, 
                          hist_data, start_date: dt.date, end_date: dt.date) -> int:
    """
    Store historical price data in the database.
    Returns the number of records inserted.
    """
    inserted_count = 0
    
    try:
        # Process each day's data
        for date, row in hist_data.iterrows():
            # Convert pandas timestamp to date
            trade_date = date.date()
            
            # Skip if outside our desired range
            if trade_date < start_date or trade_date > end_date:
                continue
                
            price = float(row['Close'])
            
            # Insert or update the price
            cursor.execute("""
                INSERT INTO hl_prices_historical (ticker, yahoo_symbol, price, currency, trade_date)
                VALUES (%s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    price = VALUES(price),
                    yahoo_symbol = VALUES(yahoo_symbol)
            """, (ticker, yahoo_symbol, price, currency, trade_date))
            
            inserted_count += 1
            
    except Exception as e:
        print(f"[ERROR] Failed to store data for {ticker}: {e}")
        return 0
        
    return inserted_count

def main():
    """Main function to fetch and store historical prices."""
    print("Historical Price Fetcher")
    print("=" * 50)
    
    # Define date range
    start_date = dt.date(2015, 6, 26)
    end_date = dt.date.today() - dt.timedelta(days=1)  # Yesterday
    
    print(f"Date range: {start_date} to {end_date}")
    print(f"Total days: {(end_date - start_date).days + 1}")
    
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
    
    total_inserted = 0
    successful_symbols = 0
    
    # Process each symbol
    for i, (ticker, yahoo_symbol, currency) in enumerate(symbols, 1):
        print(f"[{i}/{len(symbols)}] Processing {ticker} ({yahoo_symbol})...")
        
        # Fetch historical data
        ticker_obj = get_historical_prices(yahoo_symbol, start_date, end_date)
        if ticker_obj is None:
            print(f"  [SKIP] No data available")
            continue
            
        # Get the history data
        try:
            start_dt = dt.datetime.combine(start_date, dt.time())
            end_dt = dt.datetime.combine(end_date, dt.time())
            hist_data = ticker_obj.history(start=start_dt, end=end_dt, interval="1d")
        except Exception as e:
            print(f"  [ERROR] Failed to get history data: {e}")
            continue
        
        if hist_data.empty:
            print(f"  [SKIP] No historical data")
            continue
        
        # Store in database
        inserted = store_historical_prices(cursor, ticker, yahoo_symbol, currency, 
                                         hist_data, start_date, end_date)
        
        if inserted > 0:
            print(f"  [OK] Inserted {inserted} price records")
            total_inserted += inserted
            successful_symbols += 1
        else:
            print(f"  [SKIP] No records inserted")
        
        # Rate limiting - be respectful to yfinance
        if i < len(symbols):  # Don't sleep after the last symbol
            print(f"  [WAIT] Sleeping 2 seconds...")
            time.sleep(2)
        
        print()
    
    # Summary
    print("=" * 50)
    print("SUMMARY")
    print(f"Symbols processed: {successful_symbols}/{len(symbols)}")
    print(f"Total price records inserted: {total_inserted}")
    print(f"Date range: {start_date} to {end_date}")
    
    cursor.close()
    conn.close()
    
    return 0

if __name__ == "__main__":
    sys.exit(main())
