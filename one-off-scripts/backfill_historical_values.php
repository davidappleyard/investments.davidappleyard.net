<?php
/**
 * Back-fill Historical Account Values
 * 
 * This script populates the hl_account_values_historical table with daily
 * account values for all clients and accounts from the earliest transaction
 * date to the current date.
 * 
 * Features:
 * - Uses existing calculate_historical_account_balance function
 * - Supports resuming from where it left off (no duplicates)
 * - Progress tracking and logging
 * - Memory efficient processing
 * - Timeout handling
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
$BATCH_SIZE = 50; // Process 50 days at a time
$LOG_EVERY = 100; // Log progress every 100 records

// Remove execution time limit to allow full completion
set_time_limit(0);

// Start time tracking
$startTime = time();
$processedRecords = 0;
$skippedRecords = 0;

echo "=== Historical Account Values Back-fill ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "Batch size: {$BATCH_SIZE} days\n";
echo "Execution time: Unlimited (will run until complete)\n\n";

try {
    $pdo = db();
    
    // Get the date range from earliest transaction to today
    $stmt = $pdo->query("
        SELECT 
            MIN(trade_date) as earliest_date,
            MAX(trade_date) as latest_date
        FROM hl_transactions
    ");
    $dateRange = $stmt->fetch();
    
    if (!$dateRange || !$dateRange['earliest_date']) {
        throw new Exception("No transaction data found");
    }
    
    $startDate = $dateRange['earliest_date'];
    $endDate = date('Y-m-d'); // Today
    
    echo "Date range: {$startDate} to {$endDate}\n";
    
    // Define all client/account combinations
    $accounts = [
        ['David', 'SIPP'],
        ['David', 'ISA'], 
        ['David', 'Fund & Share'],
        ['Jen', 'SIPP'],
        ['Jen', 'ISA'],
        ['Jen', 'Fund & Share']
    ];
    
    echo "Processing " . count($accounts) . " account combinations\n\n";
    
    // Create date range array
    $dates = [];
    $currentDate = new DateTime($startDate);
    $endDateTime = new DateTime($endDate);
    
    while ($currentDate <= $endDateTime) {
        $dates[] = $currentDate->format('Y-m-d');
        $currentDate->add(new DateInterval('P1D'));
    }
    
    echo "Total days to process: " . count($dates) . "\n";
    echo "Total records to create: " . (count($dates) * count($accounts)) . "\n\n";
    
    // Check how many records already exist
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM hl_account_values_historical");
    $existingCount = $stmt->fetch()['count'];
    echo "Existing records: {$existingCount}\n\n";
    
    // Process in batches
    $batchCount = 0;
    $totalBatches = ceil(count($dates) / $BATCH_SIZE);
    
    for ($i = 0; $i < count($dates); $i += $BATCH_SIZE) {
        $batchCount++;
        $batchStart = $i;
        $batchEnd = min($i + $BATCH_SIZE - 1, count($dates) - 1);
        $batchDates = array_slice($dates, $batchStart, $BATCH_SIZE);
        
        echo "Processing batch {$batchCount}/{$totalBatches} (dates {$batchDates[0]} to {$batchDates[count($batchDates)-1]})...\n";
        
        // Process each date in the batch
        foreach ($batchDates as $date) {
            foreach ($accounts as [$client, $account]) {
                // Check if record already exists
                $stmt = $pdo->prepare("
                    SELECT id FROM hl_account_values_historical 
                    WHERE client_name = ? AND account_type = ? AND trade_date = ?
                ");
                $stmt->execute([$client, $account, $date]);
                
                if ($stmt->fetch()) {
                    $skippedRecords++;
                    continue; // Skip existing records
                }
                
                // Calculate historical account balance
                $balance = calculate_historical_account_balance($pdo, $client, $account, $date);
                
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
                    $date,
                    $holdingsValue,
                    $cashValue,
                    $totalValue
                ]);
                
                $processedRecords++;
                
                // Log progress
                if ($processedRecords % $LOG_EVERY === 0) {
                    $elapsed = time() - $startTime;
                    echo "  Processed {$processedRecords} records, {$skippedRecords} skipped, {$elapsed}s elapsed\n";
                }
            }
        }
        
        echo "  Batch {$batchCount} completed\n";
    }
    
    // Final summary
    $elapsed = time() - $startTime;
    echo "\n=== Back-fill Complete ===\n";
    echo "Processed records: {$processedRecords}\n";
    echo "Skipped records: {$skippedRecords}\n";
    echo "Total time: {$elapsed} seconds\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    
    if ($processedRecords > 0) {
        echo "\nTo continue from where this left off, simply run the script again.\n";
        echo "It will automatically skip existing records.\n";
    }
    
} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>
