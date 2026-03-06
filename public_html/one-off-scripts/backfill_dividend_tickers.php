<?php
/**
 * Backfill Dividend Tickers Script
 * 
 * This script will find all dividend transactions that don't have a ticker
 * and attempt to match them using the same logic as the import script.
 */

// Include the database connection and helper functions from the main script
require_once 'index.php';

// ---- Helper Functions ----

function detect_ticker_for_dividend(PDO $pdo, string $description): ?string {
    // Remove trailing " [qty] @ [price...]" including decimals and extra spaces
    $namePart = preg_replace('/\s+\d[\d\.]*\s*@.*$/u', '', $description);
    $namePart = trim($namePart);

    // Longest prefix match
    $stmt = $pdo->prepare("
        SELECT ticker
        FROM hl_tickers
        WHERE :namePart LIKE CONCAT(match_text, '%')
        ORDER BY LENGTH(match_text) DESC
        LIMIT 1
    ");
    $stmt->execute([':namePart' => $namePart]);
    $ticker = $stmt->fetchColumn();

    return $ticker ?: null;
}

function get_dividend_transactions_without_tickers(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT id, description, reference, trade_date, value_gbp
        FROM hl_transactions 
        WHERE type = 'Dividend' 
        AND (ticker IS NULL OR ticker = '')
        ORDER BY trade_date DESC, id DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function update_ticker_for_transaction(PDO $pdo, int $id, string $ticker): bool {
    $stmt = $pdo->prepare("UPDATE hl_transactions SET ticker = ? WHERE id = ?");
    return $stmt->execute([$ticker, $id]);
}

// ---- Main Logic ----

$pdo = db();
$action = $_GET['action'] ?? 'preview';

if ($action === 'apply') {
    // Apply the changes
    $transactions = get_dividend_transactions_without_tickers($pdo);
    $updated = 0;
    $skipped = 0;
    
    foreach ($transactions as $transaction) {
        $ticker = detect_ticker_for_dividend($pdo, $transaction['description']);
        if ($ticker) {
            if (update_ticker_for_transaction($pdo, $transaction['id'], $ticker)) {
                $updated++;
            }
        } else {
            $skipped++;
        }
    }
    
    $message = "Updated $updated transactions with tickers. Skipped $skipped transactions (no ticker match found).";
    $messageType = 'success';
} else {
    // Preview mode - show what will be updated
    $transactions = get_dividend_transactions_without_tickers($pdo);
    $previewData = [];
    
    foreach ($transactions as $transaction) {
        $ticker = detect_ticker_for_dividend($pdo, $transaction['description']);
        $previewData[] = [
            'id' => $transaction['id'],
            'description' => $transaction['description'],
            'reference' => $transaction['reference'],
            'trade_date' => $transaction['trade_date'],
            'value_gbp' => $transaction['value_gbp'],
            'suggested_ticker' => $ticker,
            'will_update' => $ticker !== null
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Backfill Dividend Tickers</title>
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .summary {
            background: #e7f8ef;
            border: 1px solid #bde8cf;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .summary h3 {
            margin: 0 0 10px 0;
            color: #28a745;
        }
        .btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
        }
        .btn:hover {
            background: #0056b3;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 14px;
        }
        th, td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .ticker-found {
            background: #d4edda;
            color: #155724;
            font-weight: 600;
        }
        .ticker-not-found {
            background: #f8d7da;
            color: #721c24;
        }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        }
        .right {
            text-align: right;
        }
        .message {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .message.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .message.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            text-align: center;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Backfill Dividend Tickers</h1>
        
        <?php if (isset($message)): ?>
            <div class="message <?= $messageType ?>">
                <?= htmlspecialchars($message) ?>
            </div>
            <a href="backfill_dividend_tickers.php" class="btn btn-success">Back to Preview</a>
        <?php else: ?>
            <?php
                $totalTransactions = count($previewData);
                $willUpdate = count(array_filter($previewData, fn($row) => $row['will_update']));
                $willSkip = $totalTransactions - $willUpdate;
            ?>
            
            <div class="summary">
                <h3>Preview Mode</h3>
                <p>This script will attempt to backfill ticker symbols for dividend transactions that currently don't have them. Review the table below before applying changes.</p>
            </div>
            
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-number"><?= $totalTransactions ?></div>
                    <div class="stat-label">Total Dividend Transactions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $willUpdate ?></div>
                    <div class="stat-label">Will Be Updated</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $willSkip ?></div>
                    <div class="stat-label">Will Be Skipped</div>
                </div>
            </div>
            
            <div style="margin-bottom: 20px;">
                <a href="?action=apply" class="btn btn-danger" onclick="return confirm('Are you sure you want to update <?= $willUpdate ?> transactions? This action cannot be undone.')">
                    Apply Changes (<?= $willUpdate ?> transactions)
                </a>
                <a href="index.php" class="btn">Cancel</a>
            </div>
            
            <?php if (!empty($previewData)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th class="right">Value (£)</th>
                            <th>Suggested Ticker</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewData as $row): ?>
                            <tr class="<?= $row['will_update'] ? 'ticker-found' : 'ticker-not-found' ?>">
                                <td class="mono"><?= htmlspecialchars($row['id']) ?></td>
                                <td class="mono"><?= htmlspecialchars($row['trade_date']) ?></td>
                                <td><?= htmlspecialchars($row['reference']) ?></td>
                                <td><?= htmlspecialchars($row['description']) ?></td>
                                <td class="right mono"><?= number_format($row['value_gbp'], 2) ?></td>
                                <td class="mono">
                                    <?php if ($row['suggested_ticker']): ?>
                                        <?= htmlspecialchars($row['suggested_ticker']) ?>
                                    <?php else: ?>
                                        <em>No match found</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['will_update']): ?>
                                        <span style="color: #28a745; font-weight: 600;">✓ Will Update</span>
                                    <?php else: ?>
                                        <span style="color: #dc3545;">✗ Will Skip</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="message success">
                    <strong>Great news!</strong> All dividend transactions already have ticker symbols. No updates needed.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
