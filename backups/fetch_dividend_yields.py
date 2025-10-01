#!/usr/bin/env python3
"""
Dividend Yield Fetcher
Fetches the latest dividend yield information for all active ticker symbols.
This script is designed to be run daily via cron.

Usage: python3 fetch_dividend_yields.py
Cron example: 0 19 * * 1-5 /path/to/python3 /path/to/fetch_dividend_yields.py >> /path/to/logs/dividend_yields.log 2>&1
"""

import os
import sys
import time
import datetime as dt
from typing import List, Tuple, Optional, Dict, Any

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

def get_dividend_yield_info(symbol: str) -> Optional[Dict[str, Any]]:
    """
    Fetch dividend yield information for a symbol.
    Returns dict with yield info or None if failed.
    """
    try:
        ticker = yf.Ticker(symbol)
        
        # Get basic info which includes dividend yield
        info = ticker.info
        
        # Extract dividend yield (yfinance already returns as percentage)
        dividend_yield = info.get('dividendYield')
        if dividend_yield is not None:
            dividend_yield = float(dividend_yield)  # Already a percentage from yfinance
            
            # Sanity check: yield should be reasonable (0-50%)
            if dividend_yield > 50.0:
                print(f"  [WARN] Unrealistic yield {dividend_yield:.2f}% - likely data error, skipping")
                return None
        
        # Extract dividend rate (annual dividend per share)
        dividend_rate = info.get('dividendRate')
        if dividend_rate is not None:
            dividend_rate = float(dividend_rate)
        
        # Get currency
        currency = info.get('currency', 'GBP')
        if currency:
            currency = currency.upper()
        
        # Try alternative methods for ETFs that might not have yield in info
        if dividend_yield is None:
            # Try getting dividend history to calculate yield
            try:
                # Get last 12 months of dividends
                dividends = ticker.dividends.tail(4)  # Last 4 quarters
                if not dividends.empty:
                    annual_dividend = dividends.sum()
                    current_price = info.get('currentPrice') or info.get('regularMarketPrice')
                    
                    if current_price and annual_dividend > 0:
                        dividend_yield = (annual_dividend / float(current_price)) * 100
                        dividend_rate = annual_dividend
                        print(f"  [CALC] Calculated yield from dividend history: {dividend_yield:.2f}%")
            except Exception as calc_e:
                print(f"  [DEBUG] Could not calculate yield from history: {calc_e}")
        
        # Only return if we have at least dividend yield and it's reasonable
        if dividend_yield is not None and dividend_yield <= 50.0:
            return {
                'dividend_yield': dividend_yield,
                'dividend_rate': dividend_rate,
                'currency': currency
            }
        
        return None
        
    except Exception as e:
        print(f"[ERROR] Failed to fetch dividend info for {symbol}: {e}")
        return None

def upsert_dividend_yield(cursor, ticker: str, yahoo_symbol: str, currency: str, 
                         dividend_yield: float, dividend_rate: Optional[float] = None) -> bool:
    """
    Insert or update dividend yield information in the database.
    Returns True if successful, False otherwise.
    """
    try:
        cursor.execute("""
            INSERT INTO hl_yield_latest (ticker, yahoo_symbol, dividend_yield, dividend_rate, currency, asof_utc, source)
            VALUES (%s, %s, %s, %s, %s, %s, 'yfinance')
            ON DUPLICATE KEY UPDATE
                yahoo_symbol = VALUES(yahoo_symbol),
                dividend_yield = VALUES(dividend_yield),
                dividend_rate = VALUES(dividend_rate),
                currency = VALUES(currency),
                asof_utc = VALUES(asof_utc),
                source = 'yfinance',
                updated_at = CURRENT_TIMESTAMP
        """, (ticker, yahoo_symbol, dividend_yield, dividend_rate, currency, 
              dt.datetime.now(tz=UTC).strftime("%Y-%m-%d %H:%M:%S")))
        
        return True
        
    except Exception as e:
        print(f"[ERROR] Failed to store dividend yield for {ticker}: {e}")
        return False

def main():
    """Main function to fetch and store dividend yields."""
    print(f"Dividend Yield Fetcher - {dt.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)
    
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
    no_yield_count = 0
    
        # Process each symbol
    for i, (ticker, yahoo_symbol, currency) in enumerate(symbols, 1):
        print(f"[{i}/{len(symbols)}] Processing {ticker} ({yahoo_symbol})...")
        
        # Fetch dividend yield info
        yield_info = get_dividend_yield_info(yahoo_symbol)
        
        # Debug: show what data we got
        if yield_info is None:
            try:
                debug_ticker = yf.Ticker(yahoo_symbol)
                debug_info = debug_ticker.info
                has_yield = 'dividendYield' in debug_info and debug_info['dividendYield'] is not None
                has_dividends = not debug_ticker.dividends.empty
                print(f"  [DEBUG] Has yield in info: {has_yield}, Has dividend history: {has_dividends}")
                if has_yield:
                    raw_yield = debug_info.get('dividendYield', 'None')
                    print(f"  [DEBUG] Raw yield value: {raw_yield}")
            except Exception as debug_e:
                print(f"  [DEBUG] Could not get debug info: {debug_e}")
        
        if yield_info is None:
            print(f"  [NO YIELD] No dividend yield data available")
            no_yield_count += 1
            continue
        
        dividend_yield = yield_info['dividend_yield']
        dividend_rate = yield_info.get('dividend_rate')
        currency = yield_info.get('currency', currency)
        
        # Store in database
        success = upsert_dividend_yield(cursor, ticker, yahoo_symbol, currency, 
                                      dividend_yield, dividend_rate)
        
        if success:
            rate_str = f" (Rate: {dividend_rate:.4f})" if dividend_rate else ""
            print(f"  [OK] Yield: {dividend_yield:.2f}%{rate_str} {currency}")
            successful_count += 1
        else:
            print(f"  [ERROR] Failed to store dividend yield data")
            failed_count += 1
        
        # Small delay to be respectful to yfinance
        if i < len(symbols):
            time.sleep(1)
    
    # Summary
    print()
    print("=" * 60)
    print("SUMMARY")
    print(f"Symbols processed: {successful_count + failed_count + no_yield_count}/{len(symbols)}")
    print(f"Successful: {successful_count}")
    print(f"No yield data: {no_yield_count}")
    print(f"Failed: {failed_count}")
    print(f"Completed at: {dt.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    
    cursor.close()
    conn.close()
    
    return 0 if failed_count == 0 else 1

if __name__ == "__main__":
    sys.exit(main())
