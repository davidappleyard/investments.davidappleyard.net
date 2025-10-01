<?php
/**
 * Check Historical Account Values Progress
 * 
 * This script provides useful information about the back-fill progress
 * and some sample queries to verify the data.
 */

require_once 'index.php';

echo "=== Historical Account Values Progress Check ===\n\n";

try {
    $pdo = db();
    
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'hl_account_values_historical'");
    if (!$stmt->fetch()) {
        echo "ERROR: Table 'hl_account_values_historical' does not exist.\n";
        echo "Please run the SQL script first to create the table.\n";
        exit(1);
    }
    
    // Get total record count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM hl_account_values_historical");
    $totalRecords = $stmt->fetch()['count'];
    
    // Get date range
    $stmt = $pdo->query("
        SELECT 
            MIN(trade_date) as earliest_date,
            MAX(trade_date) as latest_date,
            COUNT(DISTINCT trade_date) as unique_dates
        FROM hl_account_values_historical
    ");
    $dateInfo = $stmt->fetch();
    
    // Get account breakdown
    $stmt = $pdo->query("
        SELECT 
            client_name,
            account_type,
            COUNT(*) as record_count,
            MIN(trade_date) as first_date,
            MAX(trade_date) as last_date
        FROM hl_account_values_historical
        GROUP BY client_name, account_type
        ORDER BY client_name, account_type
    ");
    $accountBreakdown = $stmt->fetchAll();
    
    // Calculate expected records
    $stmt = $pdo->query("
        SELECT 
            MIN(trade_date) as earliest_transaction,
            MAX(trade_date) as latest_transaction
        FROM hl_transactions
    ");
    $transactionRange = $stmt->fetch();
    
    $expectedDays = 0;
    if ($transactionRange['earliest_transaction']) {
        $startDate = new DateTime($transactionRange['earliest_transaction']);
        $endDate = new DateTime(date('Y-m-d'));
        $expectedDays = $startDate->diff($endDate)->days + 1;
    }
    $expectedRecords = $expectedDays * 6; // 6 accounts total
    
    echo "=== Summary ===\n";
    echo "Total records: {$totalRecords}\n";
    echo "Expected records: {$expectedRecords}\n";
    echo "Progress: " . round(($totalRecords / $expectedRecords) * 100, 1) . "%\n\n";
    
    echo "=== Date Range ===\n";
    echo "Earliest date: " . ($dateInfo['earliest_date'] ?? 'N/A') . "\n";
    echo "Latest date: " . ($dateInfo['latest_date'] ?? 'N/A') . "\n";
    echo "Unique dates: " . ($dateInfo['unique_dates'] ?? 0) . "\n\n";
    
    echo "=== Account Breakdown ===\n";
    foreach ($accountBreakdown as $account) {
        echo sprintf("%-10s %-15s: %4d records (%s to %s)\n",
            $account['client_name'],
            $account['account_type'],
            $account['record_count'],
            $account['first_date'],
            $account['last_date']
        );
    }
    
    echo "\n=== Sample Data (Latest 5 Days) ===\n";
    $stmt = $pdo->query("
        SELECT 
            client_name,
            account_type,
            trade_date,
            holdings_value_gbp,
            cash_value_gbp,
            total_value_gbp
        FROM hl_account_values_historical
        WHERE trade_date >= DATE_SUB(CURDATE(), INTERVAL 5 DAY)
        ORDER BY trade_date DESC, client_name, account_type
        LIMIT 30
    ");
    $sampleData = $stmt->fetchAll();
    
    foreach ($sampleData as $row) {
        echo sprintf("%-10s %-15s %s: Holdings £%10s, Cash £%8s, Total £%10s\n",
            $row['client_name'],
            $row['account_type'],
            $row['trade_date'],
            number_format($row['holdings_value_gbp'], 2),
            number_format($row['cash_value_gbp'], 2),
            number_format($row['total_value_gbp'], 2)
        );
    }
    
    echo "\n=== Missing Date Check ===\n";
    // Check for gaps in the data
    $stmt = $pdo->query("
        SELECT 
            client_name,
            account_type,
            COUNT(*) as record_count,
            COUNT(DISTINCT trade_date) as unique_dates,
            (COUNT(*) - COUNT(DISTINCT trade_date)) as potential_duplicates
        FROM hl_account_values_historical
        GROUP BY client_name, account_type
        HAVING potential_duplicates > 0
    ");
    $duplicates = $stmt->fetchAll();
    
    if (empty($duplicates)) {
        echo "No duplicate records found - good!\n";
    } else {
        echo "WARNING: Potential duplicate records found:\n";
        foreach ($duplicates as $dup) {
            echo "  {$dup['client_name']} {$dup['account_type']}: {$dup['potential_duplicates']} potential duplicates\n";
        }
    }
    
    echo "\n=== Quick Queries ===\n";
    echo "To get latest values for all accounts:\n";
    echo "SELECT * FROM hl_account_values_historical WHERE trade_date = (SELECT MAX(trade_date) FROM hl_account_values_historical);\n\n";
    
    echo "To get David's SIPP over time:\n";
    echo "SELECT trade_date, total_value_gbp FROM hl_account_values_historical WHERE client_name = 'David' AND account_type = 'SIPP' ORDER BY trade_date;\n\n";
    
    echo "To get total portfolio value over time:\n";
    echo "SELECT trade_date, SUM(total_value_gbp) as total_portfolio FROM hl_account_values_historical GROUP BY trade_date ORDER BY trade_date;\n\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
