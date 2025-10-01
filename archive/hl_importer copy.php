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
function detect_type(string $reference, string $description): string {
    $r = strtoupper(trim($reference));
    $d = trim($description);

    if (strpos($r, 'B') === 0) return 'Buy';
    if (strpos($r, 'S') === 0) return 'Sell';
    if ($r === 'INTEREST') return 'Interest';
    if ($r === 'MANAGE FEE') return 'Fee';
    if ($d === 'Transfer from Income Account') return 'Dividend Transfer';

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

        $type   = detect_type($reference, $description);
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
    // Dividends include both explicit 'Dividend' and 'Dividend Transfer' types if present.
    $sql = "
        SELECT
          YEAR(trade_date) AS y,
          SUM(CASE WHEN type = 'Fee' THEN ABS(COALESCE(value_gbp,0)) ELSE 0 END) AS fees,
          SUM(CASE WHEN type IN ('Dividend','Dividend Transfer') THEN COALESCE(value_gbp,0) ELSE 0 END) AS dividends,
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

$rows = [];
$holdings = [];
try {
    $rows = fetch_all_transactions();
    $holdings = fetch_current_holdings();
    $annual = fetch_annual_totals(2015);
} catch (Throwable $t) { /* table may not exist yet */ }

// ---- View ----
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Investment Reporting Tool</title>
<style>
  :root { color-scheme: light dark; }
  body {
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    margin: 1.5rem;
    font-size: 0.95rem; /* nudged down slightly */
  }
  h1 { font-size: 1.4rem; margin: 0 0 0.8rem; }
  h2 { font-size: 1.1rem; margin: 0.2rem 0 0.6rem; }
  .card {
    background: rgba(250,250,250,.8);
    backdrop-filter: blur(6px);
    border: 1px solid rgba(0,0,0,.08);
    border-radius: 12px;
    padding: 0.85rem;
    box-shadow: 0 6px 22px rgba(0,0,0,.06);
  }
  form .grid { display: grid; gap: 0.8rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
  label { display:block; font-weight:600; margin-bottom:.3rem; font-size: 0.95em; }
  select, textarea {
    width: 100%;
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
  table { width:100%; border-collapse: collapse; margin-top: 0.8rem; font-size:.9rem; } /* slightly smaller */
  th, td { padding:.45rem .5rem; border-bottom:1px solid rgba(0,0,0,.07); text-align:left; vertical-align: top; }
  thead th { position: sticky; top: 0; background: #fafafa; border-bottom:2px solid rgba(0,0,0,.15); }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
  .pill { display:inline-block; padding:.12rem .45rem; border-radius:999px; font-size:.8rem; border:1px solid rgba(0,0,0,.2); background:#fff; }
  .right { text-align:right; }
  .muted { color:#666; }
  .wrap { white-space: normal; max-width: 52ch; }
  .soft { color:#333; }
</style>
</head>
<body>
  <h1>Investment Reporting Tool</h1>

  <div class="card" style="margin-bottom:0.9rem;">
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

    <?php if (!empty($parsed_client['name'])): ?>
      <p class="note">Detected client: <strong><?=h($parsed_client['name'])?></strong></p>
    <?php endif; ?>
  </div>

  <div class="card">
  <h2>Accounts Summary</h2>
  <?php
    $clients  = ['David','Jen'];
    $accounts = ['Fund & Share','SIPP','ISA'];

    foreach ($clients as $client):
  ?>
    <h3 style="margin-top:0.6rem;"><?=h($client)?></h3>
    <div class="grid">
      <?php foreach ($accounts as $acct): ?>
        <div>
          <h4 style="margin:0.2rem 0 0.4rem; font-size:1rem;"><?=h($acct)?></h4>
          <?php $list = $holdings[$client][$acct] ?? []; ?>

          <?php if (empty($list)): ?>
            <p class="muted" style="margin:0;">No holdings.</p>
          <?php else: ?>
            <?php
              // Build a price cache for the tickers in this account
              $tickers = array_map(fn($h) => $h['ticker'] ?? null, $list);
              $priceMap = get_prices_for_tickers($tickers);

              // Track account totals per currency (GBP, USD, …)
              $acctTotals = []; // e.g. ['GBP' => 12345.67, 'USD' => 890.12]
            ?>
            <table>
              <thead>
                <tr>
                  <th>Holding</th>
                  <th>Ticker</th>
                  <th class="right">Quantity</th>
                  <th class="right">Live Price</th>
                  <th class="right">Holding Value</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($list as $h): ?>
                  <?php
                    $label   = $h['label'] ?? '';
                    $qty     = (float)($h['quantity'] ?? 0);
                    $ticker  = $h['ticker'] ?? null;

                    $live    = ($ticker && isset($priceMap[$ticker])) ? $priceMap[$ticker] : null;
                    $price   = $live['price']   ?? null;
                    $ccy     = $live['currency'] ?? null;

                    $value   = ($price !== null) ? ($qty * $price) : null;

                    if ($value !== null && $ccy) {
                      if (!isset($acctTotals[$ccy])) $acctTotals[$ccy] = 0.0;
                      $acctTotals[$ccy] += $value;
                    }

                    $qtyStr   = rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
                    $priceStr = ($price !== null && $ccy) ? (fmt_money($price, 4).' '.$ccy) : '—';
                    $valStr   = ($value !== null && $ccy) ? (fmt_money($value, 2).' '.$ccy) : '—';
                  ?>
                  <tr>
                    <td class="wrap"><?=h($label)?></td>
                    <td class="mono"><?=h($ticker ?? '')?></td>
                    <td class="right mono"><?=h($qtyStr)?></td>
                    <td class="right mono"><?=h($priceStr)?></td>
                    <td class="right mono"><?=h($valStr)?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <?php if (!empty($acctTotals)): ?>
                <tfoot>
                  <tr>
                    <th colspan="4" class="right">Account Total</th>
                    <th class="right mono">
                      <?php
                        // Show totals per currency (e.g., "£12,345.67 GBP; $890.12 USD")
                        $parts = [];
                        foreach ($acctTotals as $ccy => $sum) {
                          $parts[] = fmt_money($sum, 2) . ' ' . h($ccy);
                        }
                        echo h(implode('; ', $parts));
                      ?>
                    </th>
                  </tr>
                </tfoot>
              <?php endif; ?>
            </table>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

  <div class="card">
    <h2>Fees, Dividends and Deposits</h2>
    <?php
      $startYear = 2015;
      $endYear = (int)date('Y');
    ?>
    <table>
      <thead>
        <tr>
          <th>Year</th>
          <th class="right">Total Fees</th>
          <th class="right">Total Dividends</th>
          <th class="right">Deposits</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($y = $startYear; $y <= $endYear; $y++): $vals = $annual[$y] ?? ['fees'=>0,'dividends'=>0,'deposits'=>0]; ?>
          <tr>
            <td class="mono"><?=h($y)?></td>
            <td class="right mono">£<?=number_format((float)$vals['fees'], 2)?></td>
            <td class="right mono">£<?=number_format((float)$vals['dividends'], 2)?></td>
            <td class="right mono">£<?=number_format((float)$vals['deposits'], 2)?></td>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>All Transactions (newest first)</h2>
    <?php if (empty($rows)): ?>
      <p class="muted">No transactions yet. Import your first CSV above.</p>
    <?php else: ?>
      <div style="overflow:auto;">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Settle</th>
            <th>Client</th>
            <th>Account</th>
            <th>Reference</th>
            <th>Type</th>
            <th>Ticker</th>
            <th>Description</th>
            <th class="right">Unit cost (p)</th>
            <th class="right">Quantity</th>
            <th class="right">Value (£)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="mono"><?=h($r['trade_date'])?></td>
              <td class="mono"><?=h($r['settle_date'] ?? '')?></td>
              <td><span class="pill"><?=h($r['client_name'])?></span></td> <!-- client number removed -->
              <td><span class="pill"><?=h($r['account_type'])?></span></td>
              <td class="mono"><?=h($r['reference'])?></td>
              <td class="mono"><?=h($r['type'])?></td>
              <td class="mono"><?=h($r['ticker'] ?? '')?></td>
              <td class="wrap"><?=h($r['description'])?></td>
              <td class="right mono"><?=h($r['unit_cost_p'] ?? '')?></td>
              <td class="right mono"><?=h($r['quantity'] ?? '')?></td>
              <td class="right mono"><?=number_format((float)$r['value_gbp'], 2)?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <p class="note" style="margin-top:0.8rem;">Next: export buttons & filters (date range/account), then CGT logic.</p>
</body>
</html>