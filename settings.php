<?php
/*******************************
 * Settings Page - Ticker Management
 * - Manage hl_tickers and hl_ticker_symbols tables
 * - Add, edit, delete tickers and symbols
 * - Set target allocations and active status
 *******************************/

// ---- SECURE AUTHENTICATION ----
require_once 'auth.php';

function enforce_secure_auth(): void {
    // Redirect to login if not authenticated
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

// Enforce auth and set anti-indexing header very early
enforce_secure_auth();
header('X-Robots-Tag: noindex, nofollow', true);

// ---- DB ----
if (!function_exists('db')) {
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=localhost;dbname=investments;charset=utf8mb4';
        $pdo = new PDO($dsn, 'root', 'gN6mCgrP!Gi6z9gxp', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
}

// Handle logout
if (isset($_GET['logout'])) {
    logout();
    header('Location: login.php');
    exit;
}

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = db();
    } catch (Exception $e) {
        $error = "Database connection failed: " . $e->getMessage();
    }
    
    if (isset($_POST['add_ticker']) && isset($pdo)) {
        try {
            $pdo->beginTransaction();
            
            $ticker = trim($_POST['ticker']);
            $match_text = trim($_POST['match_text']);
            $yahoo_symbol = trim($_POST['yahoo_symbol']);
            $currency = trim($_POST['currency']);
            $target_allocation = (float)$_POST['target_allocation'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Insert into hl_tickers
            $stmt = $pdo->prepare("INSERT INTO hl_tickers (ticker, match_text) VALUES (?, ?)");
            $stmt->execute([$ticker, $match_text]);
            
            // Insert into hl_ticker_symbols
            $stmt = $pdo->prepare("INSERT INTO hl_ticker_symbols (ticker, yahoo_symbol, currency, is_active, target_allocation, source) VALUES (?, ?, ?, ?, ?, 'yfinance')");
            $stmt->execute([$ticker, $yahoo_symbol, $currency, $is_active, $target_allocation]);
            
            $pdo->commit();
            $message = "Ticker '{$ticker}' added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error adding ticker: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_ticker']) && isset($pdo)) {
        try {
            $pdo->beginTransaction();
            
            $original_ticker = trim($_POST['original_ticker']);
            $ticker = trim($_POST['ticker']);
            $match_text = trim($_POST['match_text']);
            $yahoo_symbol = trim($_POST['yahoo_symbol']);
            $currency = trim($_POST['currency']);
            $target_allocation = (float)$_POST['target_allocation'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // If ticker symbol changed, we need to update the key
            if ($original_ticker !== $ticker) {
                // Delete old entries
                $stmt = $pdo->prepare("DELETE FROM hl_ticker_symbols WHERE ticker = ?");
                $stmt->execute([$original_ticker]);
                
                $stmt = $pdo->prepare("DELETE FROM hl_tickers WHERE ticker = ?");
                $stmt->execute([$original_ticker]);
                
                // Insert new entries
                $stmt = $pdo->prepare("INSERT INTO hl_tickers (ticker, match_text) VALUES (?, ?)");
                $stmt->execute([$ticker, $match_text]);
                
                $stmt = $pdo->prepare("INSERT INTO hl_ticker_symbols (ticker, yahoo_symbol, currency, is_active, target_allocation, source) VALUES (?, ?, ?, ?, ?, 'yfinance')");
                $stmt->execute([$ticker, $yahoo_symbol, $currency, $is_active, $target_allocation]);
            } else {
                // Update hl_tickers
                $stmt = $pdo->prepare("UPDATE hl_tickers SET match_text = ? WHERE ticker = ?");
                $stmt->execute([$match_text, $ticker]);
                
                // Update or insert hl_ticker_symbols
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM hl_ticker_symbols WHERE ticker = ?");
                $stmt->execute([$ticker]);
                $exists = $stmt->fetchColumn() > 0;
                
                if ($exists) {
                    $stmt = $pdo->prepare("UPDATE hl_ticker_symbols SET yahoo_symbol = ?, currency = ?, is_active = ?, target_allocation = ? WHERE ticker = ?");
                    $stmt->execute([$yahoo_symbol, $currency, $is_active, $target_allocation, $ticker]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO hl_ticker_symbols (ticker, yahoo_symbol, currency, is_active, target_allocation, source) VALUES (?, ?, ?, ?, ?, 'yfinance')");
                    $stmt->execute([$ticker, $yahoo_symbol, $currency, $is_active, $target_allocation]);
                }
            }
            
            $pdo->commit();
            $message = "Ticker '{$ticker}' updated successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error updating ticker: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_ticker']) && isset($pdo)) {
        try {
            $pdo->beginTransaction();
            
            $ticker = trim($_POST['ticker']);
            
            // Delete from both tables
            $stmt = $pdo->prepare("DELETE FROM hl_ticker_symbols WHERE ticker = ?");
            $stmt->execute([$ticker]);
            
            $stmt = $pdo->prepare("DELETE FROM hl_tickers WHERE ticker = ?");
            $stmt->execute([$ticker]);
            
            $pdo->commit();
            $message = "Ticker '{$ticker}' deleted successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error deleting ticker: " . $e->getMessage();
        }
    }
}

// Fetch unified data
try {
    $pdo = db();
    $tickers = $pdo->query("
        SELECT 
            t.ticker,
            t.match_text,
            s.yahoo_symbol,
            s.currency,
            s.is_active,
            s.target_allocation,
            s.source
        FROM hl_tickers t
        LEFT JOIN hl_ticker_symbols s ON t.ticker COLLATE utf8mb4_general_ci = s.ticker
        ORDER BY 
            COALESCE(s.is_active, 0) DESC,
            t.ticker ASC
    ")->fetchAll();
    
    // Calculate statistics
    $totalTickers = count($tickers);
    $activeTickers = count(array_filter($tickers, function($t) { return $t['is_active'] == 1; }));
    $withYahooSymbols = count(array_filter($tickers, function($t) { return !empty($t['yahoo_symbol']); }));
    $withTargetAllocation = count(array_filter($tickers, function($t) { return $t['target_allocation'] > 0; }));
    
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
    $tickers = [];
    $totalTickers = $activeTickers = $withYahooSymbols = $withTargetAllocation = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title>Settings - Investment Reporting Tool</title>
<style>
  :root { color-scheme: light only; }
  body {
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    margin: 0;
    padding: 0;
    font-size: 0.85rem;
    display: flex;
    min-height: 100vh;
  }
  
  /* Navigation Sidebar */
  .sidebar {
    width: 200px;
    background: #f5f5f5;
    border-right: 1px solid #ddd;
    padding: 1rem 0;
    flex-shrink: 0;
  }
  
  .sidebar h1 {
    font-size: 1.1rem;
    margin: 0 0 1.5rem 1rem;
    color: #333;
  }
  
  .nav-menu {
    list-style: none;
    padding: 0;
    margin: 0;
  }
  
  .nav-item {
    margin: 0;
  }
  
  .nav-link {
    display: block;
    padding: 0.7rem 1rem;
    color: #555;
    text-decoration: none;
    border-left: 3px solid transparent;
    transition: all 0.2s;
  }
  
  .nav-link:hover {
    background: #e9e9e9;
    color: #333;
  }
  
  .nav-link.active {
    background: #e0e0e0;
    color: #000;
    border-left-color: #007bff;
    font-weight: 600;
  }
  
  /* Main Content */
  .main-content {
    flex: 1;
    padding: 1.5rem;
    overflow-y: auto;
  }
  
  h1 { font-size: 1.6rem; margin: 0 0 1rem; color: #2c3e50; font-weight: 600; }
  h2 { font-size: 1.2rem; margin: 0.3rem 0 0.8rem; color: #34495e; font-weight: 600; }
  h3 { font-size: 1.1rem; margin: 0.2rem 0 0.6rem; color: #34495e; font-weight: 500; }
  h4 { font-size: 1.0rem; margin: 0.2rem 0 0.5rem; color: #34495e; font-weight: 500; }
  
  .card {
    background: rgba(250,250,250,.8);
    backdrop-filter: blur(6px);
    border: 1px solid rgba(0,0,0,.08);
    border-radius: 12px;
    padding: 0.85rem;
    box-shadow: 0 6px 22px rgba(0,0,0,.06);
    margin-bottom: 1.5rem;
    max-width: 1500px;
  }
  
  table { width:100%; border-collapse: collapse; margin-top: 0.8rem; font-size:.8rem; }
  th, td { padding:.45rem .5rem; border-bottom:1px solid rgba(0,0,0,.07); text-align:left; vertical-align: top; }
  thead th { position: sticky; top: 0; background: #fafafa; border-bottom:2px solid rgba(0,0,0,.15); }
  
  /* Very subtle zebra striping */
  tbody tr:nth-child(even) { background-color: rgba(0,0,0,.01); }
  tbody tr:nth-child(odd) { background-color: transparent; }
  
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
  .pill { display:inline-block; padding:.12rem .45rem; border-radius:999px; font-size:.8rem; border:1px solid rgba(0,0,0,.2); background:#fff; }
  .right { text-align:right; }
  .muted { color:#666; }
  .wrap { white-space: normal; max-width: 52ch; }
  .soft { color:#333; }
  
  /* Form styles */
  .form-group {
    margin-bottom: 1rem;
  }
  
  .form-group label {
    display: block;
    margin-bottom: 0.3rem;
    font-weight: 500;
    color: #333;
  }
  
  .form-group input, .form-group select {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.85rem;
  }
  
  .form-group input[type="checkbox"] {
    width: auto;
    margin-right: 0.5rem;
  }
  
  .btn {
    background: #007bff;
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.85rem;
    margin-right: 0.5rem;
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
  
  .message {
    padding: 0.75rem;
    border-radius: 4px;
    margin-bottom: 1rem;
  }
  
  .message.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
  }
  
  .message.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
  }
  
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
  }
  
  .stat-card {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
  }
  
  .stat-number {
    font-size: 1.5rem;
    font-weight: 600;
    color: #007bff;
  }
  
  .stat-label {
    font-size: 0.8rem;
    color: #666;
    margin-top: 0.25rem;
  }
  
  .form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
  }
  
  .inline-form {
    display: flex;
    align-items: end;
    gap: 0.5rem;
  }
  
  .inline-form input {
    flex: 1;
  }
  
  /* Responsive */
  @media (max-width: 768px) {
    body { flex-direction: column; }
    .sidebar { width: 100%; border-right: none; border-bottom: 1px solid #ddd; }
    .nav-menu { display: flex; overflow-x: auto; }
    .nav-item { flex-shrink: 0; }
    .main-content { padding: 1rem; }
  }
</style>
</head>
<body>
  <!-- Navigation Sidebar -->
  <nav class="sidebar">
    <h1>Investment Tool</h1>
    <ul class="nav-menu">
      <li class="nav-item"><a href="index.php#summary" class="nav-link">Summary</a></li>
      <li class="nav-item"><a href="index.php#holdings" class="nav-link">Holdings</a></li>
      <li class="nav-item"><a href="index.php#transactions" class="nav-link">All Transactions</a></li>
      <li class="nav-item"><a href="index.php#performance" class="nav-link">Performance</a></li>
      <li class="nav-item"><a href="index.php#dividends" class="nav-link">Dividend Projections</a></li>
      <li class="nav-item"><a href="index.php#cash" class="nav-link">Cash Balances</a></li>
      <li class="nav-item"><a href="index.php#gains" class="nav-link">Capital Gains</a></li>
      <li class="nav-item"><a href="index.php#rebalancing" class="nav-link">Rebalancing</a></li>
      <li class="nav-item"><a href="index.php#import" class="nav-link">Import</a></li>
      <li class="nav-item"><a href="settings.php" class="nav-link active">Settings</a></li>
      <li class="nav-item"><a href="change_password.php" class="nav-link">Change Password</a></li>
      <li class="nav-item"><a href="?logout=1" class="nav-link" style="color: #dc3545;">Logout</a></li>
    </ul>
  </nav>

  <!-- Main Content Area -->
  <main class="main-content">
    <h1>Settings</h1>
    
    <?php if ($message): ?>
        <div class="message success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if (empty($tickers) && !$error): ?>
        <div class="message error">
            <strong>No ticker data found.</strong> This could mean:
            <ul style="margin: 0.5rem 0 0 1.5rem;">
                <li>The database tables don't exist yet</li>
                <li>The tables are empty</li>
                <li>There's a database connection issue</li>
            </ul>
            <p style="margin: 0.5rem 0 0 0;"><strong>Next steps:</strong> Make sure you've run the SQL command to add the target_allocation column, and that your database contains the hl_tickers and hl_ticker_symbols tables.</p>
        </div>
    <?php endif; ?>

    <!-- Statistics Dashboard -->
    <div class="card">
        <h2>Ticker Statistics</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $totalTickers ?></div>
                <div class="stat-label">Total Tickers</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $activeTickers ?></div>
                <div class="stat-label">Active Tickers</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $withYahooSymbols ?></div>
                <div class="stat-label">With Yahoo Symbols</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $withTargetAllocation ?></div>
                <div class="stat-label">With Target Allocation</div>
            </div>
        </div>
    </div>

    <!-- Add New Ticker Form -->
    <div class="card">
        <h2>Add New Ticker</h2>
        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="ticker">Ticker Symbol *</label>
                    <input type="text" id="ticker" name="ticker" required>
                </div>
                <div class="form-group">
                    <label for="match_text">Match Text *</label>
                    <input type="text" id="match_text" name="match_text" required>
                </div>
                <div class="form-group">
                    <label for="yahoo_symbol">Yahoo Symbol</label>
                    <input type="text" id="yahoo_symbol" name="yahoo_symbol">
                </div>
                <div class="form-group">
                    <label for="currency">Currency</label>
                    <select id="currency" name="currency">
                        <option value="GBP">GBP</option>
                        <option value="USD">USD</option>
                        <option value="EUR">EUR</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="target_allocation">Target Allocation %</label>
                    <input type="number" id="target_allocation" name="target_allocation" step="0.01" min="0" max="100" value="0">
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" checked> Active
                    </label>
                </div>
            </div>
            <button type="submit" name="add_ticker" class="btn btn-success">Add Ticker</button>
        </form>
    </div>

    <!-- Tickers Table -->
    <div class="card">
        <h2>All Tickers</h2>
        <?php if (!empty($tickers)): ?>
        <table>
            <thead>
                <tr>
                    <th>Ticker</th>
                    <th>Match Text</th>
                    <th>Yahoo Symbol</th>
                    <th>Currency</th>
                    <th>Active</th>
                    <th>Target Allocation</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickers as $ticker): ?>
                <tr id="row-<?= htmlspecialchars($ticker['ticker']) ?>">
                    <!-- Display Mode -->
                    <td class="display-mode"><strong><?= htmlspecialchars($ticker['ticker']) ?></strong></td>
                    <td class="display-mode"><?= htmlspecialchars($ticker['match_text']) ?></td>
                    <td class="display-mode"><?= htmlspecialchars($ticker['yahoo_symbol'] ?? '') ?></td>
                    <td class="display-mode"><?= htmlspecialchars($ticker['currency'] ?? '') ?></td>
                    <td class="display-mode">
                        <?php if ($ticker['is_active'] == 1): ?>
                            <span class="pill" style="background: #d4edda; color: #155724;">Active</span>
                        <?php else: ?>
                            <span class="pill" style="background: #f8d7da; color: #721c24;">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="display-mode right"><?= number_format($ticker['target_allocation'] ?? 0, 2) ?>%</td>
                    <td class="display-mode">
                        <button type="button" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; margin-right: 0.25rem;" onclick="editTicker('<?= htmlspecialchars($ticker['ticker']) ?>')">Edit</button>
                        <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this ticker?')">
                            <input type="hidden" name="ticker" value="<?= htmlspecialchars($ticker['ticker']) ?>">
                            <button type="submit" name="delete_ticker" class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Delete</button>
                        </form>
                    </td>
                    
                    <!-- Edit Mode -->
                    <td class="edit-mode" style="display: none;">
                        <form method="POST" action="" id="edit-form-<?= htmlspecialchars($ticker['ticker']) ?>">
                            <input type="hidden" name="original_ticker" value="<?= htmlspecialchars($ticker['ticker']) ?>">
                            <input type="text" name="ticker" value="<?= htmlspecialchars($ticker['ticker']) ?>" style="width: 100%; padding: 0.25rem; font-size: 0.8rem; border: 1px solid #ddd; border-radius: 3px;" required>
                        </form>
                    </td>
                    <td class="edit-mode" style="display: none;">
                        <input type="text" name="match_text" value="<?= htmlspecialchars($ticker['match_text']) ?>" form="edit-form-<?= htmlspecialchars($ticker['ticker']) ?>" style="width: 100%; padding: 0.25rem; font-size: 0.8rem; border: 1px solid #ddd; border-radius: 3px;" required>
                    </td>
                    <td class="edit-mode" style="display: none;">
                        <input type="text" name="yahoo_symbol" value="<?= htmlspecialchars($ticker['yahoo_symbol'] ?? '') ?>" form="edit-form-<?= htmlspecialchars($ticker['ticker']) ?>" style="width: 100%; padding: 0.25rem; font-size: 0.8rem; border: 1px solid #ddd; border-radius: 3px;">
                    </td>
                    <td class="edit-mode" style="display: none;">
                        <select name="currency" form="edit-form-<?= htmlspecialchars($ticker['ticker']) ?>" style="width: 100%; padding: 0.25rem; font-size: 0.8rem; border: 1px solid #ddd; border-radius: 3px;">
                            <option value="GBP" <?= ($ticker['currency'] ?? '') === 'GBP' ? 'selected' : '' ?>>GBP</option>
                            <option value="USD" <?= ($ticker['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD</option>
                            <option value="EUR" <?= ($ticker['currency'] ?? '') === 'EUR' ? 'selected' : '' ?>>EUR</option>
                        </select>
                    </td>
                    <td class="edit-mode" style="display: none;">
                        <label style="display: flex; align-items: center; font-size: 0.8rem;">
                            <input type="checkbox" name="is_active" value="1" <?= ($ticker['is_active'] ?? 0) == 1 ? 'checked' : '' ?> form="edit-form-<?= htmlspecialchars($ticker['ticker']) ?>" style="margin-right: 0.25rem;">
                            Active
                        </label>
                    </td>
                    <td class="edit-mode" style="display: none;">
                        <input type="number" name="target_allocation" value="<?= $ticker['target_allocation'] ?? 0 ?>" step="0.01" min="0" max="100" form="edit-form-<?= htmlspecialchars($ticker['ticker']) ?>" style="width: 100%; padding: 0.25rem; font-size: 0.8rem; border: 1px solid #ddd; border-radius: 3px;">
                    </td>
                    <td class="edit-mode" style="display: none;">
                        <button type="submit" name="update_ticker" form="edit-form-<?= htmlspecialchars($ticker['ticker']) ?>" class="btn btn-success" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; margin-right: 0.25rem;">Save</button>
                        <button type="button" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;" onclick="cancelEdit('<?= htmlspecialchars($ticker['ticker']) ?>')">Cancel</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="muted">No tickers found.</p>
        <?php endif; ?>
    </div>

    <!-- Logs Section -->
    <div class="card">
        <h2>System Logs</h2>
        <p class="note">Recent log entries from automated processes.</p>
        
        <?php
        // Function to read and display log file content
        function displayLogFile($filePath, $title, $maxLines = 500) {
            echo "<div style='margin-bottom: 2rem;'>";
            echo "<h3 style='margin: 0 0 0.5rem 0; color: #34495e; font-size: 1.0rem;'>{$title}</h3>";
            echo "<p style='margin: 0 0 0.5rem 0; color: #666; font-size: 0.8rem;'>Showing last {$maxLines} lines</p>";
            
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $lines = explode("\n", $content);
                    // Don't filter empty lines - we want to preserve the blank line separators
                    $lines = array_slice($lines, -$maxLines); // Get last N lines
                    
                    if (!empty($lines)) {
                        echo "<div style='background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.8rem; max-height: 300px; overflow-y: auto; white-space: pre-wrap;'>";
                        echo htmlspecialchars(implode("\n", $lines));
                        echo "</div>";
                    } else {
                        echo "<p class='muted'>Log file is empty.</p>";
                    }
                } else {
                    echo "<p class='muted'>Unable to read log file.</p>";
                }
            } else {
                echo "<p class='muted'>Log file not found: " . htmlspecialchars($filePath) . "</p>";
            }
            echo "</div>";
        }
        
        // Display the three log files
        displayLogFile('logs/price_cron_daily.log', 'Daily Price Logs');
        displayLogFile('logs/yields_cron_daily.log', 'Daily Yield Log');
        displayLogFile('logs/daily_update_historical_values.log', 'Daily Portfolio Value Log');
        ?>
    </div>
  </main>

<script>
// Handle navigation to main dashboard with hash
document.addEventListener('DOMContentLoaded', function() {
    const navLinks = document.querySelectorAll('.nav-link');
    
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Handle links to main dashboard with hash
            if (this.href && this.href.includes('index.php#')) {
                e.preventDefault();
                window.location.href = this.href;
            }
            // For other links (settings, change_password, logout), let them work normally
        });
    });
});

function editTicker(ticker) {
    const row = document.getElementById('row-' + ticker);
    const displayCells = row.querySelectorAll('.display-mode');
    const editCells = row.querySelectorAll('.edit-mode');
    
    // Hide display mode, show edit mode
    displayCells.forEach(cell => cell.style.display = 'none');
    editCells.forEach(cell => cell.style.display = 'table-cell');
}

function cancelEdit(ticker) {
    const row = document.getElementById('row-' + ticker);
    const displayCells = row.querySelectorAll('.display-mode');
    const editCells = row.querySelectorAll('.edit-mode');
    
    // Show display mode, hide edit mode
    displayCells.forEach(cell => cell.style.display = 'table-cell');
    editCells.forEach(cell => cell.style.display = 'none');
    
    // Reset form values to original (reload page to be safe)
    location.reload();
}
</script>
</body>
</html>