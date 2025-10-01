<?php
/**
 * Daily Update Historical Account Values
 * 
 * This script is designed to be run daily via cron job to populate
 * the hl_account_values_historical table with the current day's data.
 * 
 * Features:
 * - Processes only today's data (or specified date)
 * - Uses existing calculate_historical_account_balance function
 * - Handles duplicate prevention (safe to run multiple times)
 * - Minimal logging for cron job use
 * - Command line argument support for date override
 */

// Include only the necessary functions without the web interface
// We'll define the essential functions here to avoid authentication issues

// Database connection function
function db(): PDO {
    $host = 'localhost';
    $dbname = 'investments'; // Update this with your actual database name
    $username = 'root';    // Update this with your actual username
    $password = 'gN6mCgrP!Gi6z9gxp';    // Update this with your actual password
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

// Historical account balance calculation function
function calculate_historical_account_balance(PDO $pdo, string $client, string $account, string $date): array {
    /**
     * Calculate the total account balance (holdings + cash) for a specific client/account on a specific date.
     * Returns array with 'holdings' and 'cash' totals by currency.
     */
    
    // Get holdings as of the given date (sum of all buy/sell transactions up to that date)
    $stmt = $pdo->prepare("
        SELECT 
            ticker,
            SUM(CASE WHEN type = 'Buy' THEN quantity ELSE -quantity END) as net_quantity,
            description
        FROM hl_transactions 
        WHERE client_name = ? 
        AND account_type = ? 
        AND trade_date <= ?
        AND type IN ('Buy', 'Sell')
        GROUP BY ticker
        HAVING net_quantity > 0
    ");
    $stmt->execute([$client, $account, $date]);
    $holdings = $stmt->fetchAll();
    
    if (empty($holdings)) {
        return ['holdings' => [], 'cash' => 0.0, 'total' => 0.0];
    }
    
    // Get historical prices for the given date
    $tickers = array_column($holdings, 'ticker');
    $tickerPlaceholders = str_repeat('?,', count($tickers) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT ticker, price, currency 
        FROM hl_prices_historical 
        WHERE ticker IN ($tickerPlaceholders) 
        AND trade_date <= ?
        ORDER BY ticker, trade_date DESC
    ");
    $params = array_merge($tickers, [$date]);
    $stmt->execute($params);
    $priceRows = $stmt->fetchAll();
    
    // Group prices by ticker and get the most recent price for each ticker on/before the date
    $prices = [];
    foreach ($priceRows as $row) {
        if (!isset($prices[$row['ticker']])) {
            $prices[$row['ticker']] = [
                'price' => (float)$row['price'],
                'currency' => $row['currency']
            ];
        }
    }
    
    // Calculate holdings value
    $holdingsTotal = [];
    foreach ($holdings as $holding) {
        $ticker = $holding['ticker'];
        $quantity = (float)$holding['net_quantity'];
        
        if (isset($prices[$ticker])) {
            $price = $prices[$ticker]['price'];
            $currency = $prices[$ticker]['currency'];
            $value = $quantity * $price;
            
            if (!isset($holdingsTotal[$currency])) {
                $holdingsTotal[$currency] = 0.0;
            }
            $holdingsTotal[$currency] += $value;
        }
    }
    
    // Calculate cash balance as of the given date
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE 
                WHEN type IN ('Deposit', 'Interest', 'Sell', 'Dividend', 'Loyalty Payment') THEN value_gbp
                WHEN type IN ('Buy', 'Withdrawal', 'Fee') THEN -ABS(value_gbp)
                ELSE 0
            END) as cash_balance
        FROM hl_transactions 
        WHERE client_name = ? 
        AND account_type = ? 
        AND trade_date <= ?
    ");
    $stmt->execute([$client, $account, $date]);
    $cashResult = $stmt->fetch();
    $cashBalance = (float)($cashResult['cash_balance'] ?? 0);
    
    // Add cash to GBP total
    if (!isset($holdingsTotal['GBP'])) {
        $holdingsTotal['GBP'] = 0.0;
    }
    $holdingsTotal['GBP'] += $cashBalance;
    
    return [
        'holdings' => $holdingsTotal,
        'cash' => $cashBalance,
        'total' => array_sum($holdingsTotal)
    ];
}

// Configuration
$LOG_EVERY = 10; // Log progress every 10 records (not really needed for daily script)

// Get the date to process (default: today, can be overridden with command line argument)
$targetDate = isset($argv[1]) ? $argv[1] : date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
    echo "ERROR: Invalid date format. Use YYYY-MM-DD format.\n";
    echo "Usage: php daily_update_historical_values.php [YYYY-MM-DD]\n";
    echo "Example: php daily_update_historical_values.php 2025-01-26\n";
    exit(1);
}

// Start time tracking
$startTime = time();
$processedRecords = 0;
$skippedRecords = 0;

echo "=== Daily Historical Account Values Update ===\n";
echo "Target date: {$targetDate}\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $pdo = db();
    
    // Check if the historical values table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'hl_account_values_historical'");
    if (!$stmt->fetch()) {
        throw new Exception("Table 'hl_account_values_historical' does not exist. Please create it first using the SQL script.");
    }
    
    // Define all client/account combinations
    $accounts = [
        ['David', 'SIPP'],
        ['David', 'ISA'], 
        ['David', 'Fund & Share'],
        ['Jen', 'SIPP'],
        ['Jen', 'ISA'],
        ['Jen', 'Fund & Share']
    ];
    
    echo "Processing " . count($accounts) . " account combinations for date {$targetDate}...\n\n";
    
    // Process each account for the target date
    foreach ($accounts as [$client, $account]) {
        // Check if record already exists
        $stmt = $pdo->prepare("
            SELECT id FROM hl_account_values_historical 
            WHERE client_name = ? AND account_type = ? AND trade_date = ?
        ");
        $stmt->execute([$client, $account, $targetDate]);
        
        if ($stmt->fetch()) {
            echo "  Skipping {$client} {$account} - record already exists for {$targetDate}\n";
            $skippedRecords++;
            continue;
        }
        
        // Calculate historical account balance for the target date
        $balance = calculate_historical_account_balance($pdo, $client, $account, $targetDate);
        
        // Extract values
        $holdingsValue = 0;
        $cashValue = $balance['cash'] ?? 0;
        $totalValue = $balance['total'] ?? 0;
        
        // Calculate holdings value (total - cash)
        $holdingsValue = $totalValue - $cashValue;
        
        // Insert record
        $stmt = $pdo->prepare("
            INSERT INTO hl_account_values_historical 
            (client_name, account_type, trade_date, holdings_value_gbp, cash_value_gbp, total_value_gbp)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $client,
            $account, 
            $targetDate,
            $holdingsValue,
            $cashValue,
            $totalValue
        ]);
        
        echo "  Processed {$client} {$account}: Holdings £" . number_format($holdingsValue, 2) . 
             ", Cash £" . number_format($cashValue, 2) . 
             ", Total £" . number_format($totalValue, 2) . "\n";
        
        $processedRecords++;
    }
    
    // Final summary
    $elapsed = time() - $startTime;
    echo "\n=== Daily Update Complete ===\n";
    echo "Target date: {$targetDate}\n";
    echo "Processed records: {$processedRecords}\n";
    echo "Skipped records: {$skippedRecords}\n";
    echo "Total time: {$elapsed} seconds\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    
    if ($processedRecords > 0) {
        echo "\nSuccessfully updated historical values for {$targetDate}.\n";
    } else {
        echo "\nNo new records were created (all records already existed).\n";
    }
    
} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>
