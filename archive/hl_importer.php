<?php
/*******************************
 * HL CSV Importer + Lister (v4.1 UI tweaks)
 * - PHP 7 compatible (polyfill for str_contains)
 * - Auto-detect Client name/number from CSV preamble
 * - Map Client name to "David" or "Jen"
 * - Title-case Reference
 * - Populate Type & Ticker (via hl_tickers)
 * - Deduping by: client_number + account_type + trade_date + settle_date + reference + value_gbp + quantity
 *   (never dedupe: Fpc, Opening Subscription, Sipp Contribution, TopUp Subscription)
 * - Shows which CSV line numbers were skipped as duplicates
 * - UI: smaller textarea & font; hide client number in table
 *******************************/

// ---- PHP 7 polyfill ----
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

// ---- DB CONFIG ----
const DB_HOST = 'localhost';
const DB_NAME = 'investments';
const DB_USER = 'root';
const DB_PASS = 'gN6mCgrP!Gi6z9gxp';
const DB_CHARSET = 'utf8mb4';

// ---- BASIC AUTH (set your own credentials) ----
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'change-this-password';

function enforce_basic_auth(): void {
    // Some servers pass the header differently
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($authHeader, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authHeader, 6));
            if ($decoded !== false) {
                [$u, $p] = array_pad(explode(':', $decoded, 2), 2, '');
                $_SERVER['PHP_AUTH_USER'] = $u;
                $_SERVER['PHP_AUTH_PW']   = $p;
            }
        }
    }
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW']   ?? null;
    $ok = ($user === ADMIN_USER && $pass === ADMIN_PASS);
    if (!$ok) {
        header('WWW-Authenticate: Basic realm="Investment Reporting Tool"');
        header('HTTP/1.1 401 Unauthorized');
        echo 'Authentication required.';
        exit;
    }
}

// Enforce auth and set anti-indexing header very early
enforce_basic_auth();
header('X-Robots-Tag: noindex, nofollow', true);

// ---- DB ----
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ---- DB Introspection ----
function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1');
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $t) { return false; }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1');
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $t) { return false; }
}

// ---- Import batch helpers ----
function create_import_batch(PDO $pdo, string $client_name, string $client_number, string $account_type): ?int {
    if (!table_exists($pdo, 'hl_import_batches')) return null;
    $sql = 'INSERT INTO hl_import_batches (created_at, client_name, client_number, account_type) VALUES (NOW(), :client_name, :client_number, :account_type)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':client_name'=>$client_name, ':client_number'=>$client_number, ':account_type'=>$account_type]);
    return (int)$pdo->lastInsertId();
}

function finalize_import_batch(PDO $pdo, ?int $batch_id, int $inserted, int $duplicates): void {
    if ($batch_id === null) return;
    try {
        $stmt = $pdo->prepare('UPDATE hl_import_batches SET inserted_count = :i, duplicates_count = :d WHERE id = :id');
        $stmt->execute([':i'=>$inserted, ':d'=>$duplicates, ':id'=>$batch_id]);
    } catch (Throwable $t) { /* ignore */ }
}

function rollback_import_batch(PDO $pdo, int $batch_id): int {
    if (!table_exists($pdo, 'hl_transactions') || !column_exists($pdo, 'hl_transactions', 'import_batch_id')) return 0;
    $stmt = $pdo->prepare('DELETE FROM hl_transactions WHERE import_batch_id = :id');
    $stmt->execute([':id'=>$batch_id]);
    $deleted = $stmt->rowCount();
    if (table_exists($pdo, 'hl_import_batches') && column_exists($pdo, 'hl_import_batches', 'rolled_back_at')) {
        try {
            $pdo->prepare('UPDATE hl_import_batches SET rolled_back_at = NOW() WHERE id = :id')->execute([':id'=>$batch_id]);
        } catch (Throwable $t) { /* ignore */ }
    }
    return (int)$deleted;
}

// ---- Cash calculation helpers ----
function calculate_cash_for_account($pdo, $client, $account): float {
    try {
        $stmt = $pdo->prepare("SELECT * FROM hl_transactions WHERE client_name = ? AND account_type = ? ORDER BY trade_date ASC, id ASC");
        $stmt->execute([$client, $account]);
        $transactions = $stmt->fetchAll();
    } catch (Throwable $t) {
        return 0.0;
    }
    
    $runningTotal = 0.0;
    $cashAffectingTypes = ['Deposit', 'Interest', 'Sell', 'Dividend', 'Loyalty Payment', 'Buy', 'Withdrawal', 'Fee'];
    
    foreach ($transactions as $t) {
        $type = $t['type'] ?? '';
        $value = (float)($t['value_gbp'] ?? 0);
        $cashImpact = 0.0;
        
        // Calculate cash impact based on transaction type
        if (in_array($type, ['Deposit', 'Interest', 'Sell', 'Dividend', 'Loyalty Payment'])) {
            // These add to cash (positive impact)
            $cashImpact = $value;
        } elseif (in_array($type, ['Buy', 'Withdrawal', 'Fee'])) {
            // These subtract from cash (negative impact)
            $cashImpact = -abs($value); // Ensure negative for fees
        }
        
        // Only include transactions that affect cash
        if (in_array($type, $cashAffectingTypes)) {
            $runningTotal += $cashImpact;
        }
    }
    
    return $runningTotal;
}

function fetch_calculated_cash_balances(): array {
    $pdo = db();
    $clients = ['David', 'Jen'];
    $accounts = ['Fund & Share', 'SIPP', 'ISA'];
    $out = [];
    
    foreach ($clients as $client) {
        $out[$client] = [];
        foreach ($accounts as $account) {
            $amount = calculate_cash_for_account($pdo, $client, $account);
            $out[$client][$account] = ['amount' => $amount, 'currency' => 'GBP'];
        }
    }
    
    return $out;
}

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

function calculate_historical_account_balance_excluding_deposits(PDO $pdo, string $client, string $account, string $date): array {
    /**
     * Calculate the total account balance (holdings + cash) for a specific client/account on a specific date.
     * EXCLUDES deposits and withdrawals from cash calculation to show pure investment performance.
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
    
    // Calculate cash balance as of the given date - EXCLUDING deposits and withdrawals
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE 
                WHEN type IN ('Interest', 'Sell', 'Dividend', 'Loyalty Payment') THEN value_gbp
                WHEN type IN ('Buy', 'Fee') THEN -ABS(value_gbp)
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

// ---- HTML escape ----
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ---- Title Case ----
function title_case(string $s): string {
    $s = strtolower($s);
    return mb_convert_case($s, MB_CASE_TITLE, "UTF-8");
}

// ---- Parsers/normalisers ----
function parse_date(string $d): ?string {
    $d = trim($d, "\" \t\r\n");
    if ($d === '' || strtolower($d) === 'n/a') return null;
    $d = str_replace('-', '/', $d);
    $parts = explode('/', $d);
    if (count($parts) === 3) {
        [$dd,$mm,$yyyy] = $parts;
        if (checkdate((int)$mm, (int)$dd, (int)$yyyy)) {
            return sprintf('%04d-%02d-%02d', $yyyy, $mm, $dd);
        }
    }
    $ts = strtotime($d);
    return $ts ? date('Y-m-d', $ts) : null;
}

function parse_decimal(?string $s, int $scale = 6): ?string {
    if ($s === null) return null;
    $s = trim($s, "\" \t\r\n");
    if ($s === '' || strtolower($s) === 'n/a') return null;
    $s = str_replace(['£', ',', ' '], '', $s);
    $s = preg_replace('/[^\-\.\d]/', '', $s);
    if ($s === '' || $s === '-' || $s === '.') return null;
    return number_format((float)$s, $scale, '.', '');
}

// ---- Client info from preamble ----
function parse_client_info(string $csv_text): array {
    $lines = preg_split("/\r\n|\n|\r/", $csv_text);
    $client_name = '';
    $client_number = '';
    foreach ($lines as $line) {
        if ($client_number === '' && stripos($line, 'client number') !== false) {
            if (preg_match('/(\d{5,})/', $line, $m)) $client_number = $m[1];
        }
        if ($client_name === '' && stripos($line, 'client name') !== false) {
            $parts = explode(':', $line, 2);
            if (isset($parts[1])) $client_name = trim($parts[1]);
        }
        if ($client_name && $client_number) break;
    }
    return [$client_name, $client_number];
}

// ---- Map client display name ----
function map_client_display_name(string $raw_name): string {
    $s = mb_strtolower($raw_name, 'UTF-8');
    if (str_contains($s, 'david')) return 'David';
    if (str_contains($s, 'jenifer') || str_contains($s, 'jennifer') || preg_match('/\bjen\b/u', $s)) return 'Jen';
    return $raw_name;
}

// ---- Special references that must never be deduped ----
function is_non_dedupe_reference(string $reference): bool {
    $r = mb_strtolower($reference, 'UTF-8');
    $specials = [
        'fpc',
        'opening subscription',
        'sipp contribution',
        'topup subscription',
    ];
    return in_array($r, $specials, true);
}

// ---- Detect Type per business rules ----
function detect_type(string $reference, string $description, float $value_gbp): string {
    $r = strtoupper(trim($reference));
    $d = trim($description);

    if (strpos($r, 'B') === 0) return 'Buy';
    if (strpos($r, 'S') === 0) return 'Sell';
    if ($r === 'INTEREST') return 'Interest';
    if ($r === 'MANAGE FEE') return 'Fee';
    if ($d === 'Transfer from Income Account') return 'Transfer from Income Account';
    if ($d === 'Transfer to Capital Account') return 'Transfer to Capital Account';
    if ($r === 'OVR CR') return 'Dividend';
    if ($r === 'UTG CR') return 'Dividend';
    if ($r === 'LOYALTYU') return 'Loyalty Payment';
    if ($r === 'TRANSFER' && $value_gbp < 0) return 'Withdrawal';

    return 'Deposit';
}

// ---- Detect Ticker via hl_tickers (Buy/Sell only); match Description prefix ----
function detect_ticker(PDO $pdo, string $type, string $description): ?string {
    if (!in_array($type, ['Buy','Sell'])) return null;

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

// ---- Parse HL CSV block; keep line numbers ----
function parse_hl_csv_block(string $csv_text): array {
    $lines = preg_split("/\r\n|\n|\r/", $csv_text);
    $startIndex = null;
    foreach ($lines as $i => $line) {
        if (stripos($line, 'Trade date') !== false && stripos($line, 'Value') !== false) { $startIndex = $i; break; }
    }
    if ($startIndex === null) throw new RuntimeException('Could not find the transactions header (Trade date / Value).');

    $body = implode("\n", array_slice($lines, $startIndex));
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $body); rewind($fh);

    $header = fgetcsv($fh);
    if (!$header) throw new RuntimeException('Failed to parse header row.');

    $idx = [];
    foreach ($header as $k => $name) $idx[strtolower(trim($name))] = $k;

    foreach (['trade date','settle date','reference','description','unit cost (p)','quantity','value (£)'] as $need) {
        if (!array_key_exists($need, $idx)) throw new RuntimeException('Missing required column: '.$need);
    }

    $rows = []; $csvRowNumber = 0;
    while (($row = fgetcsv($fh)) !== false) {
        if (count(array_filter($row, fn($v)=>trim((string)$v)!=='')) === 0) continue;
        $csvRowNumber++;
        $rows[] = [
            'line_no'         => $csvRowNumber,                 // 1-based after header row
            'trade_date_raw'  => $row[$idx['trade date']] ?? '',
            'settle_date_raw' => $row[$idx['settle date']] ?? '',
            'reference_raw'   => $row[$idx['reference']] ?? '',
            'description_raw' => $row[$idx['description']] ?? '',
            'unit_cost_p_raw' => $row[$idx['unit cost (p)']] ?? '',
            'quantity_raw'    => $row[$idx['quantity']] ?? '',
            'value_gbp_raw'   => $row[$idx['value (£)']] ?? '',
        ];
    }
    fclose($fh);
    return $rows;
}

function clean_and_normalise(array $rows, string $client_name_raw, string $client_number, string $account_type): array {
    $pdo = db();
    $client_name_mapped = map_client_display_name($client_name_raw);
    $out = [];
    foreach ($rows as $r) {
        $trade_date  = parse_date($r['trade_date_raw']);
        $settle_date = parse_date($r['settle_date_raw']);
        $reference   = title_case(trim((string)$r['reference_raw']));
        $description = trim((string)$r['description_raw']);
        $unit_cost_p = parse_decimal($r['unit_cost_p_raw'], 6);
        $quantity    = parse_decimal($r['quantity_raw'], 6);
        $value_gbp   = parse_decimal($r['value_gbp_raw'], 2);

        if (!$trade_date || $value_gbp === null || $description === '') continue;

        $type   = detect_type($reference, $description, $value_gbp);
        // Special rule: plain "Transfer" references that would be Deposits, 
        // but have negative value, should be classified as Withdrawal
        if (strcasecmp($reference, 'Transfer') === 0 && $type === 'Deposit' && (float)$value_gbp < 0) {
            $type = 'Withdrawal';
        }
        $ticker = detect_ticker($pdo, $type, $description);

        $out[] = [
            'line_no'       => $r['line_no'],
            'client_name'   => $client_name_mapped,
            'client_number' => $client_number,
            'account_type'  => $account_type,
            'trade_date'    => $trade_date,
            'settle_date'   => $settle_date,
            'reference'     => $reference,
            'type'          => $type,
            'ticker'        => $ticker,
            'description'   => $description,
            'unit_cost_p'   => $unit_cost_p,
            'quantity'      => $quantity,
            'value_gbp'     => $value_gbp,
        ];
    }
    return $out;
}

// ---- Duplicate check (by fields) ----
function is_duplicate(PDO $pdo, array $r): bool {
    if (is_non_dedupe_reference($r['reference'])) return false;

    $sql = "SELECT id FROM hl_transactions
            WHERE client_number = :client_number
              AND account_type  = :account_type
              AND trade_date    = :trade_date
              AND (settle_date <=> :settle_date)
              AND reference     = :reference
              AND value_gbp     = :value_gbp
              AND (quantity  <=> :quantity)
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':client_number' => $r['client_number'],
        ':account_type'  => $r['account_type'],
        ':trade_date'    => $r['trade_date'],
        ':settle_date'   => $r['settle_date'],
        ':reference'     => $r['reference'],
        ':value_gbp'     => $r['value_gbp'],
        ':quantity'      => $r['quantity'],
    ]);
    return (bool)$stmt->fetchColumn();
}

// ---- Insert rows with duplicate reporting ----
function insert_rows(array $rows, ?int $import_batch_id = null): array {
    if (empty($rows)) return ['inserted'=>0,'duplicates'=>0,'duplicate_lines'=>[]];

    $pdo = db();
    $hasBatchCol = column_exists($pdo, 'hl_transactions', 'import_batch_id');
    $useBatch = $import_batch_id !== null && $hasBatchCol;
    if ($useBatch) {
        $insertSql = "INSERT INTO hl_transactions
        (client_name, client_number, account_type, trade_date, settle_date, reference, type, ticker, description, unit_cost_p, quantity, value_gbp, import_batch_id)
        VALUES
        (:client_name, :client_number, :account_type, :trade_date, :settle_date, :reference, :type, :ticker, :description, :unit_cost_p, :quantity, :value_gbp, :import_batch_id)";
    } else {
    $insertSql = "INSERT INTO hl_transactions
        (client_name, client_number, account_type, trade_date, settle_date, reference, type, ticker, description, unit_cost_p, quantity, value_gbp)
        VALUES
        (:client_name, :client_number, :account_type, :trade_date, :settle_date, :reference, :type, :ticker, :description, :unit_cost_p, :quantity, :value_gbp)";
    }
    $stmt = $pdo->prepare($insertSql);

    $inserted = 0; $dupes = 0; $dupLines = [];

    foreach ($rows as $r) {
        if (is_duplicate($pdo, $r)) {
            $dupes++;
            $dupLines[] = [
                'line_no'   => $r['line_no'],
                'trade_date'=> $r['trade_date'],
                'reference' => $r['reference'],
                'value_gbp' => $r['value_gbp'],
                'desc'      => mb_substr($r['description'], 0, 80),
            ];
            continue;
        }

        $params = [
            ':client_name'   => $r['client_name'],
            ':client_number' => $r['client_number'],
            ':account_type'  => $r['account_type'],
            ':trade_date'    => $r['trade_date'],
            ':settle_date'   => $r['settle_date'],
            ':reference'     => $r['reference'],
            ':type'          => $r['type'],
            ':ticker'        => $r['ticker'],
            ':description'   => $r['description'],
            ':unit_cost_p'   => $r['unit_cost_p'],
            ':quantity'      => $r['quantity'],
            ':value_gbp'     => $r['value_gbp'],
        ];
        if ($useBatch) { $params[':import_batch_id'] = $import_batch_id; }
        $stmt->execute($params);
        $inserted++;
    }

    return ['inserted'=>$inserted,'duplicates'=>$dupes,'duplicate_lines'=>$dupLines];
}

function fetch_all_transactions(): array {
    $pdo = db();
    $sql = "SELECT * FROM hl_transactions ORDER BY trade_date DESC, id DESC";
    return $pdo->query($sql)->fetchAll();
}

// ---- Holdings (current positions from Buy/Sell net of quantity) ----
function fetch_current_holdings(): array {
    $pdo = db();
    // Sum Buy as +quantity and Sell as -quantity, group by client/account/ticker (fallback to description)
    $sql = "
        SELECT
          client_name,
          account_type,
          COALESCE(NULLIF(ticker, ''), description) AS holding_key,
          MAX(ticker) AS ticker,
          MAX(description) AS description,
          SUM(CASE WHEN type = 'Buy' THEN COALESCE(quantity,0)
                   WHEN type = 'Sell' THEN -COALESCE(quantity,0)
                   ELSE 0 END) AS total_quantity
        FROM hl_transactions
        WHERE type IN ('Buy','Sell')
        GROUP BY client_name, account_type, holding_key
        HAVING ABS(total_quantity) > 0
        ORDER BY client_name, account_type, holding_key
    ";
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Throwable $t) {
        return [];
    }
    $out = [
        'David' => ['Fund & Share'=>[], 'SIPP'=>[], 'ISA'=>[]],
        'Jen'   => ['Fund & Share'=>[], 'SIPP'=>[], 'ISA'=>[]],
    ];
    foreach ($rows as $r) {
        $client  = $r['client_name'];
        $account = $r['account_type'];
        if (!isset($out[$client])) $out[$client] = ['Fund & Share'=>[], 'SIPP'=>[], 'ISA'=>[]];
        if (!isset($out[$client][$account])) $out[$client][$account] = [];
        $out[$client][$account][] = [
            'label'    => $r['ticker'] ?: $r['description'],
            'ticker'   => $r['ticker'] ?: null,
            'quantity' => $r['total_quantity'],
        ];
    }
    return $out;
}

// ---- Annual totals: Fees, Dividends, Deposits ----
function fetch_annual_totals(int $startYear = 2015): array {
    $pdo = db();
    $currentYear = (int)date('Y');
    $out = [];
    for ($y = $startYear; $y <= $currentYear; $y++) {
        $out[$y] = ['fees'=>0.0, 'dividends'=>0.0, 'deposits'=>0.0];
    }

    // Sum by year across all clients/accounts. Treat fees as positive totals (absolute value).
    // Dividends include explicit 'Dividend' and 'Loyalty Payment' types.
    $sql = "
        SELECT
          YEAR(trade_date) AS y,
          SUM(CASE WHEN type = 'Fee' THEN ABS(COALESCE(value_gbp,0)) ELSE 0 END) AS fees,
          SUM(CASE WHEN type IN ('Dividend','Loyalty Payment') THEN COALESCE(value_gbp,0) ELSE 0 END) AS dividends,
          SUM(CASE WHEN type = 'Deposit' THEN COALESCE(value_gbp,0) ELSE 0 END) AS deposits
        FROM hl_transactions
        WHERE trade_date IS NOT NULL
        GROUP BY YEAR(trade_date)
        ORDER BY y ASC
    ";
    try {
        foreach ($pdo->query($sql) as $row) {
            $y = (int)$row['y'];
            if (!isset($out[$y])) $out[$y] = ['fees'=>0.0, 'dividends'=>0.0, 'deposits'=>0.0];
            $out[$y]['fees'] = (float)$row['fees'];
            $out[$y]['dividends'] = (float)$row['dividends'];
            $out[$y]['deposits'] = (float)$row['deposits'];
        }
    } catch (Throwable $t) {
        // table may not exist; return zeros
    }
    return $out;
}

// ---- UK Tax Year totals: Fees, Dividends, Deposits (6 Apr to 5 Apr) ----
function fetch_tax_year_totals(int $startTaxYearStart = 2015): array {
    $pdo = db();
    // Compute tax year start (YYYY) for each row. If date >= 6 Apr YYYY then tax year = YYYY-YYYY+1 else (YYYY-1)-(YYYY)
    $sql = "
        SELECT
          CASE
            WHEN (MONTH(trade_date) > 4) OR (MONTH(trade_date) = 4 AND DAY(trade_date) >= 6)
              THEN YEAR(trade_date)
            ELSE YEAR(trade_date) - 1
          END AS tax_year_start,
          SUM(CASE WHEN type = 'Fee' THEN ABS(COALESCE(value_gbp,0)) ELSE 0 END) AS fees,
          SUM(CASE WHEN type IN ('Dividend','Loyalty Payment') THEN COALESCE(value_gbp,0) ELSE 0 END) AS dividends,
          SUM(CASE WHEN type = 'Deposit' THEN COALESCE(value_gbp,0) ELSE 0 END) AS deposits,
          SUM(CASE WHEN type = 'Withdrawal' THEN ABS(COALESCE(value_gbp,0)) ELSE 0 END) AS withdrawals
        FROM hl_transactions
        WHERE trade_date IS NOT NULL
        GROUP BY tax_year_start
        ORDER BY tax_year_start ASC
    ";
    $rows = [];
    try { $rows = $pdo->query($sql)->fetchAll(); } catch (Throwable $t) { return []; }
    $map = [];
    foreach ($rows as $r) {
        $tys = (int)$r['tax_year_start'];
        if ($tys < $startTaxYearStart) continue;
        $map[$tys] = [
            'fees' => (float)$r['fees'],
            'dividends' => (float)$r['dividends'],
            'deposits' => (float)$r['deposits'],
            'withdrawals' => (float)$r['withdrawals'],
        ];
    }
    // Fill missing years
    $current = (int)date('Y');
    // Determine the last completed tax year start; if before 6 Apr, current-1
    $nowMonth = (int)date('n');
    $nowDay   = (int)date('j');
    $lastStart = ($nowMonth > 4 || ($nowMonth === 4 && $nowDay >= 6)) ? $current : ($current - 1);
    for ($y = $startTaxYearStart; $y <= $lastStart; $y++) {
        if (!isset($map[$y])) $map[$y] = ['fees'=>0.0,'dividends'=>0.0,'deposits'=>0.0,'withdrawals'=>0.0];
    }
    ksort($map);
    return $map;
}

// ---- Account total values using live prices ----
function compute_account_totals(array $holdings): array {
    // Build a unique ticker list across all holdings
    $tickers = [];
    foreach ($holdings as $client => $accts) {
        foreach ($accts as $acct => $list) {
            foreach ($list as $h) {
                if (!empty($h['ticker'])) $tickers[] = $h['ticker'];
            }
        }
    }
    $priceMap = get_prices_for_tickers($tickers);
    $totals = [
        'David' => ['Fund & Share'=>[], 'SIPP'=>[], 'ISA'=>[]],
        'Jen'   => ['Fund & Share'=>[], 'SIPP'=>[], 'ISA'=>[]],
    ];
    foreach ($holdings as $client => $accts) {
        foreach ($accts as $acct => $list) {
            foreach ($list as $h) {
                $ticker = $h['ticker'] ?? null;
                $qty = (float)($h['quantity'] ?? 0);
                $live = ($ticker && isset($priceMap[$ticker])) ? $priceMap[$ticker] : null;
                if (!$live) continue;
                $value = $qty * (float)$live['price'];
                $ccy   = $live['currency'] ?? 'GBP';
                if (!isset($totals[$client][$acct][$ccy])) $totals[$client][$acct][$ccy] = 0.0;
                $totals[$client][$acct][$ccy] += $value;
            }
        }
    }
    return $totals;
}


function get_prices_for_tickers(array $tickers): array {
    $tickers = array_values(array_unique(array_filter($tickers)));
    if (empty($tickers)) return [];
    $pdo = db();
    $in  = implode(',', array_fill(0, count($tickers), '?'));
    $stmt = $pdo->prepare("SELECT ticker, price, currency FROM hl_prices_latest WHERE ticker IN ($in)");
    $stmt->execute($tickers);
    $out = [];
    foreach ($stmt as $row) {
        $out[$row['ticker']] = [
            'price' => (float)$row['price'],
            'currency' => $row['currency'],
        ];
    }
    return $out;
}

function fmt_money(float $n, int $dp = 2): string {
    return number_format($n, $dp, '.', ',');
}


// ---- Controller ----
$messages = [];
$summary = null;
$parsed_client = ['name'=>'', 'number'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['rollback_batch_id']) && trim((string)$_POST['rollback_batch_id']) !== '') {
        try {
            $pdo = db();
            $batchId = (int)$_POST['rollback_batch_id'];
            $deleted = rollback_import_batch($pdo, $batchId);
            if ($deleted > 0) {
                $messages[] = ['type'=>'success','text'=>"Rolled back batch #$batchId and deleted $deleted row(s)."];
            } else {
                $messages[] = ['type'=>'error','text'=>"No rows deleted. Ensure batch #$batchId exists and schema is updated."];
            }
        } catch (Throwable $t) {
            $messages[] = ['type'=>'error','text'=>'Rollback failed: '.$t->getMessage()];
        }
    }


    $account_type  = trim($_POST['account_type'] ?? '');
    $csv_text      = $_POST['csv_text'] ?? '';

    if (!in_array($account_type, ['SIPP','ISA','Fund & Share'], true)) {
        $messages[] = ['type'=>'error','text'=>'Please choose a valid account type (SIPP, ISA, Fund & Share).'];
    }
    if (trim($csv_text) === '') {
        $messages[] = ['type'=>'error','text'=>'Please paste the CSV contents.'];
    }

    if (empty(array_filter($messages, fn($m)=>$m['type']==='error'))) {
        try {
            [$client_name_raw, $client_number] = parse_client_info($csv_text);
            if ($client_name_raw === '' || $client_number === '') {
                throw new RuntimeException('Could not read Client name or Client number from the CSV preamble.');
            }
            $client_name_mapped = map_client_display_name($client_name_raw);
            $parsed_client = ['name'=>$client_name_mapped, 'number'=>$client_number];

            $parsed = parse_hl_csv_block($csv_text);
            $clean  = clean_and_normalise($parsed, $client_name_raw, $client_number, $account_type);
            $pdo = db();
            $batchId = create_import_batch($pdo, $client_name_mapped, $client_number, $account_type);
            $res    = insert_rows($clean, $batchId);
            finalize_import_batch($pdo, $batchId, (int)$res['inserted'], (int)$res['duplicates']);
            $summary = $res;

            $messages[] = [
                'type'=>'success',
                'text'=>"Imported {$res['inserted']} rows (Client: {$client_name_mapped}, Account: {$account_type}). Skipped {$res['duplicates']} duplicates." . ($batchId ? " Batch #$batchId." : "")
            ];
        } catch (Throwable $t) {
            $messages[] = ['type'=>'error','text'=>'Import failed: '.$t->getMessage()];
        }
    }
}

// Handle CSV download for CGT Calculator
if (isset($_GET['download_csv']) && $_GET['download_csv'] === '1') {
    $client = $_GET['client'] ?? '';
    $account = $_GET['account'] ?? '';
    $taxYear = (int)($_GET['tax_year'] ?? 0);
    
    if (in_array($client, ['David', 'Jen']) && $account === 'Fund & Share' && $taxYear >= 2021) {
        // Calculate tax year date range (6 April to 5 April)
        $startDate = $taxYear . '-04-06';
        $endDate = ($taxYear + 1) . '-04-05';
        
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT trade_date, type, ticker, quantity, unit_cost_p, description
                FROM hl_transactions 
                WHERE client_name = ? 
                AND account_type = ? 
                AND type IN ('Buy', 'Sell')
                AND trade_date >= ? 
                AND trade_date <= ?
                ORDER BY trade_date ASC
            ");
            $stmt->execute([$client, $account, $startDate, $endDate]);
            $transactions = $stmt->fetchAll();
            
            // Set headers for CSV display in browser
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Output CSV header
            echo "B/S,Date,Company,Shares,Price,Charges,Tax\n";
            
            // Output transaction data
            foreach ($transactions as $t) {
                $type = $t['type'] === 'Buy' ? 'B' : 'S';
                $date = date('d/m/Y', strtotime($t['trade_date']));
                $company = $t['ticker'] ?? '';
                $shares = number_format((float)$t['quantity'], 0, '.', ''); // No decimals for shares
                $price = number_format((float)$t['unit_cost_p'] / 100, 3, '.', ''); // Convert pence to pounds, 3 decimal places
                $charges = '0.0';
                $tax = '0.0';
                
                echo "$type,$date,$company,$shares,$price,$charges,$tax\n";
            }
            
            exit;
        } catch (Throwable $t) {
            $messages[] = ['type'=>'error','text'=>'Failed to generate CSV: '.$t->getMessage()];
        }
    } else {
        $messages[] = ['type'=>'error','text'=>'Invalid download parameters.'];
    }
}

// Handle AJAX CSV generation
if (isset($_POST['generate_csv']) && $_POST['generate_csv'] === '1') {
    $client = $_POST['client'] ?? '';
    $account = $_POST['account'] ?? '';
    
    if (in_array($client, ['David', 'Jen']) && $account === 'Fund & Share') {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT trade_date, type, ticker, quantity, unit_cost_p, description
                FROM hl_transactions 
                WHERE client_name = ? 
                AND account_type = ? 
                AND type IN ('Buy', 'Sell')
                ORDER BY trade_date ASC
            ");
            $stmt->execute([$client, $account]);
            $transactions = $stmt->fetchAll();
            
            // Output TSV header
            echo "B/S\tDate\tCompany\tShares\tPrice\tCharges\tTax\n";
            
            // Output transaction data
            foreach ($transactions as $t) {
                $type = $t['type'] === 'Buy' ? 'B' : 'S';
                $date = date('d/m/Y', strtotime($t['trade_date']));
                $company = $t['ticker'] ?? '';
                $shares = number_format((float)$t['quantity'], 0, '.', ''); // No decimals for shares
                $price = number_format((float)$t['unit_cost_p'] / 100, 3, '.', ''); // Convert pence to pounds, 3 decimal places
                $charges = '0.0';
                $tax = '0.0';
                
                echo "$type\t$date\t$company\t$shares\t$price\t$charges\t$tax\n";
            }
            
            exit;
        } catch (Throwable $t) {
            echo "Error generating TSV: " . $t->getMessage();
            exit;
        }
    } else {
        echo "Invalid parameters for TSV generation.";
        exit;
    }
}

$rows = [];
$holdings = [];
try {
    $rows = fetch_all_transactions();
    $holdings = fetch_current_holdings();
    $annual = fetch_annual_totals(2015);
    $taxAnnual = fetch_tax_year_totals(2015);
    $cashBalances = fetch_calculated_cash_balances();
    $accountTotals = compute_account_totals($holdings);
} catch (Throwable $t) { /* table may not exist yet */ }

// ---- View ----
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title>Investment Reporting Tool</title>
<style>
  :root { color-scheme: light dark; }
  body {
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    margin: 0;
    padding: 0;
    font-size: 0.85rem; /* reduced by 1 point */
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
  
  .page {
    display: none;
  }
  
  .page.active {
    display: block;
  }
  h1 { font-size: 1.3rem; margin: 0 0 0.8rem; }
  h2 { font-size: 1.0rem; margin: 0.2rem 0 0.6rem; }
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
  
  /* Summary page cards minimum width */
  #summary .card {
    min-width: 550px;
  }
  form .grid { display: grid; gap: 0.8rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
  label { display:block; font-weight:600; margin-bottom:.3rem; font-size: 0.95em; }
  select, textarea {
    width: 80%;
    max-width: 900px;      /* reduce width on large screens */
    padding:.55rem .7rem;
    border-radius:10px; border:1px solid rgba(0,0,0,.15);
    font: inherit; background: white; color: inherit;
  }
  textarea {
    min-height: 160px;     /* reduced height */
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  }
  .actions { display:flex; gap:.6rem; align-items:center; }
  .btn {
    background:#111; color:#fff; border:0; padding:.55rem .9rem;
    border-radius:10px; cursor:pointer; font-weight:600; font-size: 0.95em;
  }
  .note { font-size:.9rem; color:#555; }
  .msgs { margin: 0.8rem 0; }
  .msg { padding:.6rem .8rem; border-radius:10px; margin-bottom:.4rem; }
  .msg.success { background:#e7f8ef; border:1px solid #bde8cf; }
  .msg.error { background:#fde8e8; border:1px solid #f5bcbc; }
  details { margin-top:.45rem; }
  summary { cursor: pointer; font-weight:600; }
  table { width:100%; border-collapse: collapse; margin-top: 0.8rem; font-size:.8rem; } /* reduced by 1 point */
  th, td { padding:.45rem .5rem; border-bottom:1px solid rgba(0,0,0,.07); text-align:left; vertical-align: top; }
  thead th { position: sticky; top: 0; background: #fafafa; border-bottom:2px solid rgba(0,0,0,.15); }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
  .pill { display:inline-block; padding:.12rem .45rem; border-radius:999px; font-size:.8rem; border:1px solid rgba(0,0,0,.2); background:#fff; }
  .right { text-align:right; }
  .muted { color:#666; }
  .wrap { white-space: normal; max-width: 52ch; }
  .soft { color:#333; }
  
  /* Dashboard grid for summary page */
  .dashboard { display:grid; gap:0.9rem; grid-template-columns: repeat(auto-fit, minmax(550px, 1fr)); align-items:start; }
  
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
      <li class="nav-item"><a href="#" class="nav-link active" data-page="summary">Summary</a></li>
      <li class="nav-item"><a href="#" class="nav-link" data-page="transactions">All Transactions</a></li>
      <li class="nav-item"><a href="#" class="nav-link" data-page="performance">Performance</a></li>
      <li class="nav-item"><a href="#" class="nav-link" data-page="dividends">Dividend Projections</a></li>
      <li class="nav-item"><a href="#" class="nav-link" data-page="cash">Cash Balances</a></li>
      <li class="nav-item"><a href="#" class="nav-link" data-page="gains">Capital Gains</a></li>
      <li class="nav-item"><a href="#" class="nav-link" data-page="import">Import</a></li>
    </ul>
  </nav>

  <!-- Main Content Area -->
  <main class="main-content">
    <!-- Summary Page -->
    <div id="summary" class="page active">
      <h1>Summary</h1>
      <div class="dashboard">
    <div class="card">
      <h2 style="margin-top:0;">Accounts Summary</h2>
      <table>
        <thead>
          <tr>
            <th>Account</th>
            <th class="right">Total Value</th>
          </tr>
        </thead>
        <tbody>
          <?php
            // helper to render totals per currency
            $renderTotals = function($totals) {
              if (empty($totals)) return '—';
              $parts = [];
              foreach ($totals as $ccy => $sum) { $parts[] = fmt_money((float)$sum, 2).' '.h($ccy); }
              return h(implode('; ', $parts));
            };
            $sumTotals = function(...$maps) {
              $out = [];
              foreach ($maps as $m) {
                if (!is_array($m)) continue;
                foreach ($m as $ccy => $val) {
                  if (!isset($out[$ccy])) $out[$ccy] = 0.0;
                  $out[$ccy] += (float)$val;
                }
              }
              return $out;
            };
            $addCash = function(array $map, ?array $cashRow): array {
              if (!$cashRow) return $map;
              $ccy = $cashRow['currency'] ?? 'GBP';
              $amt = (float)($cashRow['amount'] ?? 0);
              if ($amt) { $map[$ccy] = ($map[$ccy] ?? 0) + $amt; }
              return $map;
            };
          ?>
          <tr><td>David Pension</td><td class="right mono"><?php $m = $addCash(($accountTotals['David']['SIPP'] ?? []), $cashBalances['David']['SIPP'] ?? null); echo $renderTotals($m); ?></td></tr>
          <tr><td>David ISA</td><td class="right mono"><?php $m = $addCash(($accountTotals['David']['ISA'] ?? []), $cashBalances['David']['ISA'] ?? null); echo $renderTotals($m); ?></td></tr>
          <tr><td>David Fund &amp; Share</td><td class="right mono"><?php $m = $addCash(($accountTotals['David']['Fund & Share'] ?? []), $cashBalances['David']['Fund & Share'] ?? null); echo $renderTotals($m); ?></td></tr>
          <tr><td>Jen Pension</td><td class="right mono"><?php $m = $addCash(($accountTotals['Jen']['SIPP'] ?? []), $cashBalances['Jen']['SIPP'] ?? null); echo $renderTotals($m); ?></td></tr>
          <tr><td>Jen ISA</td><td class="right mono"><?php $m = $addCash(($accountTotals['Jen']['ISA'] ?? []), $cashBalances['Jen']['ISA'] ?? null); echo $renderTotals($m); ?></td></tr>
          <tr><td>Jen Fund &amp; Share</td><td class="right mono"><?php $m = $addCash(($accountTotals['Jen']['Fund & Share'] ?? []), $cashBalances['Jen']['Fund & Share'] ?? null); echo $renderTotals($m); ?></td></tr>
        </tbody>
        <tfoot>
          <?php
            $grand = $sumTotals(
              ($accountTotals['David']['SIPP'] ?? []),
              ($accountTotals['David']['ISA'] ?? []),
              ($accountTotals['David']['Fund & Share'] ?? []),
              ($accountTotals['Jen']['SIPP'] ?? []),
              ($accountTotals['Jen']['ISA'] ?? []),
              ($accountTotals['Jen']['Fund & Share'] ?? [])
            );
            // add cash into totals by currency
            $cashPairs = [
              $cashBalances['David']['SIPP'] ?? null,
              $cashBalances['David']['ISA'] ?? null,
              $cashBalances['David']['Fund & Share'] ?? null,
              $cashBalances['Jen']['SIPP'] ?? null,
              $cashBalances['Jen']['ISA'] ?? null,
              $cashBalances['Jen']['Fund & Share'] ?? null,
            ];
            foreach ($cashPairs as $cr) {
              if (!$cr) continue;
              $ccy = $cr['currency'] ?? 'GBP';
              $amt = (float)($cr['amount'] ?? 0);
              if ($amt) { $grand[$ccy] = ($grand[$ccy] ?? 0) + $amt; }
            }
          ?>
          <tr>
            <th class="right">Total</th>
            <th class="right mono"><?=$renderTotals($grand)?></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="card">
      <h2 style="margin-top:0;">David's Accounts</h2>
      <?php $accounts = ['Fund & Share','SIPP','ISA']; ?>
      <table>
        <thead>
          <tr>
            <th>Holding</th>
            <th class="right">Quantity</th>
            <th class="right">Live Price</th>
            <th class="right">Holding Value</th>
          </tr>
        </thead>
        <tbody>
          <?php $personTotals = []; ?>
          <?php foreach ($accounts as $acct): ?>
            <?php $list = $holdings['David'][$acct] ?? []; ?>
            <?php if (empty($list)): ?>
              <tr><td class="muted" colspan="4">No holdings in <?=h($acct)?></td></tr>
            <?php else: ?>
              <?php
                $tickers = array_map(fn($h) => $h['ticker'] ?? null, $list);
                $priceMap = get_prices_for_tickers($tickers);
                $acctTotals = [];
              ?>
              <tr><th colspan="4" style="text-align:left;"><?=h($acct)?></th></tr>
              <?php foreach ($list as $h): ?>
                <?php
                  $label = $h['label'] ?? '';
                  $qty   = (float)($h['quantity'] ?? 0);
                  $ticker= $h['ticker'] ?? null;
                  $live  = ($ticker && isset($priceMap[$ticker])) ? $priceMap[$ticker] : null;
                  $price = $live['price']   ?? null;
                  $ccy   = $live['currency'] ?? null;
                  $value = ($price !== null) ? ($qty * $price) : null;
                  if ($value !== null && $ccy) {
                    if (!isset($acctTotals[$ccy])) $acctTotals[$ccy] = 0.0;
                    if (!isset($personTotals[$ccy])) $personTotals[$ccy] = 0.0;
                    $acctTotals[$ccy] += $value;
                    $personTotals[$ccy] += $value;
                  }
                  $qtyStr   = rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
                  $priceStr = ($price !== null && $ccy) ? (fmt_money($price, 4).' '.$ccy) : '—';
                  $valStr   = ($value !== null && $ccy) ? (fmt_money($value, 2).' '.$ccy) : '—';
                ?>
                <tr>
                  <td class="wrap"><?=h($label)?></td>
                  <td class="right mono"><?=h($qtyStr)?></td>
                  <td class="right mono"><?=h($priceStr)?></td>
                  <td class="right mono"><?=h($valStr)?></td>
                </tr>
              <?php endforeach; ?>
              <?php
                // Add cash to subtotal if present
                $cashAmt = (float)($cashBalances['David'][$acct]['amount'] ?? 0);
                $cashCcy = $cashBalances['David'][$acct]['currency'] ?? 'GBP';
                if ($cashAmt) {
                  if (!isset($acctTotals[$cashCcy])) $acctTotals[$cashCcy] = 0.0;
                  if (!isset($personTotals[$cashCcy])) $personTotals[$cashCcy] = 0.0;
                  $acctTotals[$cashCcy] += $cashAmt;
                  $personTotals[$cashCcy] += $cashAmt;
                }
              ?>
              <tr>
                <td class="wrap soft" colspan="3">Cash</td>
                <td class="right mono"><?php echo $cashAmt ? (fmt_money($cashAmt, 2).' '.h($cashCcy)) : '—'; ?></td>
              </tr>
              <tr>
                <th colspan="3" class="right">Subtotal (<?=h($acct)?>)</th>
                <th class="right mono"><?php
                  $parts = [];
                  foreach ($acctTotals as $ccy => $sum) { $parts[] = fmt_money($sum, 2).' '.h($ccy); }
                  echo h(implode('; ', $parts)) ?: '—';
                ?></th>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="3" class="right">Total</th>
            <th class="right mono"><?php
              $parts = [];
              foreach ($personTotals as $ccy => $sum) { $parts[] = fmt_money($sum, 2).' '.h($ccy); }
              echo h(implode('; ', $parts)) ?: '—';
            ?></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="card">
      <h2 style="margin-top:0;">Jen's Accounts</h2>
      <?php $accounts = ['Fund & Share','SIPP','ISA']; ?>
      <table>
        <thead>
          <tr>
            <th>Holding</th>
            <th class="right">Quantity</th>
            <th class="right">Live Price</th>
            <th class="right">Holding Value</th>
          </tr>
        </thead>
        <tbody>
          <?php $personTotals = []; ?>
          <?php foreach ($accounts as $acct): ?>
            <?php $list = $holdings['Jen'][$acct] ?? []; ?>
            <?php if (empty($list)): ?>
              <tr><td class="muted" colspan="4">No holdings in <?=h($acct)?></td></tr>
            <?php else: ?>
              <?php
                $tickers = array_map(fn($h) => $h['ticker'] ?? null, $list);
                $priceMap = get_prices_for_tickers($tickers);
                $acctTotals = [];
              ?>
              <tr><th colspan="4" style="text-align:left;"><?=h($acct)?></th></tr>
              <?php foreach ($list as $h): ?>
                <?php
                  $label = $h['label'] ?? '';
                  $qty   = (float)($h['quantity'] ?? 0);
                  $ticker= $h['ticker'] ?? null;
                  $live  = ($ticker && isset($priceMap[$ticker])) ? $priceMap[$ticker] : null;
                  $price = $live['price']   ?? null;
                  $ccy   = $live['currency'] ?? null;
                  $value = ($price !== null) ? ($qty * $price) : null;
                  if ($value !== null && $ccy) {
                    if (!isset($acctTotals[$ccy])) $acctTotals[$ccy] = 0.0;
                    if (!isset($personTotals[$ccy])) $personTotals[$ccy] = 0.0;
                    $acctTotals[$ccy] += $value;
                    $personTotals[$ccy] += $value;
                  }
                  $qtyStr   = rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
                  $priceStr = ($price !== null && $ccy) ? (fmt_money($price, 4).' '.$ccy) : '—';
                  $valStr   = ($value !== null && $ccy) ? (fmt_money($value, 2).' '.$ccy) : '—';
                ?>
                <tr>
                  <td class="wrap"><?=h($label)?></td>
                  <td class="right mono"><?=h($qtyStr)?></td>
                  <td class="right mono"><?=h($priceStr)?></td>
                  <td class="right mono"><?=h($valStr)?></td>
                </tr>
              <?php endforeach; ?>
              <?php
                // Add cash to subtotal if present (Jen)
                $cashAmt = (float)($cashBalances['Jen'][$acct]['amount'] ?? 0);
                $cashCcy = $cashBalances['Jen'][$acct]['currency'] ?? 'GBP';
                if ($cashAmt) {
                  if (!isset($acctTotals[$cashCcy])) $acctTotals[$cashCcy] = 0.0;
                  if (!isset($personTotals[$cashCcy])) $personTotals[$cashCcy] = 0.0;
                  $acctTotals[$cashCcy] += $cashAmt;
                  $personTotals[$cashCcy] += $cashAmt;
                }
              ?>
              <tr>
                <td class="wrap soft" colspan="3">Cash</td>
                <td class="right mono"><?php echo $cashAmt ? (fmt_money($cashAmt, 2).' '.h($cashCcy)) : '—'; ?></td>
              </tr>
              <tr>
                <th colspan="3" class="right">Subtotal (<?=h($acct)?>)</th>
                <th class="right mono"><?php
                  $parts = [];
                  foreach ($acctTotals as $ccy => $sum) { $parts[] = fmt_money($sum, 2).' '.h($ccy); }
                  echo h(implode('; ', $parts)) ?: '—';
                ?></th>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="3" class="right">Total</th>
            <th class="right mono"><?php
              $parts = [];
              foreach ($personTotals as $ccy => $sum) { $parts[] = fmt_money($sum, 2).' '.h($ccy); }
              echo h(implode('; ', $parts)) ?: '—';
            ?></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="card">
      <h2 style="margin-top:0;">Fees, Dividends, Deposits and Withdrawals</h2>
      <table>
        <thead>
          <tr>
            <th>Tax Year</th>
            <th class="right">Total Fees</th>
            <th class="right">Total Dividends</th>
            <th class="right">Deposits</th>
            <th class="right">Withdrawals</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($taxAnnual as $start => $vals): $end = $start + 1; ?>
            <tr>
              <td class="mono"><?=h($start)?>-<?=h($end)?></td>
              <td class="right mono">£<?=fmt_money((float)$vals['fees'], 2)?></td>
              <td class="right mono">£<?=fmt_money((float)$vals['dividends'], 2)?></td>
              <td class="right mono">£<?=fmt_money((float)$vals['deposits'], 2)?></td>
              <td class="right mono">£<?=fmt_money((float)($vals['withdrawals'] ?? 0), 2)?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Gain/Loss Today and This Month -->
    <div class="card">
      <h2 style="margin-top:0;">Gain/Loss Summary</h2>
      <?php
        try {
          // Calculate gain/loss for today, this month, and this tax year
          $today = date('Y-m-d');
          $yesterday = date('Y-m-d', strtotime('-1 day'));
          $monthStart = date('Y-m-01'); // First day of current month
          
          // Calculate UK tax year start (6 April)
          $currentYear = date('Y');
          $currentMonth = date('n');
          if ($currentMonth >= 4) {
            // If we're in April or later, tax year started this year on 6 April
            $taxYearStart = $currentYear . '-04-06';
          } else {
            // If we're before April, tax year started last year on 6 April
            $taxYearStart = ($currentYear - 1) . '-04-06';
          }
          
          // Get database connection
          $pdo = db();
          
          // Define accounts list
          $accounts = [
            ['David', 'SIPP'], ['David', 'ISA'], ['David', 'Fund & Share'],
            ['Jen', 'SIPP'], ['Jen', 'ISA'], ['Jen', 'Fund & Share']
          ];
          
          // Calculate current total portfolio value EXCLUDING deposits and withdrawals
          $currentTotal = 0;
          foreach ($accounts as [$client, $account]) {
            $currentBalance = calculate_historical_account_balance_excluding_deposits($pdo, $client, $account, $today);
            $currentTotal += (float)($currentBalance['total'] ?? 0);
          }
          
          // Calculate historical totals (using historical balance function)
          $yesterdayTotal = 0;
          $monthStartTotal = 0;
          $taxYearStartTotal = 0;
          
          foreach ($accounts as [$client, $account]) {
            $yesterdayBalance = calculate_historical_account_balance_excluding_deposits($pdo, $client, $account, $yesterday);
            $yesterdayTotal += (float)($yesterdayBalance['total'] ?? 0);
            
            $monthStartBalance = calculate_historical_account_balance_excluding_deposits($pdo, $client, $account, $monthStart);
            $monthStartTotal += (float)($monthStartBalance['total'] ?? 0);
            
            $taxYearStartBalance = calculate_historical_account_balance_excluding_deposits($pdo, $client, $account, $taxYearStart);
            $taxYearStartTotal += (float)($taxYearStartBalance['total'] ?? 0);
          }
          
          $todayGainLoss = $currentTotal - $yesterdayTotal;
          $monthGainLoss = $currentTotal - $monthStartTotal;
          $taxYearGainLoss = $currentTotal - $taxYearStartTotal;
          
          // Determine colors based on positive/negative
          $todayColor = $todayGainLoss >= 0 ? '#d4edda' : '#f8d7da';
          $todayTextColor = $todayGainLoss >= 0 ? '#155724' : '#721c24';
          $monthColor = $monthGainLoss >= 0 ? '#d4edda' : '#f8d7da';
          $monthTextColor = $monthGainLoss >= 0 ? '#155724' : '#721c24';
          $taxYearColor = $taxYearGainLoss >= 0 ? '#d4edda' : '#f8d7da';
          $taxYearTextColor = $taxYearGainLoss >= 0 ? '#155724' : '#721c24';
          
          // Display the blocks
          echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">';
          echo '<div style="text-align: center; padding: 1rem; background: ' . $todayColor . '; border-radius: 8px;">';
          echo '<h3 style="margin: 0 0 0.5rem 0; color: ' . $todayTextColor . ';">Gain/Loss Today</h3>';
          echo '<p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: ' . $todayTextColor . ';">';
          echo ($todayGainLoss >= 0 ? '+' : '') . '£' . number_format($todayGainLoss, 2);
          echo '</p></div>';
          
          echo '<div style="text-align: center; padding: 1rem; background: ' . $monthColor . '; border-radius: 8px;">';
          echo '<h3 style="margin: 0 0 0.5rem 0; color: ' . $monthTextColor . ';">Gain/Loss This Month</h3>';
          echo '<p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: ' . $monthTextColor . ';">';
          echo ($monthGainLoss >= 0 ? '+' : '') . '£' . number_format($monthGainLoss, 2);
          echo '</p></div>';
          
          echo '<div style="text-align: center; padding: 1rem; background: ' . $taxYearColor . '; border-radius: 8px;">';
          echo '<h3 style="margin: 0 0 0.5rem 0; color: ' . $taxYearTextColor . ';">Gain/Loss This FY</h3>';
          echo '<p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: ' . $taxYearTextColor . ';">';
          echo ($taxYearGainLoss >= 0 ? '+' : '') . '£' . number_format($taxYearGainLoss, 2);
          echo '</p></div></div>';
          
        } catch (Exception $e) {
          // If any calculation fails, show error message
          echo '<div style="padding: 1rem; background: #f8d7da; border-radius: 8px; color: #721c24;">';
          echo '<p style="margin: 0;"><strong>Error calculating gain/loss:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
          echo '</div>';
        }
      ?>
      
      <p class="note" style="margin-top: 1rem;">
        <strong>Note:</strong> Gain/Loss calculations compare current portfolio value against yesterday's closing value, the start of this month, and the start of the current UK tax year (6 April). 
        Values are calculated using historical price data and exclude deposits/withdrawals to show pure investment performance. May not reflect real-time market movements.
      </p>
    </div>
      </div>
    </div>

    <!-- All Transactions Page -->
    <div id="transactions" class="page">
      <h1>All Transactions</h1>
      <div class="card">
    <h2>All Transactions (newest first)</h2>
    <?php if (empty($rows)): ?>
      <p class="muted">No transactions yet. Import your first CSV above.</p>
    <?php else: ?>
      <div style="overflow:auto;">
      <?php $showAll = isset($_GET['show']) && $_GET['show'] === 'all'; ?>
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Client</th>
            <th>Account</th>
            <th>Type</th>
            <th>Ticker</th>
            <th class="right">Unit cost (p)</th>
            <th class="right">Quantity</th>
            <th class="right">Value (£)</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $renderRows = $rows;
            if (!$showAll && count($rows) > 20) {
                $renderRows = array_slice($rows, 0, 20);
            }
          ?>
          <?php foreach ($renderRows as $r): ?>
            <tr>
              <td class="mono"><?=h($r['trade_date'])?></td>
              <td><span class="pill"><?=h($r['client_name'])?></span></td>
              <td><span class="pill"><?=h($r['account_type'])?></span></td>
              <td class="mono"><?=h($r['type'])?></td>
              <td class="mono"><?=h($r['ticker'] ?? '')?></td>
              <td class="right mono"><?=h($r['unit_cost_p'] ?? '')?></td>
              <td class="right mono"><?=h($r['quantity'] ?? '')?></td>
              <td class="right mono"><?=number_format((float)$r['value_gbp'], 2)?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$showAll && count($rows) > 20): ?>
        <div class="actions" style="margin-top:0.6rem; justify-content:flex-end;">
          <a class="btn" href="?show=all#transactions">Show All (<?=h(count($rows))?>)</a>
        </div>
      <?php elseif ($showAll && count($rows) > 20): ?>
        <div class="actions" style="margin-top:0.6rem; justify-content:flex-end;">
          <a class="btn" href="#transactions">Show 20</a>
        </div>
      <?php endif; ?>
      </div>
    <?php endif; ?>
      </div>
    </div>

    <!-- Import Page -->
    <div id="import" class="page">
      <h1>Import Transactions</h1>
      <div class="card">
        <h2>Import Transactions</h2>
    <form method="post">
      <div class="grid">
        <div>
          <label for="account_type">Account Type</label>
          <select id="account_type" name="account_type" required>
            <?php
              $current = $_POST['account_type'] ?? 'Fund & Share';
              foreach (['SIPP','ISA','Fund & Share'] as $opt) {
                  $sel = $current === $opt ? 'selected' : '';
                  echo "<option value=\"".h($opt)."\" $sel>".h($opt)."</option>";
              }
            ?>
          </select>
        </div>
      </div>

      <div style="margin-top:0.7rem;">
        <label for="csv_text">Paste CSV (as downloaded from HL)</label>
        <textarea id="csv_text" name="csv_text" placeholder="Paste the entire CSV file contents here..." spellcheck="false"><?=h($_POST['csv_text'] ?? '')?></textarea>
        <div class="note">Tip: Paste the whole file; the script finds the transaction table and reads the preamble for client details.</div>
      </div>

      <div class="actions" style="margin-top:0.7rem;">
        <button class="btn" type="submit">Import CSV</button>
        <span class="note">Duplicates are skipped and listed.</span>
          </div>
      </form>

      <form method="post" style="margin-top:0.7rem;">
        <div class="grid">
          <div>
            <label for="rollback_batch_id">Rollback Import (Batch ID)</label>
            <input type="number" id="rollback_batch_id" name="rollback_batch_id" placeholder="e.g. 42" style="width:220px; padding:.45rem .6rem; border-radius:10px; border:1px solid rgba(0,0,0,.15); font: inherit;" />
          </div>
        </div>
        <div class="actions" style="margin-top:0.5rem;">
          <button class="btn" type="submit">Rollback Batch</button>
          <span class="note">Removes all transactions tagged with that import batch.</span>
      </div>
    </form>

    <div class="msgs">
      <?php foreach ($messages as $m): ?>
        <div class="msg <?=$m['type']?>"><?=h($m['text'])?></div>
      <?php endforeach; ?>
    </div>

    <?php if ($summary && !empty($summary['duplicate_lines'])): ?>
      <details>
        <summary>View <?=$summary['duplicates']?> duplicate line<?=($summary['duplicates']===1?'':'s')?> skipped</summary>
        <ul>
          <?php foreach ($summary['duplicate_lines'] as $d): ?>
            <li class="soft">
              Line <?=h($d['line_no'])?> — <?=h($d['trade_date'])?> — <?=h($d['reference'])?> — £<?=h(number_format((float)$d['value_gbp'], 2))?>
              <br><span class="muted"><?=h($d['desc'])?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  </div>

      </div>
    </div>

    <!-- Cash Balances Page -->
    <div id="cash" class="page">
      <h1>Cash Balances</h1>
      <div class="card">
        <h2>Cash Balances (Auto-Calculated)</h2>
        <p class="note">Cash balances are automatically calculated from transaction history.</p>
        <table>
          <thead>
            <tr>
              <th>Account</th>
              <th class="right">Calculated Cash</th>
            </tr>
          </thead>
          <tbody>
            <tr><td>David Fund &amp; Share</td><td class="right mono">£<?=number_format($cashBalances['David']['Fund & Share']['amount'], 2)?></td></tr>
            <tr><td>David SIPP</td><td class="right mono">£<?=number_format($cashBalances['David']['SIPP']['amount'], 2)?></td></tr>
            <tr><td>David ISA</td><td class="right mono">£<?=number_format($cashBalances['David']['ISA']['amount'], 2)?></td></tr>
            <tr><td>Jen Fund &amp; Share</td><td class="right mono">£<?=number_format($cashBalances['Jen']['Fund & Share']['amount'], 2)?></td></tr>
            <tr><td>Jen SIPP</td><td class="right mono">£<?=number_format($cashBalances['Jen']['SIPP']['amount'], 2)?></td></tr>
            <tr><td>Jen ISA</td><td class="right mono">£<?=number_format($cashBalances['Jen']['ISA']['amount'], 2)?></td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Performance Page -->
    <div id="performance" class="page">
      <h1>Performance</h1>
      <div class="card">
        <h2>Historical Balance Test - David's SIPP (Month-End)</h2>
    <p class="note">Showing total account value (holdings + cash) at the end of each month for the past 3 years.</p>
    
    <?php
      // Generate month-end dates for the past 3 years
      $monthEndDates = [];
      $today = new DateTime();
      $startDate = clone $today;
      $startDate->modify('-3 years');
      $startDate->modify('first day of this month');
      
      $current = clone $startDate;
      while ($current <= $today) {
        $monthEnd = clone $current;
        $monthEnd->modify('last day of this month');
        
        // Only include if the month-end is not in the future
        if ($monthEnd <= $today) {
          $monthEndDates[] = $monthEnd->format('Y-m-d');
        }
        
        $current->modify('+1 month');
      }
      
      // Reverse to show most recent first
      $monthEndDates = array_reverse($monthEndDates);
    ?>
    
    <?php if (empty($monthEndDates)): ?>
      <p class="muted">No month-end dates to display.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Month-End Date</th>
            <th class="right">Holdings Value</th>
            <th class="right">Cash Balance</th>
            <th class="right">Total Value</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($monthEndDates as $date): ?>
            <?php 
              $balance = calculate_historical_account_balance(db(), 'David', 'SIPP', $date);
              $holdingsValue = $balance['holdings']['GBP'] ?? 0;
              $cashBalance = $balance['cash'];
              $totalValue = $balance['total'];
            ?>
            <tr>
              <td class="mono"><?=h($date)?></td>
              <td class="right mono">£<?=number_format($holdingsValue - $cashBalance, 2)?></td>
              <td class="right mono">£<?=number_format($cashBalance, 2)?></td>
              <td class="right mono">£<?=number_format($totalValue, 2)?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      
        <p class="note" style="margin-top:0.8rem;">
          <strong>Note:</strong> This calculation uses historical price data from the <code>hl_prices_historical</code> table 
          and transaction history to determine holdings and cash balances as of each month-end date.
        </p>
      <?php endif; ?>
      </div>
    </div>

    <!-- Dividend Projections Page -->
    <div id="dividends" class="page">
      <h1>Dividend Projections</h1>
      
      <?php
        // Get dividend yield data for all active tickers
        $dividendYields = [];
        try {
          $pdo = db();
          $stmt = $pdo->query("SELECT ticker, dividend_yield, dividend_rate, currency FROM hl_yield_latest ORDER BY ticker");
          while ($row = $stmt->fetch()) {
            $dividendYields[$row['ticker']] = [
              'yield' => (float)$row['dividend_yield'],
              'rate' => (float)$row['dividend_rate'],
              'currency' => $row['currency']
            ];
          }
        } catch (Throwable $t) {
          $dividendYields = [];
        }
        
        // Calculate dividend projections for each client and account
        $dividendProjections = [];
        $clients = ['David', 'Jen'];
        $accounts = ['Fund & Share', 'SIPP', 'ISA'];
        
        foreach ($clients as $client) {
          $dividendProjections[$client] = [];
          foreach ($accounts as $account) {
            $accountHoldings = $holdings[$client][$account] ?? [];
            $accountTotal = 0.0;
            $accountYield = 0.0;
            $holdingsWithYields = [];
            
            foreach ($accountHoldings as $holding) {
              $ticker = $holding['ticker'] ?? '';
              $quantity = (float)($holding['quantity'] ?? 0);
              
              if ($ticker && isset($dividendYields[$ticker])) {
                $yieldData = $dividendYields[$ticker];
                $yield = $yieldData['yield'];
                $rate = $yieldData['rate'];
                
                // Get current price for this ticker
                $currentPrice = 0.0;
                try {
                  $priceStmt = $pdo->prepare("SELECT price FROM hl_prices_latest WHERE ticker = ?");
                  $priceStmt->execute([$ticker]);
                  $priceRow = $priceStmt->fetch();
                  if ($priceRow) {
                    $currentPrice = (float)$priceRow['price'];
                  }
                } catch (Throwable $t) {
                  // If no current price, skip this holding
                  continue;
                }
                
                if ($currentPrice > 0) {
                  // Calculate holding value and annual dividend based on yield
                  $holdingValue = $quantity * $currentPrice;
                  $annualDividend = $holdingValue * ($yield / 100); // Convert yield % to decimal
                  $accountTotal += $annualDividend;
                  
                  // Calculate weighted yield (holding value * yield)
                  $weightedYield = $holdingValue * $yield;
                  $accountYield += $weightedYield;
                  
                  $holdingsWithYields[] = [
                    'ticker' => $ticker,
                    'quantity' => $quantity,
                    'current_price' => $currentPrice,
                    'holding_value' => $holdingValue,
                    'yield' => $yield,
                    'rate' => $rate,
                    'annual_dividend' => $annualDividend
                  ];
                }
              }
            }
            
            // Calculate average yield for this account (weighted by holding value)
            $totalHoldingValue = array_sum(array_column($holdingsWithYields, 'holding_value'));
            $averageYield = $totalHoldingValue > 0 ? $accountYield / $totalHoldingValue : 0.0;
            
            $dividendProjections[$client][$account] = [
              'holdings' => $holdingsWithYields,
              'total_annual_dividend' => $accountTotal,
              'average_yield' => $averageYield,
              'total_quantity' => $totalQuantity
            ];
          }
        }
        
        // Calculate overall totals
        $overallTotal = 0.0;
        $overallWeightedYield = 0.0;
        $overallTotalHoldingValue = 0.0;
        
        foreach ($dividendProjections as $client => $clientAccounts) {
          foreach ($clientAccounts as $account => $data) {
            $overallTotal += $data['total_annual_dividend'];
            $accountHoldingValue = array_sum(array_column($data['holdings'], 'holding_value'));
            $overallWeightedYield += $data['average_yield'] * $accountHoldingValue;
            $overallTotalHoldingValue += $accountHoldingValue;
          }
        }
        
        $overallAverageYield = $overallTotalHoldingValue > 0 ? $overallWeightedYield / $overallTotalHoldingValue : 0.0;
        $monthlyTotal = $overallTotal / 12;
        
        // Calculate Fund & Share totals for both clients
        $fundShareTotal = 0.0;
        foreach ($dividendProjections as $client => $clientAccounts) {
          if (isset($clientAccounts['Fund & Share'])) {
            $fundShareTotal += $clientAccounts['Fund & Share']['total_annual_dividend'];
          }
        }
      ?>
      
      <?php foreach ($clients as $client): ?>
        <div class="card">
          <h2><?=h($client)?>'s Dividend Projections</h2>
          
          <?php foreach ($accounts as $account): ?>
            <?php $data = $dividendProjections[$client][$account]; ?>
            <?php if (!empty($data['holdings'])): ?>
              <h3><?=h($account)?></h3>
              <table>
                <thead>
                  <tr>
                    <th>Ticker</th>
                    <th class="right">Quantity</th>
                    <th class="right">Current Price</th>
                    <th class="right">Holding Value</th>
                    <th class="right">Yield %</th>
                    <th class="right">Annual Dividend</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($data['holdings'] as $holding): ?>
                    <tr>
                      <td class="mono"><?=h($holding['ticker'])?></td>
                      <td class="right mono"><?=number_format($holding['quantity'], 2)?></td>
                      <td class="right mono">£<?=number_format($holding['current_price'], 2)?></td>
                      <td class="right mono">£<?=number_format($holding['holding_value'], 2)?></td>
                      <td class="right mono"><?=number_format($holding['yield'], 2)?>%</td>
                      <td class="right mono">£<?=number_format($holding['annual_dividend'], 2)?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <th colspan="3" class="right">Account Totals:</th>
                    <th class="right mono">£<?=number_format(array_sum(array_column($data['holdings'], 'holding_value')), 2)?></th>
                    <th class="right mono"><?=number_format($data['average_yield'], 2)?>%</th>
                    <th class="right mono">£<?=number_format($data['total_annual_dividend'], 2)?></th>
                  </tr>
                </tfoot>
              </table>
            <?php else: ?>
              <h3><?=h($account)?></h3>
              <p class="muted">No holdings with dividend yield data.</p>
            <?php endif; ?>
          <?php endforeach; ?>
          
          <?php
            // Calculate client totals
            $clientTotal = 0.0;
            $clientWeightedYield = 0.0;
            $clientTotalHoldingValue = 0.0;
            
            foreach ($dividendProjections[$client] as $account => $data) {
              $clientTotal += $data['total_annual_dividend'];
              $accountHoldingValue = array_sum(array_column($data['holdings'], 'holding_value'));
              $clientWeightedYield += $data['average_yield'] * $accountHoldingValue;
              $clientTotalHoldingValue += $accountHoldingValue;
            }
            
            $clientAverageYield = $clientTotalHoldingValue > 0 ? $clientWeightedYield / $clientTotalHoldingValue : 0.0;
          ?>
          
          <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 8px;">
            <h4 style="margin: 0 0 0.5rem 0;"><?=h($client)?>'s Total Projections</h4>
            <p style="margin: 0;"><strong>Average Yield:</strong> <?=number_format($clientAverageYield, 2)?>%</p>
            <p style="margin: 0;"><strong>Annual Dividend:</strong> £<?=number_format($clientTotal, 2)?></p>
            <p style="margin: 0;"><strong>Monthly Dividend:</strong> £<?=number_format($clientTotal / 12, 2)?></p>
          </div>
        </div>
      <?php endforeach; ?>
      
      <!-- Overall Summary -->
      <div class="card">
        <h2>Overall Dividend Summary</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
          <div style="text-align: center; padding: 1rem; background: #e7f8ef; border-radius: 8px;">
            <h3 style="margin: 0 0 0.5rem 0; color: #28a745;">Average Yield</h3>
            <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #28a745;"><?=number_format($overallAverageYield, 2)?>%</p>
          </div>
          <div style="text-align: center; padding: 1rem; background: #d1ecf1; border-radius: 8px;">
            <h3 style="margin: 0 0 0.5rem 0; color: #0c5460;">Annual Total</h3>
            <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #0c5460;">£<?=number_format($overallTotal, 2)?></p>
          </div>
          <div style="text-align: center; padding: 1rem; background: #fff3cd; border-radius: 8px;">
            <h3 style="margin: 0 0 0.5rem 0; color: #856404;">Annual Total from Fund & Share</h3>
            <p style="margin: 0; font-size: 1.5rem; font-weight: bold; color: #856404;">£<?=number_format($fundShareTotal, 2)?></p>
          </div>
        </div>
        
        <p class="note" style="margin-top: 1rem;">
          <strong>Note:</strong> These projections are based on current holdings and latest dividend yield data. 
          Actual dividends may vary based on company dividend policies and market conditions.
        </p>
      </div>
    </div>

    <!-- Capital Gains Page -->
    <div id="gains" class="page">
      <h1>Capital Gains</h1>
      
      <div class="card">
        <h2>TSV Export for CGT Calculator</h2>
        <p class="note">Generate transaction data in the format required by cgtcalculator.com for capital gains tax calculations. Data is exported as tab-separated values (TSV) for all-time transactions.</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-top: 1rem;">
          <!-- David's Fund & Share -->
          <div style="padding: 1rem; background: #f8f9fa; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0 0 1rem 0;">David's Fund & Share</h3>
            <button onclick="generateCSV('David', 'Fund & Share')" 
                    class="btn" 
                    style="font-size: 1rem; padding: 0.8rem; width: 100%;">
              Generate All-Time TSV
            </button>
          </div>
          
          <!-- Jen's Fund & Share -->
          <div style="padding: 1rem; background: #f8f9fa; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0 0 1rem 0;">Jen's Fund & Share</h3>
            <button onclick="generateCSV('Jen', 'Fund & Share')" 
                    class="btn" 
                    style="font-size: 1rem; padding: 0.8rem; width: 100%;">
              Generate All-Time TSV
            </button>
          </div>
        </div>
        
        <div style="margin-top: 1.5rem;">
          <label for="csv_output" style="display: block; margin-bottom: 0.5rem; font-weight: 600;">TSV Data:</label>
          <textarea id="csv_output" 
                    style="width: 100%; height: 200px; font-family: monospace; font-size: 0.9rem; padding: 0.8rem; border: 1px solid #ddd; border-radius: 6px; resize: vertical;" 
                    placeholder="Click a button above to generate TSV data for the selected account..."></textarea>
          <div style="margin-top: 0.5rem; display: flex; gap: 0.5rem;">
            <button onclick="copyToClipboard()" class="btn" style="font-size: 0.9rem; padding: 0.5rem;">Copy to Clipboard</button>
            <button onclick="clearCSV()" class="btn" style="font-size: 0.9rem; padding: 0.5rem; background: #6c757d;">Clear</button>
          </div>
        </div>
        
        <p class="note" style="margin-top: 1rem;">
          <strong>Note:</strong> CSV data includes Buy/Sell transactions only for the specified tax year (6 April to 5 April). 
          Charges and stamp duty are set to zero as recommended. Copy the data and paste it into cgtcalculator.com.
        </p>
      </div>
    </div>

  </main>

  <script>
    // Navigation functionality
    document.addEventListener('DOMContentLoaded', function() {
      const navLinks = document.querySelectorAll('.nav-link');
      const pages = document.querySelectorAll('.page');
      
      navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
          e.preventDefault();
          
          // Remove active class from all links and pages
          navLinks.forEach(l => l.classList.remove('active'));
          pages.forEach(p => p.classList.remove('active'));
          
          // Add active class to clicked link
          this.classList.add('active');
          
          // Show corresponding page
          const targetPage = this.getAttribute('data-page');
          const targetElement = document.getElementById(targetPage);
          if (targetElement) {
            targetElement.classList.add('active');
          }
        });
      });
    });

    // CSV Generation functionality
    function generateCSV(client, account) {
      const csvOutput = document.getElementById('csv_output');
      csvOutput.value = 'Loading...';
      
      // Create form data
      const formData = new FormData();
      formData.append('generate_csv', '1');
      formData.append('client', client);
      formData.append('account', account);
      
      // Make AJAX request
      fetch(window.location.href, {
        method: 'POST',
        body: formData
      })
      .then(response => response.text())
      .then(data => {
        csvOutput.value = data;
        csvOutput.style.borderColor = '#28a745';
        setTimeout(() => {
          csvOutput.style.borderColor = '#ddd';
        }, 2000);
      })
      .catch(error => {
        csvOutput.value = 'Error generating TSV: ' + error.message;
        csvOutput.style.borderColor = '#dc3545';
      });
    }

    function copyToClipboard() {
      const csvOutput = document.getElementById('csv_output');
      csvOutput.select();
      csvOutput.setSelectionRange(0, 99999); // For mobile devices
      
      try {
        document.execCommand('copy');
        csvOutput.style.borderColor = '#28a745';
        setTimeout(() => {
          csvOutput.style.borderColor = '#ddd';
        }, 2000);
      } catch (err) {
        alert('Failed to copy to clipboard');
      }
    }

    function clearCSV() {
      document.getElementById('csv_output').value = '';
    }
  </script>

</body>
</html>