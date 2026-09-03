<?php
// ============================================================
// YarraPower Stock Management — PHP Backend API
// ============================================================
// SETUP INSTRUCTIONS:
//   1. Open your Plesk panel → Databases → Create a new database
//   2. Import database.sql into that database
//   3. Fill in DB_HOST, DB_NAME, DB_USER, DB_PASS below
//   4. Upload api.php and stock-management.html to your web root
//   5. Open stock-management.html → first-time setup wizard appears
// ============================================================

// -- Session hardening: HttpOnly + SameSite cookies (secure flag auto-set on HTTPS) --
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// -- Same-origin only. Cross-origin browsers are NOT granted credentialed access. --
// (Previously this reflected any Origin with credentials allowed, which would let
//  any external website call this API with a logged-in user's session.)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $selfHost   = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    if ($originHost && $selfHost && strcasecmp($originHost, $selfHost) === 0) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// ============================================================
// ★ CONFIGURATION — CHANGE THESE BEFORE UPLOADING ★
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'yarra_StockYP');   // ← your Plesk DB name
define('DB_USER', 'yarra_StockYP');   // ← your Plesk DB user
define('DB_PASS', 'yarra_StockYP'); // ← your Plesk DB password
// ============================================================

// ---- DB CONNECTION ----
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed. Check DB credentials in api.php.']);
    exit();
}

// ============================================================
// HELPERS
// ============================================================
function resp($data)       { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit(); }
function ok($data = [])    { resp(array_merge(['success' => true], $data)); }
function err($msg, $code = 400) { http_response_code($code); resp(['success' => false, 'error' => $msg]); }
function body()            { return json_decode(file_get_contents('php://input'), true) ?? []; }

function uid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

function requireAuth() {
    if (empty($_SESSION['user_id'])) err('Not authenticated. Please log in.', 401);
}

function currentUser($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    if ($_SESSION['user_id'] === 'guest') {
        return ['id' => 'guest', 'name' => 'Guest', 'username' => 'guest', 'role' => 'View Only'];
    }
    $s = $pdo->prepare('SELECT id, name, username, role FROM users WHERE id = ? AND is_active = 1');
    $s->execute([$_SESSION['user_id']]);
    return $s->fetch() ?: null;
}

function requireRole($pdo, $roles) {
    requireAuth();
    $u = currentUser($pdo);
    if (!$u || !in_array($u['role'], (array)$roles)) err('Access denied for your role.', 403);
    return $u;
}

function getCounter($pdo, $name) {
    $s = $pdo->prepare('SELECT value FROM app_counters WHERE name = ?');
    $s->execute([$name]);
    $r = $s->fetch();
    return $r ? (int)$r['value'] : 1;
}

function nextCounter($pdo, $name) {
    $val = getCounter($pdo, $name);
    $pdo->prepare('UPDATE app_counters SET value = value + 1 WHERE name = ?')->execute([$name]);
    return str_pad($val, 4, '0', STR_PAD_LEFT);
}

function logActivity($pdo, $desc, $type = 'green') {
    $pdo->prepare('INSERT INTO activity_log (description, type) VALUES (?,?)')->execute([$desc, $type]);
}

function intCast($v)   { return (int)$v; }
function floatCast($v) { return round((float)$v, 2); }

// ── YarraBoard auto-migration + backfill (runs on every init, idempotent) ──
function ybMigrate($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_acc_lines (
        id          VARCHAR(36)  NOT NULL,
        booking_id  VARCHAR(36)  NOT NULL,
        acc_id      VARCHAR(50)  DEFAULT NULL,
        acc_name    VARCHAR(500) NOT NULL DEFAULT '',
        sheet       VARCHAR(200) NOT NULL DEFAULT '',
        qty         INT          NOT NULL DEFAULT 0,
        unit        VARCHAR(50)  NOT NULL DEFAULT 'pcs',
        PRIMARY KEY (id),
        KEY idx_bal_bk (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS yb_booking_meta (
        booking_id       VARCHAR(36)    NOT NULL,
        yb_status        VARCHAR(50)    NOT NULL DEFAULT 'Request',
        so_number        VARCHAR(100)   NOT NULL DEFAULT '',
        claim_json       MEDIUMTEXT     DEFAULT NULL,
        return_lines_json MEDIUMTEXT    DEFAULT NULL,
        return_note      TEXT           DEFAULT NULL,
        pr_items_json    MEDIUMTEXT     DEFAULT NULL,
        created_by       VARCHAR(200)   NOT NULL DEFAULT '',
        PRIMARY KEY (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Backfill: create yb_booking_meta for any existing bookings that don't have one
    // (covers bookings created before this migration was added)
    $pdo->exec("INSERT IGNORE INTO yb_booking_meta (booking_id, yb_status, created_by)
        SELECT b.id, CASE b.status
            WHEN 'Active'    THEN 'Request'
            WHEN 'Confirmed' THEN 'Confirmed'
            ELSE 'Request'
        END, COALESCE(b.created_by,'Unknown')
        FROM bookings b
        WHERE b.status NOT IN ('Cancelled','Expired')
          AND b.id NOT IN (SELECT booking_id FROM yb_booking_meta)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS yb_pr_reports (
        id          VARCHAR(36)   NOT NULL,
        pr_num      VARCHAR(100)  NOT NULL,
        bk_number   VARCHAR(100)  NOT NULL DEFAULT '',
        customer    VARCHAR(500)  NOT NULL DEFAULT '',
        project     VARCHAR(500)  NOT NULL DEFAULT '',
        date        DATE          DEFAULT NULL,
        date_needed DATE          DEFAULT NULL,
        urgency     VARCHAR(50)   NOT NULL DEFAULT 'Normal',
        total       DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_cost  DECIMAL(12,2) DEFAULT NULL,
        pr_status   VARCHAR(50)   NOT NULL DEFAULT 'Request',
        created_by  VARCHAR(200)  NOT NULL DEFAULT '',
        notes       TEXT          DEFAULT NULL,
        items_json  MEDIUMTEXT    DEFAULT NULL,
        created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── yb_document_reports — auto-generated document report store ──
    $pdo->exec("CREATE TABLE IF NOT EXISTS yb_document_reports (
        id           VARCHAR(36)   NOT NULL,
        title        VARCHAR(255)  NOT NULL,
        description  TEXT          DEFAULT NULL,
        topic        VARCHAR(50)   NOT NULL,
        authority    VARCHAR(255)  DEFAULT '[\"all\"]',
        period       VARCHAR(100)  DEFAULT NULL,
        status       VARCHAR(20)   NOT NULL DEFAULT 'Draft',
        author       VARCHAR(100)  DEFAULT NULL,
        pages        INT           DEFAULT 0,
        file_size    VARCHAR(50)   DEFAULT '—',
        summary_json MEDIUMTEXT    DEFAULT NULL,
        generated_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
        created_by   VARCHAR(100)  DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_topic (topic),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT IGNORE INTO app_counters (name, value) VALUES ('doc_report', 1)");

    // ── Serial Number Tracking — shipments + serial_units ──
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN is_serialized TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS shipments (
        id           VARCHAR(36)  NOT NULL,
        ref          VARCHAR(100) DEFAULT NULL,
        po_number    VARCHAR(100) DEFAULT NULL,
        supplier     VARCHAR(200) DEFAULT NULL,
        arrival_date DATE         NOT NULL,
        received_by  VARCHAR(100) DEFAULT NULL,
        notes        TEXT         DEFAULT NULL,
        created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS serial_units (
        id               INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
        serial_no        VARCHAR(200)  NOT NULL,
        product_id       VARCHAR(36)   DEFAULT NULL,
        product_type     ENUM('Panel','Inverter','Battery') NOT NULL,
        brand            VARCHAR(100)  DEFAULT NULL,
        model            VARCHAR(200)  DEFAULT NULL,
        watt_rating      DECIMAL(8,2)  DEFAULT NULL,
        manufacture_date DATE          DEFAULT NULL,
        shipment_id      VARCHAR(36)   DEFAULT NULL,
        arrival_date     DATE          NOT NULL,
        condition_status ENUM('New','Damaged','Returned','Warranty Claim') NOT NULL DEFAULT 'New',
        status           ENUM('In Stock','Reserved','Released','Warranty Claim','Written Off') NOT NULL DEFAULT 'In Stock',
        project_id       VARCHAR(50)   DEFAULT NULL,
        booking_id       VARCHAR(36)   DEFAULT NULL,
        booking_number   VARCHAR(50)   DEFAULT NULL,
        so_number        VARCHAR(50)   DEFAULT NULL,
        release_date     DATE          DEFAULT NULL,
        released_by      VARCHAR(100)  DEFAULT NULL,
        direct_to_site   TINYINT(1)   NOT NULL DEFAULT 0,
        notes            TEXT          DEFAULT NULL,
        created_at       DATETIME      DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_serial_no (serial_no),
        INDEX idx_product_type (product_type),
        INDEX idx_status (status),
        INDEX idx_arrival_date (arrival_date),
        INDEX idx_project (project_id),
        INDEX idx_booking (booking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── serial_units: update product_type ENUM if old values still present ──
    try {
        $pdo->exec("ALTER TABLE serial_units MODIFY COLUMN product_type ENUM('Panel','Inverter','Battery') NOT NULL");
    } catch (Exception $e) {}
    // ── serial_units: add pallet_ref column ──
    try {
        $pdo->exec("ALTER TABLE serial_units ADD COLUMN pallet_ref VARCHAR(100) DEFAULT NULL AFTER watt_rating");
    } catch (Exception $e) {}

    // ── project_id on purchase_orders ──
    try {
        $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN project_id VARCHAR(30) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN project_name VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {}

    // ── exam_results table for YarraExam backend ──
    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_results (
        id           INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
        staff_id     VARCHAR(100)  NOT NULL,
        staff_name   VARCHAR(255)  NOT NULL,
        score        INT           NOT NULL,
        passed       TINYINT(1)    NOT NULL DEFAULT 0,
        time_taken   INT           DEFAULT NULL,
        cat_a_score  INT           DEFAULT NULL,
        cat_b_score  INT           DEFAULT NULL,
        cat_c_score  INT           DEFAULT NULL,
        cat_d_score  INT           DEFAULT NULL,
        answers_json MEDIUMTEXT    DEFAULT NULL,
        taken_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_staff (staff_id),
        INDEX idx_passed (passed),
        INDEX idx_taken (taken_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// ──────────────────────────────────────────────────────────────────────

// ── Accessories helpers ───────────────────────────────────────────────
function accMigrate($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS accessories (
        id                  INT          NOT NULL,
        sheet               VARCHAR(200) NOT NULL DEFAULT '',
        item_name           VARCHAR(500) NOT NULL DEFAULT '',
        length_m            DECIMAL(10,2) DEFAULT NULL,
        quantity            INT          DEFAULT NULL,
        quantity_incomplete INT          DEFAULT NULL,
        remark              TEXT         DEFAULT NULL,
        updated_at          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── YarraExam helpers ─────────────────────────────────────────────────
function examMigrate($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_staff (
        staff_id      VARCHAR(50)  NOT NULL,
        fullname      VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        is_active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_by    VARCHAR(100) DEFAULT NULL,
        created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function examConfig() {
    return [
        'cats'   => ['A'=>['mc'=>9,'tf'=>2], 'B'=>['mc'=>2,'tf'=>1], 'C'=>['mc'=>9,'tf'=>2], 'D'=>['mc'=>2,'tf'=>1]],
        'labels' => ['A'=>'ระบบโซลาร์และอุปกรณ์','B'=>'กฎหมายและมาตรฐานไทย','C'=>'กระบวนการ EPC','D'=>'ความปลอดภัย'],
        'mcPts'  => 4, 'tfPts' => 2, 'passPct' => 0.90, 'timeMins' => 60,
    ];
}

function examLoadBank() {
    $file = __DIR__ . '/question_bank.json';
    if (!file_exists($file)) err('question_bank.json not found on server.', 500);
    $bank = json_decode(file_get_contents($file), true);
    if (!$bank) err('question_bank.json is not valid JSON.', 500);
    return $bank;
}

function examRequireStaff() {
    if (empty($_SESSION['exam_staff'])) err('กรุณาเข้าสู่ระบบก่อน', 401);
    return $_SESSION['exam_staff'];
}

function examRequireAdmin() {
    $s = examRequireStaff();
    if (empty($s['isAdmin'])) err('เฉพาะ Admin เท่านั้น', 403);
    return $s;
}
// ──────────────────────────────────────────────────────────────────────

function fetchProductWithTypes($pdo, $id) {
    $s = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $s->execute([$id]);
    $p = $s->fetch();
    if ($p) {
        $p['stock'] = (int)$p['stock'];
        $p['reserved'] = (int)$p['reserved'];
        $p['min_level'] = (int)$p['min_level'];
        $p['minLevel'] = $p['min_level'];
        $p['cost'] = floatCast($p['cost']);
        $p['price'] = floatCast($p['price']);
        $p['warehouseId'] = $p['warehouse_id'];
    }
    return $p;
}

// ============================================================
// ROUTING
// ============================================================
$action = trim($_GET['action'] ?? '');

switch ($action) {

// ============================================================
// FIRST-RUN SETUP
// ============================================================
case 'check_setup':
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    ok(['needs_setup' => $count === 0]);
    break;

case 'setup_admin':
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) err('Setup already completed. Use your existing login.');
    $b = body();
    $name     = trim($b['name'] ?? '');
    $username = trim($b['username'] ?? '');
    $password = $b['password'] ?? '';
    if (!$name || !$username || !$password) err('Name, username and password are all required.');
    if (strlen($password) < 8) err('Password must be at least 8 characters.');
    $pdo->prepare('INSERT INTO users (id, name, username, password_hash, role) VALUES (?,?,?,?,?)')
        ->execute(['superadmin', $name, strtolower($username), password_hash($password, PASSWORD_BCRYPT), 'Super User']);
    logActivity($pdo, "YarraPower Stock Management initialised. Super Admin: $username", 'green');
    ok(['message' => 'Admin account created. Please log in.']);
    break;

// ============================================================
// AUTHENTICATION
// ============================================================
case 'login':
    $b = body();
    $username = strtolower(trim($b['username'] ?? ''));
    $password = $b['password'] ?? '';
    if (!$username || !$password) err('Username and password are required.');
    $s = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
    $s->execute([$username]);
    $user = $s->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        usleep(400000); // slow down brute-force attempts
        err('Incorrect username or password.');
    }
    session_regenerate_id(true); // prevent session fixation
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];
    ok(['user' => ['id' => $user['id'], 'name' => $user['name'], 'username' => $user['username'], 'role' => $user['role']]]);
    break;

case 'logout':
    session_destroy();
    ok();
    break;

case 'guest_login':
    $_SESSION['user_id']   = 'guest';
    $_SESSION['user_role'] = 'View Only';
    ok(['user' => ['id' => 'guest', 'name' => 'Guest', 'username' => 'guest', 'role' => 'View Only']]);
    break;

case 'check_session':
    if (!empty($_SESSION['user_id'])) {
        $u = currentUser($pdo);
        if ($u) ok(['logged_in' => true, 'user' => $u]);
        else { session_destroy(); ok(['logged_in' => false]); }
    } else {
        ok(['logged_in' => false]);
    }
    break;

// ============================================================
// INIT — load all data for the frontend
// ============================================================
case 'init':
    requireAuth();
    ybMigrate($pdo);

    // Products
    $products = $pdo->query('SELECT * FROM products ORDER BY created_at')->fetchAll();
    foreach ($products as &$p) {
        $p['stock']       = (int)$p['stock'];
        $p['reserved']    = (int)$p['reserved'];
        $p['min_level']   = (int)$p['min_level'];
        $p['minLevel']    = $p['min_level'];
        $p['cost']        = floatCast($p['cost']);
        $p['price']       = floatCast($p['price']);
        $p['warehouseId'] = $p['warehouse_id'];
    }

    // Warehouses
    $warehouses = $pdo->query('SELECT * FROM warehouses ORDER BY created_at')->fetchAll();

    // Suppliers
    $suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
    foreach ($suppliers as &$s) { $s['leadTime'] = (int)$s['lead_time']; }

    // Purchase Orders + lines
    $pos = $pdo->query('SELECT * FROM purchase_orders ORDER BY created_at DESC')->fetchAll();
    $poLines = $pdo->query('SELECT * FROM po_lines')->fetchAll();
    $poMap = [];
    foreach ($poLines as $l) {
        $l['qty']      = (int)$l['qty'];
        $l['cost']     = floatCast($l['cost']);
        $l['subtotal'] = floatCast($l['subtotal']);
        $l['productId']   = $l['product_id'];
        $l['productName'] = $l['product_name'];
        $poMap[$l['po_id']][] = $l;
    }
    foreach ($pos as &$po) {
        $po['total']         = floatCast($po['total']);
        $po['lines']         = $poMap[$po['id']] ?? [];
        $po['supplierId']    = $po['supplier_id'];
        $po['supplierName']  = $po['supplier_name'];
        $po['orderDate']     = $po['order_date'];
        $po['expectedDate']  = $po['expected_date'];
        $po['createdBy']     = $po['created_by'];
    }

    // Sales Orders + lines
    $sos = $pdo->query('SELECT * FROM sales_orders ORDER BY created_at DESC')->fetchAll();
    $soLines = $pdo->query('SELECT * FROM so_lines')->fetchAll();
    $soMap = [];
    foreach ($soLines as $l) {
        $l['qty']         = (int)$l['qty'];
        $l['price']       = floatCast($l['price']);
        $l['subtotal']    = floatCast($l['subtotal']);
        $l['productId']   = $l['product_id'];
        $l['productName'] = $l['product_name'];
        $soMap[$l['so_id']][] = $l;
    }
    foreach ($sos as &$so) {
        $so['total']         = floatCast($so['total']);
        $so['lines']         = $soMap[$so['id']] ?? [];
        $so['warehouseId']   = $so['warehouse_id'];
        $so['warehouseName'] = $so['warehouse_name'];
        $so['dispatchDate']  = $so['dispatch_date'];
    }

    // Bookings + lines + acc_lines + yb_meta
    $bookings   = $pdo->query('SELECT * FROM bookings ORDER BY created_at DESC')->fetchAll();
    $bkLines    = $pdo->query('SELECT * FROM booking_lines')->fetchAll();
    $bkAccLines = $pdo->query('SELECT * FROM booking_acc_lines')->fetchAll();
    $bkMeta     = $pdo->query('SELECT * FROM yb_booking_meta')->fetchAll();
    $bkMap = []; $bkAccMap = []; $bkMetaMap = [];
    foreach ($bkLines as $l) {
        $l['qty']         = (int)$l['qty'];
        $l['price']       = floatCast($l['price']);
        $l['subtotal']    = floatCast($l['subtotal']);
        $l['productId']   = $l['product_id'];
        $l['productName'] = $l['product_name'];
        $bkMap[$l['booking_id']][] = $l;
    }
    foreach ($bkAccLines as $a) {
        $a['qty'] = (int)$a['qty'];
        $bkAccMap[$a['booking_id']][] = ['accId'=>$a['acc_id'],'name'=>$a['acc_name'],'sheet'=>$a['sheet'],'qty'=>$a['qty'],'unit'=>$a['unit']];
    }
    foreach ($bkMeta as $m) { $bkMetaMap[$m['booking_id']] = $m; }
    foreach ($bookings as &$bk) {
        $bk['total']         = floatCast($bk['total']);
        $bk['lines']         = $bkMap[$bk['id']] ?? [];
        $bk['accLines']      = $bkAccMap[$bk['id']] ?? [];
        $bk['warehouseId']   = $bk['warehouse_id'];
        $bk['warehouseName'] = $bk['warehouse_name'];
        $bk['createdBy']     = $bk['created_by'];
        $bk['linkedSO']      = $bk['linked_so'];
        $meta = $bkMetaMap[$bk['id']] ?? null;
        $bk['ybStatus']      = $meta ? $meta['yb_status'] : null; // null = no YB entry
        $bk['ybCreatedBy']   = $meta ? $meta['created_by'] : '';
        $bk['soNumber']      = $meta ? $meta['so_number'] : '';
        $bk['claim']         = $meta && $meta['claim_json'] ? json_decode($meta['claim_json'], true) : null;
        $bk['returnLines']   = $meta && $meta['return_lines_json'] ? json_decode($meta['return_lines_json'], true) : [];
        $bk['returnNote']    = $meta ? ($meta['return_note'] ?? '') : '';
    }

    // YarraBoard PR Reports (items stored as JSON in items_json column)
    $prReports = $pdo->query('SELECT * FROM yb_pr_reports ORDER BY created_at DESC')->fetchAll();
    foreach ($prReports as &$pr) {
        $pr['total']      = floatCast($pr['total']);
        $pr['totalCost']  = $pr['total_cost'] !== null ? floatCast($pr['total_cost']) : null;
        $pr['prStatus']   = $pr['pr_status'];
        $pr['prNum']      = $pr['pr_num'];
        $pr['bkNumber']   = $pr['bk_number'];
        $pr['dateNeeded'] = $pr['date_needed'];
        $pr['createdBy']  = $pr['created_by'];
        $pr['items']      = $pr['items_json'] ? json_decode($pr['items_json'], true) : [];
        // Remove raw DB fields
        unset($pr['pr_status'], $pr['pr_num'], $pr['bk_number'], $pr['date_needed'],
              $pr['created_by'], $pr['total_cost'], $pr['items_json']);
    }

    // Activity
    $activity = $pdo->query(
        'SELECT description AS `desc`, type,
         DATE_FORMAT(created_at, "%d %b, %H:%i") AS `time`
         FROM activity_log ORDER BY id DESC LIMIT 20'
    )->fetchAll();

    // Settings
    $settingsRaw = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
    $settings = [];
    foreach ($settingsRaw as $row) $settings[$row['setting_key']] = $row['setting_value'];
    if (!isset($settings['units'])) {
        $unitsRaw = $pdo->query('SELECT unit FROM app_units ORDER BY sort_order')->fetchAll();
        $settings['units'] = array_column($unitsRaw, 'unit');
    }

    // Counters
    $countersRaw = $pdo->query('SELECT name, value FROM app_counters')->fetchAll();
    $counters = [];
    foreach ($countersRaw as $c) $counters[$c['name']] = (int)$c['value'];

    // Users (only returned for Super Users; empty array for all other roles)
    $currentRole = $_SESSION['user_id'] === 'guest' ? 'View Only' : (currentUser($pdo)['role'] ?? '');
    $users = [];
    if ($currentRole === 'Super User') {
        $users = $pdo->query(
            'SELECT id, name, username, role, created_by,
             DATE_FORMAT(created_at, "%d %b %Y") AS created_at
             FROM users ORDER BY created_at'
        )->fetchAll();
    }

    // Build ybBookings: only bookings that have a yb_booking_meta entry
    $ybBookings = [];
    foreach ($bookings as $bk) {
        if ($bk['ybStatus'] === null) continue; // no YarraBoard entry yet
        $ybEntry = [
            'id'        => $bk['id'],
            'number'    => $bk['number'],
            'customer'  => $bk['customer'],
            'project'   => $bk['project'] ?? '',
            'date'      => $bk['date'] ?? '',
            'warehouse' => $bk['warehouseName'] ?? '',
            'ybStatus'  => $bk['ybStatus'],
            'createdBy' => $bk['ybCreatedBy'],
            'soNumber'  => $bk['soNumber'],
            'claim'     => $bk['claim'],
            'returnLines'=> $bk['returnLines'],
            'returnNote' => $bk['returnNote'],
            'lines'     => array_map(fn($l) => ['name'=>$l['productName']??$l['product_name']??'','sku'=>$l['sku']??'','qty'=>(int)$l['qty'],'unit'=>$l['unit']??'pcs','price'=>floatCast($l['price']??0)], $bk['lines']),
            'accLines'  => $bk['accLines'],
        ];
        $ybBookings[] = $ybEntry;
    }

    ok([
        'products'       => array_values($products),
        'warehouses'     => array_values($warehouses),
        'suppliers'      => array_values($suppliers),
        'purchaseOrders' => array_values($pos),
        'salesOrders'    => array_values($sos),
        'bookings'       => array_values($bookings),
        'ybBookings'     => $ybBookings,
        'ybPrReports'    => array_values($prReports),
        'activity'       => array_values($activity),
        'settings'       => $settings,
        'counters'       => $counters,
        'users'          => array_values($users),
    ]);
    break;

// ============================================================
// PRODUCTS
// ============================================================
case 'save_product':
    requireRole($pdo, ['Stock Manager','Director','Super User']);
    $b  = body();
    $id = $b['id'] ?? null;
    $isNew = !$id;
    if ($isNew) $id = uid();

    $sku       = trim($b['sku'] ?? '');
    $name      = trim($b['name'] ?? '');
    $whId      = $b['warehouseId'] ?? $b['warehouse_id'] ?? null;
    $minLevel  = intCast($b['minLevel'] ?? $b['min_level'] ?? 0);

    if (!$sku || !$name) err('SKU and name are required.');

    if ($isNew) {
        $pdo->prepare('INSERT INTO products
            (id,sku,name,category,brand,model,unit,stock,reserved,min_level,cost,price,supplier,warehouse_id,location,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $id, $sku, $name,
                $b['category'] ?? '', $b['brand'] ?? '', $b['model'] ?? '',
                $b['unit'] ?? 'pcs',
                intCast($b['stock'] ?? 0), intCast($b['reserved'] ?? 0), $minLevel,
                floatCast($b['cost'] ?? 0), floatCast($b['price'] ?? 0),
                $b['supplier'] ?? '', $whId ?: null,
                $b['location'] ?? '', $b['notes'] ?? ''
            ]);
        logActivity($pdo, "Added product: $name ({$sku})", 'green');
    } else {
        $pdo->prepare('UPDATE products SET
            sku=?,name=?,category=?,brand=?,model=?,unit=?,stock=?,reserved=?,min_level=?,
            cost=?,price=?,supplier=?,warehouse_id=?,location=?,notes=?
            WHERE id=?')
            ->execute([
                $sku, $name,
                $b['category'] ?? '', $b['brand'] ?? '', $b['model'] ?? '',
                $b['unit'] ?? 'pcs',
                intCast($b['stock'] ?? 0), intCast($b['reserved'] ?? 0), $minLevel,
                floatCast($b['cost'] ?? 0), floatCast($b['price'] ?? 0),
                $b['supplier'] ?? '', $whId ?: null,
                $b['location'] ?? '', $b['notes'] ?? '',
                $id
            ]);
        logActivity($pdo, "Updated product: $name", 'blue');
    }
    ok(['product' => fetchProductWithTypes($pdo, $id)]);
    break;

case 'delete_product':
    requireRole($pdo, ['Stock Manager','Director','Super User']);
    $b  = body();
    $id = $b['id'] ?? '';
    if (!$id) err('Product ID required.');
    $s = $pdo->prepare('SELECT name FROM products WHERE id = ?'); $s->execute([$id]);
    $p = $s->fetch();
    if (!$p) err('Product not found.');
    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    logActivity($pdo, "Deleted product: {$p['name']}", 'red');
    ok();
    break;

// ============================================================
// WAREHOUSES
// ============================================================
case 'save_warehouse':
    requireRole($pdo, ['Stock Manager','Director','Super User']);
    $b    = body();
    $id   = $b['id'] ?? null;
    $name = trim($b['name'] ?? '');
    $addr = trim($b['address'] ?? '');
    if (!$name) err('Warehouse name is required.');

    if (!$id) {
        // Max 3 warehouses
        $count = (int)$pdo->query('SELECT COUNT(*) FROM warehouses')->fetchColumn();
        if ($count >= 3) err('Maximum 3 warehouses supported.');
        $id = uid();
        $pdo->prepare('INSERT INTO warehouses (id,name,address) VALUES (?,?,?)')->execute([$id, $name, $addr]);
        logActivity($pdo, "Added warehouse: $name", 'green');
    } else {
        $pdo->prepare('UPDATE warehouses SET name=?, address=? WHERE id=?')->execute([$name, $addr, $id]);
        logActivity($pdo, "Updated warehouse: $name", 'blue');
    }
    $s = $pdo->prepare('SELECT * FROM warehouses WHERE id = ?'); $s->execute([$id]);
    ok(['warehouse' => $s->fetch()]);
    break;

case 'delete_warehouse':
    requireRole($pdo, ['Stock Manager','Director','Super User']);
    $b  = body();
    $id = $b['id'] ?? '';
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE warehouse_id = ?'); $cnt->execute([$id]);
    if ((int)$cnt->fetchColumn() > 0) err('Cannot delete: warehouse still has products assigned.');
    $pdo->prepare('DELETE FROM warehouses WHERE id = ?')->execute([$id]);
    ok();
    break;

// ============================================================
// SUPPLIERS
// ============================================================
case 'save_supplier':
    requireRole($pdo, ['Stock Manager','Director','Super User']);
    $b    = body();
    $id   = $b['id'] ?? null;
    $name = trim($b['name'] ?? '');
    if (!$name) err('Supplier name is required.');

    $vals = [$name, $b['contact']??'', $b['email']??'', $b['phone']??'', $b['country']??'', intCast($b['leadTime']??14), $b['notes']??''];
    if (!$id) {
        $id = uid();
        $pdo->prepare('INSERT INTO suppliers (id,name,contact,email,phone,country,lead_time,notes) VALUES (?,?,?,?,?,?,?,?)')
            ->execute(array_merge([$id], $vals));
        logActivity($pdo, "Added supplier: $name", 'green');
    } else {
        $pdo->prepare('UPDATE suppliers SET name=?,contact=?,email=?,phone=?,country=?,lead_time=?,notes=? WHERE id=?')
            ->execute(array_merge($vals, [$id]));
        logActivity($pdo, "Updated supplier: $name", 'blue');
    }
    $s = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?'); $s->execute([$id]);
    $sup = $s->fetch();
    $sup['leadTime'] = (int)$sup['lead_time'];
    ok(['supplier' => $sup]);
    break;

case 'delete_supplier':
    requireRole($pdo, ['Stock Manager','Director','Super User']);
    $b = body();
    $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$b['id'] ?? '']);
    ok();
    break;

// ============================================================
// PURCHASE ORDERS
// ============================================================
case 'save_po':
    requireRole($pdo, ['Stock Manager','Director','Super User']);
    $b    = body();
    $user = currentUser($pdo);
    $id   = uid();
    $num  = 'PO-' . nextCounter($pdo, 'po');

    $pdo->prepare('INSERT INTO purchase_orders
        (id,number,supplier_id,supplier_name,status,order_date,expected_date,notes,total,created_by,project_id,project_name)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $id, $num,
            $b['supplierId']   ?? $b['supplier_id']   ?? null,
            $b['supplierName'] ?? $b['supplier_name'] ?? '',
            $b['status'] ?? 'Draft',
            $b['orderDate']    ?? $b['order_date']    ?: null,
            $b['expectedDate'] ?? $b['expected_date'] ?: null,
            $b['notes'] ?? '',
            floatCast($b['total'] ?? 0),
            $user['username'],
            $b['projectId']   ?? $b['project_id']   ?? null,
            $b['projectName'] ?? $b['project_name'] ?? null,
        ]);

    foreach (($b['lines'] ?? []) as $line) {
        $pdo->prepare('INSERT INTO po_lines (id,po_id,product_id,product_name,sku,qty,cost,subtotal) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([uid(), $id,
                $line['productId'] ?? $line['product_id'] ?? null,
                $line['productName'] ?? $line['product_name'] ?? '',
                $line['sku']??'',
                intCast($line['qty']??0), floatCast($line['cost']??0), floatCast($line['subtotal']??0)]);
    }

    logActivity($pdo, "Created PO $num — " . ($b['supplierName'] ?? $b['supplier_name'] ?? 'Unknown Supplier'), 'orange');
    ok(['id' => $id, 'number' => $num]);
    break;

case 'update_po_status':
    requireRole($pdo, ['Stock Manager','Director','Super User']);
    $b  = body();
    $id = $b['id'] ?? '';
    $status = $b['status'] ?? '';
    if (!in_array($status, ['Draft','Ordered','Received','Cancelled'])) err('Invalid status.');

    $s = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?'); $s->execute([$id]);
    $po = $s->fetch();
    if (!$po) err('Purchase order not found.');

    // Determine final status (partial receive possible)
    $receiveLines = $b['receive_lines'] ?? [];
    $finalStatus  = $status;

    if ($status === 'Received' && $po['status'] !== 'Received') {
        if (!empty($receiveLines)) {
            // Selective receive: update specific products with given quantities
            foreach ($receiveLines as $rl) {
                $prodId = $rl['product_id'] ?? null;
                $qty    = (int)($rl['qty'] ?? 0);
                $whId   = $rl['warehouse_id'] ?? null;
                if (!$prodId || $qty <= 0) continue;
                $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$qty, $prodId]);
                if ($whId) {
                    // Assign warehouse to product if not yet set
                    $pdo->prepare('UPDATE products SET warehouse_id = ? WHERE id = ? AND (warehouse_id IS NULL OR warehouse_id = "")')->execute([$whId, $prodId]);
                }
            }
            // Check if all lines fully received → 'Received', else 'Partial'
            $allLines = $pdo->prepare('SELECT * FROM po_lines WHERE po_id = ?'); $allLines->execute([$id]);
            $lineRows = $allLines->fetchAll();
            $totalOrdered = array_sum(array_column($lineRows, 'qty'));
            $totalInRl    = array_sum(array_column($receiveLines, 'qty'));
            $finalStatus  = ($totalInRl >= $totalOrdered) ? 'Received' : 'Partial';
        } else {
            // Full receive: add all PO line quantities
            $lines = $pdo->prepare('SELECT * FROM po_lines WHERE po_id = ?'); $lines->execute([$id]);
            foreach ($lines->fetchAll() as $l) {
                if ($l['product_id']) {
                    $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([(int)$l['qty'], $l['product_id']]);
                }
            }
        }
        $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=?')->execute([$finalStatus, $id]);
        logActivity($pdo, "PO {$po['number']} $finalStatus — stock levels updated", 'green');
    } else {
        $pdo->prepare('UPDATE purchase_orders SET status=? WHERE id=?')->execute([$status, $id]);
        if ($status === 'Cancelled') {
            logActivity($pdo, "PO {$po['number']} cancelled", 'red');
        } else {
            logActivity($pdo, "PO {$po['number']} status → $status", 'blue');
        }
    }
    ok();
    break;

// ============================================================
// SALES ORDERS
// ============================================================
case 'save_so':
    requireRole($pdo, ['Director','Super User']);
    $b    = body();
    $id   = uid();
    $num  = 'SO-' . nextCounter($pdo, 'so');

    $pdo->prepare('INSERT INTO sales_orders
        (id,number,customer,project,date,dispatch_date,address,notes,warehouse_id,warehouse_name,status,total)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $id, $num,
            $b['customer']??'', $b['project']??'',
            $b['date'] ?: null, $b['dispatchDate'] ?: null,
            $b['address']??'', $b['notes']??'',
            $b['warehouseId']??null, $b['warehouseName']??'',
            'Allocated', floatCast($b['total']??0)
        ]);

    foreach (($b['lines'] ?? []) as $line) {
        $pdo->prepare('INSERT INTO so_lines (id,so_id,product_id,product_name,sku,qty,price,subtotal) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([uid(), $id, $line['productId']??null, $line['productName']??'', $line['sku']??'',
                intCast($line['qty']??0), floatCast($line['price']??0), floatCast($line['subtotal']??0)]);
        if ($line['productId'] ?? false) {
            $pdo->prepare('UPDATE products SET reserved = reserved + ? WHERE id = ?')->execute([(int)$line['qty'], $line['productId']]);
        }
    }

    logActivity($pdo, "Created SO $num for " . ($b['customer']??''), 'green');
    ok(['id' => $id, 'number' => $num]);
    break;

case 'update_so_status':
    requireRole($pdo, ['Director','Super User']);
    $b  = body();
    $id = $b['id'] ?? '';
    $status = $b['status'] ?? '';
    if (!in_array($status, ['Allocated','Dispatched','Cancelled'])) err('Invalid status.');

    $s = $pdo->prepare('SELECT * FROM sales_orders WHERE id = ?'); $s->execute([$id]);
    $so = $s->fetch();
    if (!$so) err('Sales order not found.');

    $pdo->prepare('UPDATE sales_orders SET status=? WHERE id=?')->execute([$status, $id]);

    $lines = $pdo->prepare('SELECT * FROM so_lines WHERE so_id = ?'); $lines->execute([$id]);
    $lineData = $lines->fetchAll();

    if ($status === 'Dispatched' && $so['status'] !== 'Dispatched') {
        foreach ($lineData as $l) {
            if ($l['product_id']) {
                $pdo->prepare('UPDATE products SET stock = GREATEST(0, stock-?), reserved = GREATEST(0, reserved-?) WHERE id=?')
                    ->execute([(int)$l['qty'], (int)$l['qty'], $l['product_id']]);
            }
        }
        logActivity($pdo, "SO {$so['number']} dispatched — stock deducted", 'green');
    } elseif ($status === 'Cancelled' && $so['status'] !== 'Cancelled') {
        foreach ($lineData as $l) {
            if ($l['product_id']) {
                $pdo->prepare('UPDATE products SET reserved = GREATEST(0, reserved-?) WHERE id=?')->execute([(int)$l['qty'], $l['product_id']]);
            }
        }
        logActivity($pdo, "SO {$so['number']} cancelled — reserved stock returned", 'red');
    }
    ok();
    break;

// ============================================================
// BOOKINGS
// ============================================================
case 'save_booking':
    requireRole($pdo, ['Project Manager','Director','Super User']);
    $b    = body();
    $user = currentUser($pdo);
    $id   = uid();
    $num  = 'BK-' . nextCounter($pdo, 'bk');

    $pdo->prepare('INSERT INTO bookings
        (id,number,customer,project,date,expiry,notes,warehouse_id,warehouse_name,status,total,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $id, $num,
            $b['customer']??'', $b['project']??'',
            $b['date'] ?: null, ($b['expiry'] ?: null),
            $b['notes']??'',
            $b['warehouseId']??null, $b['warehouseName']??'',
            'Active', floatCast($b['total']??0),
            $user['username']
        ]);

    foreach (($b['lines'] ?? []) as $line) {
        $pdo->prepare('INSERT INTO booking_lines (id,booking_id,product_id,product_name,sku,qty,price,subtotal) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([uid(), $id, $line['productId']??null, $line['productName']??'', $line['sku']??'',
                intCast($line['qty']??0), floatCast($line['price']??0), floatCast($line['subtotal']??0)]);
        if ($line['productId'] ?? false) {
            $pdo->prepare('UPDATE products SET reserved = reserved + ? WHERE id = ?')->execute([(int)$line['qty'], $line['productId']]);
        }
    }

    // Save accessory lines
    foreach (($b['accLines'] ?? []) as $a) {
        $pdo->prepare('INSERT INTO booking_acc_lines (id,booking_id,acc_id,acc_name,sheet,qty,unit) VALUES (?,?,?,?,?,?,?)')
            ->execute([uid(), $id, $a['accId']??null, $a['name']??'', $a['sheet']??'', intCast($a['qty']??0), $a['unit']??'pcs']);
    }

    // Create YarraBoard meta entry
    $prItemsJson = null;
    if (!empty($b['prItems'])) {
        $prItemsJson = json_encode(['items'=>$b['prItems'], 'urgency'=>$b['prUrgency']??'Normal', 'dateNeeded'=>$b['prDateNeeded']??'']);
    }
    $pdo->prepare('INSERT INTO yb_booking_meta (booking_id,yb_status,created_by,pr_items_json) VALUES (?,?,?,?)')
        ->execute([$id, 'Request', $user['name']??$user['username']??'Unknown', $prItemsJson]);

    logActivity($pdo, "Created Booking $num for " . ($b['customer']??'') . " — ฿" . number_format(floatCast($b['total']??0), 2), 'orange');
    ok(['id' => $id, 'number' => $num]);
    break;

case 'cancel_booking':
    requireAuth();
    $b  = body();
    $id = $b['id'] ?? '';
    $u  = currentUser($pdo);
    $s  = $pdo->prepare('SELECT * FROM bookings WHERE id = ?'); $s->execute([$id]);
    $bk = $s->fetch();
    if (!$bk) err('Booking not found.');
    $isOwner = ($bk['created_by'] === $u['username']);
    if (!in_array($u['role'], ['Director','Super User']) && !$isOwner) err('You can only cancel your own bookings.');

    if ($bk['status'] === 'Active') {
        $lines = $pdo->prepare('SELECT * FROM booking_lines WHERE booking_id = ?'); $lines->execute([$id]);
        foreach ($lines->fetchAll() as $l) {
            if ($l['product_id']) $pdo->prepare('UPDATE products SET reserved = GREATEST(0, reserved-?) WHERE id=?')->execute([(int)$l['qty'], $l['product_id']]);
        }
    }
    $pdo->prepare("UPDATE bookings SET status='Cancelled' WHERE id=?")->execute([$id]);
    logActivity($pdo, "Cancelled Booking {$bk['number']} — reserved stock returned", 'red');
    ok();
    break;

case 'confirm_booking':
    requireAuth();
    $b  = body();
    $id = $b['id'] ?? '';
    $u  = currentUser($pdo);
    $s  = $pdo->prepare('SELECT * FROM bookings WHERE id = ?'); $s->execute([$id]);
    $bk = $s->fetch();
    if (!$bk) err('Booking not found.');
    $isOwner = ($bk['created_by'] === $u['username']);
    if (!in_array($u['role'], ['Director','Super User']) && !($isOwner && $u['role'] === 'Project Manager')) err('Access denied.');

    $soId  = uid();
    $soNum = 'SO-' . nextCounter($pdo, 'so');
    $soNotes = $bk['notes'] ? "Converted from {$bk['number']}. {$bk['notes']}" : "Converted from {$bk['number']}";

    $pdo->prepare('INSERT INTO sales_orders
        (id,number,customer,project,date,dispatch_date,address,notes,warehouse_id,warehouse_name,status,total)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$soId, $soNum, $bk['customer'], $bk['project']??'', date('Y-m-d'),
            $bk['expiry']??null, '', $soNotes, $bk['warehouse_id'], $bk['warehouse_name'], 'Allocated', $bk['total']]);

    $lines = $pdo->prepare('SELECT * FROM booking_lines WHERE booking_id = ?'); $lines->execute([$id]);
    foreach ($lines->fetchAll() as $l) {
        $pdo->prepare('INSERT INTO so_lines (id,so_id,product_id,product_name,sku,qty,price,subtotal) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([uid(), $soId, $l['product_id'], $l['product_name'], $l['sku'], $l['qty'], $l['price'], $l['subtotal']]);
    }

    $pdo->prepare("UPDATE bookings SET status='Confirmed', linked_so=? WHERE id=?")->execute([$soNum, $id]);

    // Update YarraBoard meta → Confirmed + so_number
    $pdo->prepare("INSERT INTO yb_booking_meta (booking_id,yb_status,so_number,created_by)
        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE yb_status='Confirmed', so_number=?")
        ->execute([$id, 'Confirmed', $soNum, $u['name']??$u['username']??'Unknown', $soNum]);

    // Auto-create PR report if PR items were stored in meta
    $metaRow = $pdo->prepare('SELECT pr_items_json FROM yb_booking_meta WHERE booking_id = ?');
    $metaRow->execute([$id]);
    $meta = $metaRow->fetch();
    $prCreated = '';
    if ($meta && $meta['pr_items_json']) {
        $prData = json_decode($meta['pr_items_json'], true);
        if (!empty($prData['items'])) {
            $existingPRs = $pdo->prepare('SELECT COUNT(*) AS cnt FROM yb_pr_reports WHERE bk_number = ?');
            $existingPRs->execute([$bk['number']]);
            $prSeq  = (int)$existingPRs->fetch()['cnt'] + 1;
            $prNum  = $bk['number'] . '-PR-' . str_pad($prSeq, 2, '0', STR_PAD_LEFT);
            $prId   = uid();
            $prTotal = array_sum(array_column($prData['items'], 'subtotal'));
            $dateNeeded = $prData['dateNeeded'] ?: null;
            $pdo->prepare('INSERT INTO yb_pr_reports (id,pr_num,bk_number,customer,project,date,date_needed,urgency,total,pr_status,created_by,notes,items_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$prId, $prNum, $bk['number'], $bk['customer'], $bk['project']??'',
                    date('Y-m-d'), $dateNeeded, $prData['urgency']??'Normal', $prTotal,
                    'Request', $u['name']??$u['username']??'Unknown',
                    "Auto-generated from booking {$bk['number']} confirmation.",
                    json_encode($prData['items'])]);
            // Clear pr_items_json so it's not re-created on next confirm
            $pdo->prepare('UPDATE yb_booking_meta SET pr_items_json=NULL WHERE booking_id=?')->execute([$id]);
            $prCreated = $prNum;
        }
    }

    logActivity($pdo, "Confirmed Booking {$bk['number']} → created $soNum for {$bk['customer']}", 'green');
    ok(['soNumber' => $soNum, 'soId' => $soId, 'prCreated' => $prCreated]);
    break;

case 'expire_booking':
    requireAuth();
    $b  = body();
    $id = $b['id'] ?? '';
    $s  = $pdo->prepare('SELECT * FROM bookings WHERE id = ?'); $s->execute([$id]);
    $bk = $s->fetch();
    if (!$bk || $bk['status'] !== 'Active') err('Booking not found or not active.');
    $lines = $pdo->prepare('SELECT * FROM booking_lines WHERE booking_id = ?'); $lines->execute([$id]);
    foreach ($lines->fetchAll() as $l) {
        if ($l['product_id']) $pdo->prepare('UPDATE products SET reserved = GREATEST(0, reserved-?) WHERE id=?')->execute([(int)$l['qty'], $l['product_id']]);
    }
    $pdo->prepare("UPDATE bookings SET status='Expired' WHERE id=?")->execute([$id]);
    logActivity($pdo, "Booking {$bk['number']} expired — reserved stock returned", 'red');
    ok();
    break;

// ============================================================
// USER MANAGEMENT (Super User only)
// ============================================================
case 'get_users':
    requireRole($pdo, ['Super User']);
    $users = $pdo->query('SELECT id,name,username,role,created_by,DATE_FORMAT(created_at,"%d %b %Y") AS created_at FROM users ORDER BY created_at')->fetchAll();
    ok(['users' => $users]);
    break;

case 'save_user':
    requireRole($pdo, ['Super User']);
    $b        = body();
    $id       = $b['id'] ?? null;
    $name     = trim($b['name'] ?? '');
    $username = strtolower(trim($b['username'] ?? ''));
    $role     = $b['role'] ?? 'View Only';
    $password = $b['password'] ?? '';
    $caller   = currentUser($pdo);

    if (!$name || !$username) err('Name and username are required.');
    if (!in_array($role, ['View Only','Project Manager','Stock Manager','Director','Super User'])) err('Invalid role.');

    if (!$id) {
        if (!$password || strlen($password) < 6) err('Password required (min 6 characters) for new users.');
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?'); $check->execute([$username]);
        if ($check->fetch()) err('Username already taken. Choose another.');
        $id = uid();
        $pdo->prepare('INSERT INTO users (id,name,username,password_hash,role,created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $name, $username, password_hash($password, PASSWORD_BCRYPT), $role, $caller['username']]);
        logActivity($pdo, "User added: $username ($role)", 'green');
    } else {
        if ($id === 'superadmin' && $caller['id'] !== 'superadmin') err('Cannot modify the original Super Admin.');
        $sql    = 'UPDATE users SET name=?,username=?,role=?';
        $params = [$name, $username, $role];
        if ($password) {
            if (strlen($password) < 6) err('Password min 6 characters.');
            $sql    .= ',password_hash=?';
            $params[] = password_hash($password, PASSWORD_BCRYPT);
        }
        $params[] = $id;
        $pdo->prepare("$sql WHERE id=?")->execute($params);
        logActivity($pdo, "User updated: $username ($role)", 'blue');
    }
    ok(['id' => $id]);
    break;

case 'delete_user':
    requireRole($pdo, ['Super User']);
    $b  = body();
    $id = $b['id'] ?? '';
    $me = currentUser($pdo);
    if ($id === 'superadmin') err('Cannot delete the original Super Admin.');
    if ($id === $me['id'])    err('Cannot delete your own account.');
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    ok();
    break;

case 'change_password':
    requireAuth();
    $b   = body();
    $cur = $b['currentPassword'] ?? '';
    $new = $b['newPassword'] ?? '';
    if (strlen($new) < 6) err('New password must be at least 6 characters.');
    $s = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?'); $s->execute([$_SESSION['user_id']]);
    $row = $s->fetch();
    if (!$row || !password_verify($cur, $row['password_hash'])) err('Current password is incorrect.');
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['user_id']]);
    ok(['message' => 'Password changed successfully.']);
    break;

// ============================================================
// SETTINGS
// ============================================================
case 'save_settings':
    requireRole($pdo, ['Director','Super User']);
    $b = body();
    foreach (['company','abn','currency','fy'] as $key) {
        $val = $b[$key] ?? '';
        $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
            ->execute([$key, $val, $val]);
    }
    ok();
    break;

case 'save_units':
    requireRole($pdo, ['Director','Super User']);
    $b     = body();
    $units = $b['units'] ?? [];
    $pdo->exec('DELETE FROM app_units');
    $stmt = $pdo->prepare('INSERT INTO app_units (unit,sort_order) VALUES (?,?)');
    foreach ($units as $i => $u) $stmt->execute([trim($u), $i]);
    ok();
    break;

// ============================================================
// ACTIVITY
// ============================================================
case 'add_activity':
    requireAuth();
    $b = body();
    logActivity($pdo, $b['desc'] ?? '', $b['type'] ?? 'green');
    ok();
    break;

// ============================================================
// PROJECTS
// ============================================================
case 'get_projects':
    requireAuth();
    $stmt = $pdo->query('SELECT * FROM projects ORDER BY created_at DESC');
    echo json_encode(['success' => true, 'projects' => $stmt->fetchAll()]);
    break;

case 'save_project': {
    requireAuth();
    $cu = currentUser($pdo);
    $role = $cu['role'] ?? '';
    $allowed = ['Project Manager', 'Director', 'Super User'];
    if (!in_array($role, $allowed)) err('Access denied. Required role: Project Manager, Director, or Super User.', 403);

    $b    = body();
    $type = $b['type'] ?? 'Residential';
    $id   = $b['id']   ?? null;

    // Generate new ID if creating (prepared statements only — no string interpolation)
    if (!$id) {
        $prefix = ($type === 'Residential') ? 'RES' : 'CI';
        $year   = date('Y');
        $s = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE id LIKE ?');
        $s->execute(["YP-{$prefix}-{$year}-%"]);
        $cnt = (int)$s->fetchColumn();
        $chk = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE id = ?');
        do {
            $cnt++;
            $id = "YP-{$prefix}-{$year}-" . str_pad($cnt, 3, '0', STR_PAD_LEFT);
            $chk->execute([$id]);
        } while ((int)$chk->fetchColumn() > 0);
    }

    // Check if exists (update) or new (insert)
    $exists = (function() use ($pdo, $id) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE id=?');
        $s->execute([$id]);
        return (int)$s->fetchColumn() > 0;
    })();

    // Sanitise nullable numerics
    $nf = fn($v) => ($v !== '' && $v !== null) ? $v : null;

    if ($exists) {
        $pdo->prepare('UPDATE projects SET
            type=?,name=?,customer=?,status=?,phone=?,email=?,contact=?,address=?,
            province=?,postcode=?,size=?,panels=?,panel_model=?,inverter=?,
            grid_conn=?,battery_kwh=?,battery_brand=?,
            install_date=?,value=?,quote_value=?,contract_value=?,pay_terms=?,
            sales=?,notes=?,
            ci_company=?,ci_taxid=?,ci_building=?,ci_grid_auth=?,
            ci_peak_demand=?,ci_roof_area=?,ci_roof_structure=?,ci_contract_type=?,ci_special=?,
            milestones=?,payment_periods=?,traceability=?,updated_at=NOW() WHERE id=?')
            ->execute([
                $b['type']??'Residential', $b['name']??'', $b['customer']??'', $b['status']??'Active',
                $nf($b['phone']??null), $nf($b['email']??null), $nf($b['contact']??null), $b['address']??'',
                $nf($b['province']??null), $nf($b['postcode']??null),
                $nf($b['size']??null), $nf($b['panels']??null), $nf($b['panel_model']??null), $nf($b['inverter']??null),
                $nf($b['grid_conn']??null), $nf($b['battery_kwh']??null), $nf($b['battery_brand']??null),
                ($b['install_date']??'')?:null,
                $nf($b['value']??null), $nf($b['quote_value']??null), $nf($b['contract_value']??null),
                $nf($b['pay_terms']??null), $nf($b['sales']??null), $nf($b['notes']??null),
                $nf($b['ci_company']??null), $nf($b['ci_taxid']??null), $nf($b['ci_building']??null),
                $nf($b['ci_grid_auth']??null), $nf($b['ci_peak_demand']??null), $nf($b['ci_roof_area']??null),
                $nf($b['ci_roof_structure']??null), $nf($b['ci_contract_type']??null), $nf($b['ci_special']??null),
                is_string($b['milestones']??null) ? $b['milestones'] : json_encode($b['milestones']??[]),
                is_string($b['payment_periods']??null) ? $b['payment_periods'] : json_encode($b['payment_periods']??[]),
                is_string($b['traceability']??null) ? $b['traceability'] : json_encode($b['traceability']??[]),
                $id
            ]);
        logActivity($pdo, "Updated project {$id}: " . ($b['name'] ?? ''), 'blue');
    } else {
        $pdo->prepare('INSERT INTO projects
            (id,type,name,customer,status,phone,email,contact,address,
             province,postcode,size,panels,panel_model,inverter,
             grid_conn,battery_kwh,battery_brand,
             install_date,value,quote_value,contract_value,pay_terms,
             sales,notes,
             ci_company,ci_taxid,ci_building,ci_grid_auth,
             ci_peak_demand,ci_roof_area,ci_roof_structure,ci_contract_type,ci_special,
             milestones,payment_periods,traceability,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $id, $b['type']??'Residential', $b['name']??'', $b['customer']??'', $b['status']??'Active',
                $nf($b['phone']??null), $nf($b['email']??null), $nf($b['contact']??null), $b['address']??'',
                $nf($b['province']??null), $nf($b['postcode']??null),
                $nf($b['size']??null), $nf($b['panels']??null), $nf($b['panel_model']??null), $nf($b['inverter']??null),
                $nf($b['grid_conn']??null), $nf($b['battery_kwh']??null), $nf($b['battery_brand']??null),
                ($b['install_date']??'')?:null,
                $nf($b['value']??null), $nf($b['quote_value']??null), $nf($b['contract_value']??null),
                $nf($b['pay_terms']??null), $nf($b['sales']??null), $nf($b['notes']??null),
                $nf($b['ci_company']??null), $nf($b['ci_taxid']??null), $nf($b['ci_building']??null),
                $nf($b['ci_grid_auth']??null), $nf($b['ci_peak_demand']??null), $nf($b['ci_roof_area']??null),
                $nf($b['ci_roof_structure']??null), $nf($b['ci_contract_type']??null), $nf($b['ci_special']??null),
                is_string($b['milestones']??null) ? $b['milestones'] : json_encode($b['milestones']??[]),
                is_string($b['payment_periods']??null) ? $b['payment_periods'] : json_encode($b['payment_periods']??[]),
                is_string($b['traceability']??null) ? $b['traceability'] : json_encode($b['traceability']??[]),
                $b['created_by'] ?? ($cu['name'] ?? $cu['username'] ?? 'Unknown')
            ]);
        logActivity($pdo, "Created project {$id}: " . ($b['name'] ?? ''), 'green');
    }
    echo json_encode(['success' => true, 'id' => $id]);
    break;
}

case 'delete_project': {
    requireAuth();
    $cu = currentUser($pdo);
    $role = $cu['role'] ?? '';
    $allowed = ['Super User'];
    if (!in_array($role, $allowed)) err('Only Super User can delete projects.', 403);
    $id = body()['id'] ?? '';
    if (!$id) err('Project ID required');
    $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
    logActivity($pdo, "Deleted project {$id}", 'red');
    ok();
    break;
}

// ============================================================
// YARRABOARD API
// ============================================================

case 'update_yb_booking':
    requireAuth();
    $b  = body();
    $id = $b['id'] ?? '';
    if (!$id) err('Booking ID required.');
    $fields = [];
    $params = [];
    if (isset($b['ybStatus']))    { $fields[] = 'yb_status=?';           $params[] = $b['ybStatus']; }
    if (isset($b['soNumber']))    { $fields[] = 'so_number=?';           $params[] = $b['soNumber']; }
    if (isset($b['claim']))       { $fields[] = 'claim_json=?';          $params[] = json_encode($b['claim']); }
    if (array_key_exists('returnLines', $b)) { $fields[] = 'return_lines_json=?'; $params[] = json_encode($b['returnLines']); }
    if (array_key_exists('returnNote',  $b)) { $fields[] = 'return_note=?';       $params[] = $b['returnNote']; }
    if (empty($fields)) err('Nothing to update.');
    $pdo->prepare("INSERT INTO yb_booking_meta (booking_id,yb_status,created_by) VALUES (?,'Request','System')
        ON DUPLICATE KEY UPDATE " . implode(',', $fields))
        ->execute(array_merge([$id], $params));
    ok();
    break;

case 'delete_yb_booking':
    requireRole($pdo, ['Super User']);
    $id = (body()['id'] ?? '');
    if (!$id) err('Booking ID required.');
    $pdo->prepare('DELETE FROM yb_booking_meta WHERE booking_id=?')->execute([$id]);
    ok();
    break;

case 'save_yb_pr':
    requireAuth();
    $b    = body();
    $u    = currentUser($pdo);
    $prId = $b['id'] ?? uid();
    $existing = $pdo->prepare('SELECT id FROM yb_pr_reports WHERE id=?');
    $existing->execute([$prId]);
    $items      = $b['items'] ?? [];
    $itemsJson  = json_encode($items);
    $prTotal    = array_sum(array_column($items, 'subtotal'));
    if ($existing->fetch()) {
        $pdo->prepare('UPDATE yb_pr_reports SET pr_status=?,urgency=?,date_needed=?,notes=?,items_json=? WHERE id=?')
            ->execute([$b['prStatus']??'Request', $b['urgency']??'Normal',
                ($b['dateNeeded']??'')?:null, $b['notes']??'', $itemsJson, $prId]);
    } else {
        $pdo->prepare('INSERT INTO yb_pr_reports (id,pr_num,bk_number,customer,project,date,date_needed,urgency,total,pr_status,created_by,notes,items_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$prId, $b['prNum']??'', $b['bkNumber']??'', $b['customer']??'', $b['project']??'',
                ($b['date']??'')?:date('Y-m-d'), ($b['dateNeeded']??'')?:null,
                $b['urgency']??'Normal', $prTotal, $b['prStatus']??'Request',
                $b['createdBy']??($u['name']??$u['username']??'Unknown'), $b['notes']??'', $itemsJson]);
    }
    ok(['id' => $prId]);
    break;

case 'update_yb_pr_status':
    requireAuth();
    $b = body();
    $sets = ['pr_status=?'];
    $vals = [$b['prStatus']??$b['status']??'Request'];
    if (array_key_exists('totalCost', $b)) { $sets[] = 'total_cost=?'; $vals[] = floatCast($b['totalCost']); }
    if (array_key_exists('items',     $b)) { $sets[] = 'items_json=?'; $vals[] = json_encode($b['items']); }
    $vals[] = $b['id'] ?? '';
    $pdo->prepare('UPDATE yb_pr_reports SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
    ok();
    break;

case 'delete_yb_pr':
    requireRole($pdo, ['Super User']);
    $id = (body()['id'] ?? '');
    if (!$id) err('PR ID required.');
    $pdo->prepare('DELETE FROM yb_pr_reports WHERE id=?')->execute([$id]);
    ok();
    break;

// ============================================================
// SERIAL NUMBER TRACKING
// ============================================================

case 'receive_serials': {
    requireAuth();
    $u    = currentUser($pdo);
    $b    = body();
    $rows = $b['serials'] ?? [];
    if (empty($rows)) err('No serial data provided.');

    // Save shipment header
    $shipId      = uid();
    $arrivalDate = $b['arrival_date'] ?? date('Y-m-d');
    $pdo->prepare('INSERT INTO shipments (id,ref,po_number,supplier,arrival_date,received_by,notes)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute([
            $shipId,
            $b['ref']         ?? null,
            $b['po_number']   ?? null,
            $b['supplier']    ?? null,
            $arrivalDate,
            $u['name'] ?? $u['username'] ?? 'Unknown',
            $b['notes']       ?? null,
        ]);

    // Insert each serial unit
    $inserted = 0; $skipped = [];
    $stmt = $pdo->prepare('INSERT IGNORE INTO serial_units
        (serial_no,product_id,product_type,brand,model,watt_rating,pallet_ref,manufacture_date,
         shipment_id,arrival_date,condition_status,direct_to_site,notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) {
        $sn = trim($r['serial_no'] ?? '');
        if (!$sn) continue;
        // Check duplicate
        $ck = $pdo->prepare('SELECT id FROM serial_units WHERE serial_no=?'); $ck->execute([$sn]);
        if ($ck->fetch()) { $skipped[] = $sn; continue; }
        $palletRef = trim($r['pallet_ref'] ?? '') ?: null;
        $stmt->execute([
            $sn,
            $r['product_id']       ?? null,
            $r['product_type']     ?? 'Panel',
            $r['brand']            ?? null,
            $r['model']            ?? null,
            ($r['watt_rating'] !== '' && $r['watt_rating'] !== null) ? $r['watt_rating'] : null,
            $palletRef,
            ($r['manufacture_date'] ?? '') ?: null,
            $shipId,
            $arrivalDate,
            $r['condition_status'] ?? 'New',
            (int)($b['direct_to_site'] ?? 0),
            $r['notes']            ?? null,
        ]);
        $inserted++;
    }
    logActivity($pdo, "Received shipment {$shipId}: {$inserted} serial units logged", 'green');
    ok(['shipment_id'=>$shipId, 'inserted'=>$inserted, 'skipped'=>$skipped]);
    break;
}

case 'get_serial_inventory': {
    requireAuth();
    $b       = body();
    $type    = $b['product_type'] ?? '';
    $status  = $b['status']       ?? '';
    $search  = $b['search']       ?? '';
    $limit   = min((int)($b['limit'] ?? 200), 500);

    $where = ['1=1']; $params = [];
    if ($type)   { $where[] = 'su.product_type=?'; $params[] = $type; }
    if ($status) { $where[] = 'su.status=?';       $params[] = $status; }
    if ($search) { $where[] = '(su.serial_no LIKE ? OR su.brand LIKE ? OR su.model LIKE ? OR su.project_id LIKE ?)';
                   $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]); }

    $sql = 'SELECT su.*, s.ref AS shipment_ref, s.po_number, s.supplier
            FROM serial_units su
            LEFT JOIN shipments s ON su.shipment_id = s.id
            WHERE ' . implode(' AND ', $where) .
           ' ORDER BY su.arrival_date ASC, su.id ASC LIMIT ' . $limit;
    $st = $pdo->prepare($sql); $st->execute($params);
    ok(['serials' => $st->fetchAll()]);
    break;
}

case 'get_fifo_queue': {
    requireAuth();
    $b    = body();
    $type = $b['product_type'] ?? '';
    $qty  = (int)($b['qty'] ?? 1);
    $brand = $b['brand'] ?? '';
    $model = $b['model'] ?? '';
    if (!$type) err('product_type required.');

    $where = ["status='In Stock'", "product_type=?"];
    $params = [$type];
    if ($brand) { $where[] = 'brand=?'; $params[] = $brand; }
    if ($model) { $where[] = 'model=?'; $params[] = $model; }

    $sql = 'SELECT su.*, s.ref AS shipment_ref, s.arrival_date AS ship_arrival
            FROM serial_units su
            LEFT JOIN shipments s ON su.shipment_id = s.id
            WHERE ' . implode(' AND ', $where) .
           ' ORDER BY su.arrival_date ASC, su.id ASC LIMIT ' . $qty;
    $st = $pdo->prepare($sql); $st->execute($params);
    $units = $st->fetchAll();

    // Also return how many are in stock total for this type/model
    $cntSql = 'SELECT COUNT(*) FROM serial_units WHERE ' . implode(' AND ', $where);
    $cntSt  = $pdo->prepare($cntSql); $cntSt->execute($params);
    ok(['units' => $units, 'available' => (int)$cntSt->fetchColumn()]);
    break;
}

case 'assign_booking_serials': {
    requireAuth();
    $u  = currentUser($pdo);
    $b  = body();
    // assignments: [{serial_no, booking_id, booking_number, project_id, so_number}]
    $assignments = $b['assignments'] ?? [];
    if (empty($assignments)) err('No assignments provided.');

    $today = date('Y-m-d');
    $by    = $u['name'] ?? $u['username'] ?? 'Unknown';
    $stmt  = $pdo->prepare("UPDATE serial_units SET
        status='Released', booking_id=?, booking_number=?, project_id=?,
        so_number=?, release_date=?, released_by=?
        WHERE serial_no=? AND status IN ('In Stock','Reserved')");

    $done = 0;
    foreach ($assignments as $a) {
        $stmt->execute([
            $a['booking_id']     ?? null,
            $a['booking_number'] ?? null,
            $a['project_id']     ?? null,
            $a['so_number']      ?? null,
            $today, $by,
            $a['serial_no'],
        ]);
        if ($stmt->rowCount()) $done++;
    }
    logActivity($pdo, "Assigned {$done} serial units to booking " . ($assignments[0]['booking_number'] ?? ''), 'blue');
    ok(['assigned' => $done]);
    break;
}

case 'search_serial': {
    requireAuth();
    $sn = trim(body()['serial_no'] ?? '');
    if (!$sn) err('serial_no required.');
    $st = $pdo->prepare('SELECT su.*, s.ref AS shipment_ref, s.po_number, s.supplier
                         FROM serial_units su
                         LEFT JOIN shipments s ON su.shipment_id = s.id
                         WHERE su.serial_no = ?');
    $st->execute([$sn]);
    $unit = $st->fetch();
    if (!$unit) err("Serial number '{$sn}' not found.", 404);
    ok(['unit' => $unit]);
    break;
}

case 'get_pallets': {
    requireAuth();
    $b     = body();
    $type  = $b['product_type'] ?? '';
    $where = ["pallet_ref IS NOT NULL AND pallet_ref != ''"]; $params = [];
    if ($type) { $where[] = 'product_type=?'; $params[] = $type; }
    $sql = 'SELECT pallet_ref, product_type, brand, model, watt_rating,
                   MIN(arrival_date) AS arrival_date,
                   COUNT(*) AS total_units,
                   SUM(status="In Stock") AS in_stock,
                   SUM(status="Reserved") AS reserved,
                   SUM(status="Released") AS released,
                   shipment_id
            FROM serial_units
            WHERE ' . implode(' AND ', $where) .
           ' GROUP BY pallet_ref, product_type, brand, model, watt_rating, shipment_id
             ORDER BY arrival_date ASC, pallet_ref ASC';
    $st = $pdo->prepare($sql); $st->execute($params);
    ok(['pallets' => $st->fetchAll()]);
    break;
}

case 'get_pallet_units': {
    requireAuth();
    $b   = body();
    $ref = trim($b['pallet_ref'] ?? '');
    if (!$ref) err('pallet_ref required.');
    $st = $pdo->prepare("SELECT su.*, s.ref AS shipment_ref, s.po_number, s.supplier
                         FROM serial_units su
                         LEFT JOIN shipments s ON su.shipment_id = s.id
                         WHERE su.pallet_ref = ?
                         ORDER BY su.arrival_date ASC, su.id ASC");
    $st->execute([$ref]);
    ok(['units' => $st->fetchAll()]);
    break;
}

case 'toggle_serialized': {
    requireAuth();
    $u = currentUser($pdo);
    if (!in_array($u['role'], ['Director','Super User'])) err('Access denied.');
    $b  = body();
    $id = $b['id'] ?? ''; $val = (int)($b['is_serialized'] ?? 0);
    $pdo->prepare('UPDATE products SET is_serialized=? WHERE id=?')->execute([$val, $id]);
    logActivity($pdo, ($val?'Enabled':'Disabled')." serial tracking for product {$id}", 'blue');
    ok();
    break;
}

// ============================================================
// YARRABOARD — DOCUMENT REPORTS
// ============================================================

case 'get_doc_reports': {
    requireAuth();
    $rows = $pdo->query(
        "SELECT * FROM yb_document_reports ORDER BY generated_at DESC"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['pages'] = (int)$r['pages'];
    }
    ok(['docReports' => $rows]);
    break;
}

case 'generate_doc_report': {
    requireAuth();
    $u = currentUser($pdo);
    $b = body();
    $topic = trim($b['topic'] ?? '');
    $allowed_topics = ['booking','stock','finance','installation','procurement','hr',
                       'payment_collection','milestone_overdue','profitability',
                       'serial_traceability','certification','kpi_dashboard'];
    if (!in_array($topic, $allowed_topics)) err('Invalid topic.');

    $today   = date('Y-m-d');
    $month   = date('M Y');
    $year    = date('Y');
    $weekNum = date('W');
    $author  = $u['name'] ?? $u['username'] ?? 'System';

    $title = $desc = $period = '';
    $authority = '["all","management"]';
    $summary = [];

    switch ($topic) {

        case 'booking':
            // Count bookings by status + total value of confirmed SOs
            $bkCounts = $pdo->query("SELECT status, COUNT(*) AS cnt FROM bookings GROUP BY status")->fetchAll();
            $soTotal  = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM sales_orders WHERE status != 'Cancelled'")->fetchColumn();
            $active   = 0; $confirmed = 0; $cancelled = 0;
            foreach ($bkCounts as $row) {
                if ($row['status'] === 'Active')    $active    = (int)$row['cnt'];
                if ($row['status'] === 'Confirmed') $confirmed = (int)$row['cnt'];
                if ($row['status'] === 'Cancelled') $cancelled = (int)$row['cnt'];
            }
            $totalBk = $active + $confirmed + $cancelled;
            $title   = "Booking Summary Report — {$month}";
            $desc    = "Auto-generated booking overview. Total bookings: {$totalBk} (Active: {$active}, Confirmed: {$confirmed}, Cancelled: {$cancelled}). Total confirmed SO value: " . number_format($soTotal, 2) . " THB.";
            $period  = $month;
            $authority = '["all","management","sales"]';
            $summary = ['total' => $totalBk, 'active' => $active, 'confirmed' => $confirmed, 'cancelled' => $cancelled, 'so_value' => $soTotal];
            break;

        case 'stock':
            // Stock on-hand, reserved, low-stock items, total value
            $inv = $pdo->query("SELECT COUNT(*) AS items, SUM(stock) AS total_qty, SUM(reserved) AS total_res, SUM(stock * cost) AS total_val, SUM(CASE WHEN stock <= min_level THEN 1 ELSE 0 END) AS low FROM products")->fetch();
            $title  = "Stock Inventory Report — Week {$weekNum} {$year}";
            $desc   = "Auto-generated stock snapshot. SKUs tracked: {$inv['items']}. On-hand qty: {$inv['total_qty']}, Reserved: {$inv['total_res']}. Stock value: " . number_format((float)$inv['total_val'], 2) . " THB. Low-stock items: {$inv['low']}.";
            $period = "WK{$weekNum} {$month}";
            $authority = '["all","management","warehouse"]';
            $summary = ['items' => (int)$inv['items'], 'total_qty' => (int)$inv['total_qty'], 'reserved' => (int)$inv['total_res'], 'value' => (float)$inv['total_val'], 'low_stock' => (int)$inv['low']];
            break;

        case 'finance':
            // PO spend vs SO revenue vs project contract values
            $poSpend   = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE status IN ('Ordered','Received')")->fetchColumn();
            $soRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM sales_orders WHERE status != 'Cancelled'")->fetchColumn();
            $contractV = (float)$pdo->query("SELECT COALESCE(SUM(contract_value),0) FROM projects WHERE status NOT IN ('Cancelled')")->fetchColumn();
            $gross     = round($soRevenue - $poSpend, 2);
            $title  = "Finance Summary Report — {$month}";
            $desc   = "Auto-generated financial overview. SO Revenue: " . number_format($soRevenue, 2) . " THB. PO Spend: " . number_format($poSpend, 2) . " THB. Gross Margin (approx): " . number_format($gross, 2) . " THB. Total active contract value: " . number_format($contractV, 2) . " THB.";
            $period = $month;
            $authority = '["management","finance"]';
            $summary = ['so_revenue' => $soRevenue, 'po_spend' => $poSpend, 'gross_margin' => $gross, 'contract_value' => $contractV];
            break;

        case 'installation':
            // Project milestone completion rates
            $projects = $pdo->query("SELECT id, name, status, milestones FROM projects WHERE status NOT IN ('Cancelled')")->fetchAll();
            $total = count($projects); $active = 0; $completed = 0;
            $totalMs = 0; $doneMs = 0;
            foreach ($projects as $p) {
                if ($p['status'] === 'Active')    $active++;
                if ($p['status'] === 'Completed') $completed++;
                $ms = json_decode($p['milestones'] ?? '[]', true);
                if (is_array($ms)) {
                    $totalMs += count($ms);
                    foreach ($ms as $m) { if (($m['status'] ?? '') === 'Complete') $doneMs++; }
                }
            }
            $pct = $totalMs > 0 ? round($doneMs / $totalMs * 100, 1) : 0;
            $title  = "Installation Progress Report — {$month}";
            $desc   = "Auto-generated installation overview. Active projects: {$active}, Completed: {$completed}. Overall milestone completion: {$doneMs}/{$totalMs} ({$pct}%).";
            $period = $month;
            $authority = '["all","management","sales"]';
            $summary = ['total_projects' => $total, 'active' => $active, 'completed' => $completed, 'milestones_done' => $doneMs, 'milestones_total' => $totalMs, 'completion_pct' => $pct];
            break;

        case 'procurement':
            // PO log by status + top suppliers
            $poStats = $pdo->query("SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS val FROM purchase_orders GROUP BY status")->fetchAll();
            $supplierCount = (int)$pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
            $breakdown = [];
            foreach ($poStats as $row) { $breakdown[$row['status']] = ['count' => (int)$row['cnt'], 'value' => (float)$row['val']]; }
            $totalPOs = array_sum(array_column($poStats, 'cnt'));
            $title  = "Procurement Order Log — {$month}";
            $desc   = "Auto-generated procurement summary. Total POs: {$totalPOs}. Draft: " . ($breakdown['Draft']['count'] ?? 0) . ", Ordered: " . ($breakdown['Ordered']['count'] ?? 0) . ", Received: " . ($breakdown['Received']['count'] ?? 0) . ". Active suppliers: {$supplierCount}.";
            $period = $month;
            $authority = '["management","warehouse","finance"]';
            $summary = ['total_pos' => $totalPOs, 'by_status' => $breakdown, 'suppliers' => $supplierCount];
            break;

        case 'hr':
            // User headcount by role
            $roles = $pdo->query("SELECT role, COUNT(*) AS cnt FROM users WHERE is_active=1 GROUP BY role")->fetchAll();
            $total = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
            $byRole = [];
            foreach ($roles as $r) { $byRole[$r['role']] = (int)$r['cnt']; }
            $title  = "HR Headcount Report — {$month}";
            $desc   = "Auto-generated HR snapshot. Total active users: {$total}. Breakdown by role: " . implode(', ', array_map(fn($k,$v) => "{$k}: {$v}", array_keys($byRole), $byRole)) . ".";
            $period = $month;
            $authority = '["management"]';
            $summary = ['total_users' => $total, 'by_role' => $byRole];
            break;

        case 'payment_collection':
            // Parse payment_periods JSON from every active project
            $projects = $pdo->query("SELECT id, name, customer, contract_value, payment_periods FROM projects WHERE status NOT IN ('Cancelled')")->fetchAll();
            $totalInstallments = 0; $paidAmt = 0.0; $invoicedAmt = 0.0; $overdueAmt = 0.0; $pendingAmt = 0.0;
            $overdueItems = []; $invoicedItems = [];
            $todayTs = strtotime($today);
            foreach ($projects as $p) {
                $pp = json_decode($p['payment_periods'] ?? '[]', true);
                if (!is_array($pp)) continue;
                foreach ($pp as $inst) {
                    $totalInstallments++;
                    $amt = (float)($inst['amount'] ?? 0);
                    $st  = $inst['status'] ?? 'Pending';
                    if ($st === 'Paid')     $paidAmt     += $amt;
                    if ($st === 'Invoiced') $invoicedAmt += $amt;
                    if ($st === 'Pending')  $pendingAmt  += $amt;
                    if ($st === 'Overdue')  { $overdueAmt += $amt; $overdueItems[] = ['project' => $p['name'], 'customer' => $p['customer'], 'label' => $inst['label'] ?? '—', 'amount' => $amt]; }
                    if ($st === 'Invoiced') $invoicedItems[] = ['project' => $p['name'], 'customer' => $p['customer'], 'label' => $inst['label'] ?? '—', 'amount' => $amt];
                }
            }
            $title  = "Payment Collection Report — {$month}";
            $desc   = "Auto-generated payment collection overview across " . count($projects) . " projects. Total installments: {$totalInstallments}. Collected: " . number_format($paidAmt,2) . " THB. Invoiced (awaiting): " . number_format($invoicedAmt,2) . " THB. Overdue: " . number_format($overdueAmt,2) . " THB. Pending: " . number_format($pendingAmt,2) . " THB.";
            $period = $month;
            $authority = '["management","finance"]';
            $summary = ['projects' => count($projects), 'installments' => $totalInstallments, 'paid' => $paidAmt, 'invoiced' => $invoicedAmt, 'overdue' => $overdueAmt, 'pending' => $pendingAmt, 'overdue_items' => $overdueItems, 'invoiced_items' => $invoicedItems];
            break;

        case 'milestone_overdue':
            // Find all overdue milestones across active projects
            $projects = $pdo->query("SELECT id, name, customer, type, milestones FROM projects WHERE status = 'Active'")->fetchAll();
            $overdueMs = []; $warn7Ms = []; $totalOverdue = 0;
            foreach ($projects as $p) {
                $ms = json_decode($p['milestones'] ?? '[]', true);
                if (!is_array($ms)) continue;
                foreach ($ms as $m) {
                    if (in_array($m['status'] ?? '', ['Complete','Cancelled'])) continue;
                    $tgt = $m['targetDate'] ?? '';
                    if (!$tgt) continue;
                    $diff = (strtotime($tgt) - strtotime($today)) / 86400;
                    if ($diff < 0) {
                        $totalOverdue++;
                        $overdueMs[] = ['project' => $p['name'], 'customer' => $p['customer'], 'type' => $p['type'], 'milestone' => $m['name'], 'target' => $tgt, 'days_overdue' => abs((int)$diff), 'assigned' => $m['assignedTo'] ?? '—'];
                    } elseif ($diff <= 7) {
                        $warn7Ms[] = ['project' => $p['name'], 'customer' => $p['customer'], 'milestone' => $m['name'], 'target' => $tgt, 'days_left' => (int)$diff];
                    }
                }
            }
            usort($overdueMs, fn($a,$b) => $b['days_overdue'] <=> $a['days_overdue']);
            $title  = "Milestone Overdue Report — {$today}";
            $desc   = "Auto-generated milestone alert. Active projects scanned: " . count($projects) . ". Overdue milestones: {$totalOverdue}. Due within 7 days: " . count($warn7Ms) . ". Most overdue: " . ($overdueMs[0]['milestone'] ?? 'none') . " (" . ($overdueMs[0]['project'] ?? '') . ").";
            $period = $month;
            $authority = '["all","management","sales"]';
            $summary = ['projects_scanned' => count($projects), 'overdue_count' => $totalOverdue, 'warn7_count' => count($warn7Ms), 'overdue_items' => $overdueMs, 'warn7_items' => $warn7Ms];
            break;

        case 'profitability':
            // Contract value vs PO spend per project
            $projects = $pdo->query("SELECT id, name, customer, type, status, contract_value FROM projects WHERE status NOT IN ('Cancelled')")->fetchAll();
            $items = [];
            $totalContract = 0.0; $totalSpend = 0.0;
            foreach ($projects as $p) {
                $spend = (float)$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM purchase_orders WHERE project_id=? AND status IN ('Ordered','Received')")->execute([$p['id']]) ? 0 : 0;
                // Re-query properly
                $st = $pdo->prepare("SELECT COALESCE(SUM(total),0) AS s FROM purchase_orders WHERE project_id=? AND status IN ('Ordered','Received')");
                $st->execute([$p['id']]);
                $spend = (float)($st->fetchColumn() ?: 0);
                $contract = (float)($p['contract_value'] ?? 0);
                $margin = $contract > 0 ? round(($contract - $spend) / $contract * 100, 1) : null;
                $totalContract += $contract;
                $totalSpend    += $spend;
                $items[] = ['project' => $p['name'], 'customer' => $p['customer'], 'type' => $p['type'], 'status' => $p['status'], 'contract' => $contract, 'po_spend' => $spend, 'gross_margin' => $margin];
            }
            $overallMargin = $totalContract > 0 ? round(($totalContract - $totalSpend) / $totalContract * 100, 1) : 0;
            $title  = "Project Profitability Report — {$month}";
            $desc   = "Auto-generated profitability analysis. Projects: " . count($projects) . ". Total contract value: " . number_format($totalContract,2) . " THB. Total PO spend: " . number_format($totalSpend,2) . " THB. Overall gross margin: {$overallMargin}%.";
            $period = $month;
            $authority = '["management","finance"]';
            $summary = ['projects' => count($projects), 'total_contract' => $totalContract, 'total_po_spend' => $totalSpend, 'overall_margin_pct' => $overallMargin, 'items' => $items];
            break;

        case 'serial_traceability':
            // Serial unit movements — released to projects/bookings
            $released = $pdo->query("SELECT su.serial_no, su.product_type, su.brand, su.model, su.status, su.booking_number, su.so_number, su.project_id, su.release_date, su.released_by, su.condition_status FROM serial_units su WHERE su.status IN ('Released','Warranty Claim') ORDER BY su.release_date DESC LIMIT 500")->fetchAll();
            $byType   = []; $warrantyCount = 0;
            foreach ($released as $u) {
                $byType[$u['product_type']] = ($byType[$u['product_type']] ?? 0) + 1;
                if ($u['status'] === 'Warranty Claim') $warrantyCount++;
            }
            $totalIn  = (int)$pdo->query("SELECT COUNT(*) FROM serial_units WHERE status='In Stock'")->fetchColumn();
            $totalRes = (int)$pdo->query("SELECT COUNT(*) FROM serial_units WHERE status='Reserved'")->fetchColumn();
            $title  = "Serial Number Traceability Report — {$today}";
            $desc   = "Auto-generated serial traceability snapshot. Released to site: " . count($released) . " units. In stock: {$totalIn}. Reserved: {$totalRes}. Active warranty claims: {$warrantyCount}. Breakdown by type: " . implode(', ', array_map(fn($k,$v)=>"{$k}: {$v}", array_keys($byType), $byType)) . ".";
            $period = $month;
            $authority = '["management","warehouse"]';
            $summary = ['released' => count($released), 'in_stock' => $totalIn, 'reserved' => $totalRes, 'warranty_claims' => $warrantyCount, 'by_type' => $byType, 'units' => $released];
            break;

        case 'certification':
            // Staff exam results from exam_results table
            $results  = $pdo->query("SELECT staff_id, staff_name, MAX(score) AS best_score, COUNT(*) AS attempts, SUM(passed) AS passes, MAX(taken_at) AS last_taken FROM exam_results GROUP BY staff_id, staff_name ORDER BY last_taken DESC")->fetchAll();
            $totalPassed  = 0; $totalFailed = 0;
            foreach ($results as $r) {
                if ((int)$r['passes'] > 0) $totalPassed++; else $totalFailed++;
            }
            $avgScore = count($results) ? round(array_sum(array_column($results,'best_score')) / count($results), 1) : 0;
            $title  = "Staff Certification Report — {$month}";
            $desc   = "Auto-generated certification overview. Staff with exam records: " . count($results) . ". Certified (passed): {$totalPassed}. Not yet passed: {$totalFailed}. Average best score: {$avgScore}%.";
            $period = $month;
            $authority = '["management"]';
            $summary = ['staff_count' => count($results), 'certified' => $totalPassed, 'not_passed' => $totalFailed, 'avg_score' => $avgScore, 'staff' => $results];
            break;

        case 'kpi_dashboard':
            // Cross-system KPI snapshot
            $bkMonth    = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m') AND status != 'Cancelled'")->fetchColumn();
            $soValueMth = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM sales_orders WHERE DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m') AND status != 'Cancelled'")->fetchColumn();
            $projCompleted = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status='Completed' AND DATE_FORMAT(updated_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')")->fetchColumn();
            $projActive    = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status='Active'")->fetchColumn();
            $stockVal      = (float)$pdo->query("SELECT COALESCE(SUM(stock*cost),0) FROM products")->fetchColumn();
            $lowStock      = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock <= min_level")->fetchColumn();
            $poReceived    = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='Received' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')")->fetchColumn();
            // Payment collected this month
            $allProjects   = $pdo->query("SELECT payment_periods FROM projects WHERE status NOT IN ('Cancelled')")->fetchAll();
            $collectedMth  = 0.0;
            foreach ($allProjects as $p) {
                $pp = json_decode($p['payment_periods'] ?? '[]', true);
                if (!is_array($pp)) continue;
                foreach ($pp as $inst) { if (($inst['status'] ?? '') === 'Paid') $collectedMth += (float)($inst['amount'] ?? 0); }
            }
            // Overdue milestones
            $msOverdue = 0;
            $activeProj = $pdo->query("SELECT milestones FROM projects WHERE status='Active'")->fetchAll();
            foreach ($activeProj as $p) {
                $ms = json_decode($p['milestones'] ?? '[]', true);
                if (!is_array($ms)) continue;
                foreach ($ms as $m) {
                    $tgt = $m['targetDate'] ?? '';
                    if ($tgt && !in_array($m['status']??'',['Complete','Cancelled']) && strtotime($tgt) < strtotime($today)) $msOverdue++;
                }
            }
            $title  = "Monthly KPI Dashboard — {$month}";
            $desc   = "Cross-system KPI snapshot for {$month}. Bookings this month: {$bkMonth}. SO value dispatched: " . number_format($soValueMth,2) . " THB. Active projects: {$projActive}. Completed this month: {$projCompleted}. Stock value: " . number_format($stockVal,2) . " THB. Low-stock items: {$lowStock}. POs received: {$poReceived}. Overdue milestones: {$msOverdue}.";
            $period = $month;
            $authority = '["management"]';
            $summary = ['bookings_month' => $bkMonth, 'so_value_month' => $soValueMth, 'projects_active' => $projActive, 'projects_completed_month' => $projCompleted, 'stock_value' => $stockVal, 'low_stock_items' => $lowStock, 'pos_received_month' => $poReceived, 'cash_collected' => $collectedMth, 'overdue_milestones' => $msOverdue];
            break;
    }

    $id  = uid();
    $pdo->prepare("INSERT INTO yb_document_reports
        (id, title, description, topic, authority, period, status, author, pages, file_size, summary_json, generated_at, created_by)
        VALUES (?,?,?,?,?,?,'Draft',?,0,'auto',?,NOW(),?)")
        ->execute([$id, $title, $desc, $topic, $authority, $period, $author, json_encode($summary), $author]);

    logActivity($pdo, "Generated {$topic} document report: {$title}", 'blue');
    $row = $pdo->prepare("SELECT * FROM yb_document_reports WHERE id=?");
    $row->execute([$id]);
    ok(['report' => $row->fetch()]);
    break;
}

case 'update_doc_report_status': {
    requireAuth();
    $b      = body();
    $id     = $b['id']     ?? '';
    $status = $b['status'] ?? '';
    $allowed = ['Draft','Review','Final'];
    if (!$id || !in_array($status, $allowed)) err('id and valid status required.');
    $pdo->prepare("UPDATE yb_document_reports SET status=? WHERE id=?")->execute([$status, $id]);
    ok();
    break;
}

case 'delete_doc_report': {
    $u = requireRole($pdo, ['Super User']);
    $id = body()['id'] ?? '';
    if (!$id) err('id required.');
    $pdo->prepare("DELETE FROM yb_document_reports WHERE id=?")->execute([$id]);
    logActivity($pdo, "Deleted document report {$id}", 'red');
    ok();
    break;
}

// ============================================================
// ACCESSORIES — shared server-side accessories stock
// (previously each browser kept its own copy in localStorage)
// ============================================================

case 'acc_list': {
    requireAuth();
    accMigrate($pdo);
    $rows = $pdo->query('SELECT id, sheet, item_name, length_m, quantity, quantity_incomplete, remark FROM accessories ORDER BY sheet, item_name')->fetchAll();
    foreach ($rows as &$r) {
        $r['id']                  = (int)$r['id'];
        $r['length_m']            = $r['length_m'] !== null ? (float)$r['length_m'] : null;
        $r['quantity']            = $r['quantity'] !== null ? (int)$r['quantity'] : null;
        $r['quantity_incomplete'] = $r['quantity_incomplete'] !== null ? (int)$r['quantity_incomplete'] : null;
    }
    ok(['items' => $rows]);
    break;
}

case 'acc_bulk_save': {
    requireRole($pdo, ['Project Manager','Director','Super User']);
    accMigrate($pdo);
    $items = body()['items'] ?? [];
    if (!is_array($items)) err('items must be an array');
    if (count($items) > 5000) err('Too many items');
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM accessories');
        $ins = $pdo->prepare('INSERT INTO accessories (id, sheet, item_name, length_m, quantity, quantity_incomplete, remark) VALUES (?,?,?,?,?,?,?)');
        foreach ($items as $it) {
            if (!is_array($it) || !isset($it['id'])) continue;
            $ins->execute([
                (int)$it['id'],
                mb_substr(trim($it['sheet'] ?? ''), 0, 200),
                mb_substr(trim($it['item_name'] ?? ''), 0, 500),
                ($it['length_m'] ?? null) !== null && $it['length_m'] !== '' ? (float)$it['length_m'] : null,
                ($it['quantity'] ?? null) !== null && $it['quantity'] !== '' ? (int)$it['quantity'] : null,
                ($it['quantity_incomplete'] ?? null) !== null && $it['quantity_incomplete'] !== '' ? (int)$it['quantity_incomplete'] : null,
                ($it['remark'] ?? null) !== null ? mb_substr((string)$it['remark'], 0, 1000) : null,
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        err('Failed to save accessories: ' . $e->getMessage(), 500);
    }
    ok(['saved' => count($items)]);
    break;
}

// ============================================================
// YARRAEXAM — SERVER-SIDE EXAM BACKEND
// Questions & answer key live on the server (question_bank.json).
// The browser never receives correct answers before submission,
// and scores are computed server-side only.
// ============================================================

case 'exam_login': {
    examMigrate($pdo);
    $b   = body();
    $sid = trim($b['staffId'] ?? '');
    $pw  = $b['password'] ?? '';
    if (!$sid || !$pw) err('กรุณากรอกรหัสพนักงานและรหัสผ่าน');

    // 1) Admin login — main system users with Director / Super User role
    $s = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
    $s->execute([strtolower($sid)]);
    $u = $s->fetch();
    if ($u && in_array($u['role'], ['Director','Super User']) && password_verify($pw, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['exam_staff'] = ['id' => strtoupper($u['username']), 'fullname' => $u['name'], 'isAdmin' => true];
        ok(['staff' => $_SESSION['exam_staff']]);
    }

    // 2) Staff login — exam_staff table
    $s = $pdo->prepare('SELECT * FROM exam_staff WHERE staff_id = ? AND is_active = 1');
    $s->execute([strtoupper($sid)]);
    $st = $s->fetch();
    if (!$st || !password_verify($pw, $st['password_hash'])) {
        usleep(400000);
        err('รหัสพนักงานหรือรหัสผ่านไม่ถูกต้อง กรุณาติดต่อ Admin หากยังเข้าไม่ได้', 401);
    }
    session_regenerate_id(true);
    $_SESSION['exam_staff'] = ['id' => $st['staff_id'], 'fullname' => $st['fullname'], 'isAdmin' => false];
    ok(['staff' => $_SESSION['exam_staff']]);
    break;
}

case 'exam_logout':
    unset($_SESSION['exam_staff'], $_SESSION['exam']);
    ok();
    break;

case 'exam_check_session':
    if (!empty($_SESSION['exam_staff'])) ok(['logged_in' => true, 'staff' => $_SESSION['exam_staff']]);
    ok(['logged_in' => false]);
    break;

case 'exam_start': {
    $staff = examRequireStaff();
    $bank  = examLoadBank();
    $cfg   = examConfig();
    $questions = []; $key = []; $maxPts = 0;
    foreach ($cfg['cats'] as $cat => $counts) {
        foreach (['mc','tf'] as $t) {
            $pool = $bank[$cat][$t] ?? [];
            shuffle($pool);
            $picked = array_slice($pool, 0, min($counts[$t], count($pool)));
            $pts = $t === 'mc' ? $cfg['mcPts'] : $cfg['tfPts'];
            foreach ($picked as $q) {
                $key[$q['id']] = [
                    'ans'  => $q['answer'], 'type' => $t, 'cat' => $cat, 'pts' => $pts,
                    'q'    => $q['question'], 'opts' => $q['options'] ?? null,
                    'exp'  => $q['explanation'] ?? ''
                ];
                $maxPts += $pts;
                $out = ['id' => $q['id'], 'type' => $t, 'cat' => $cat, 'q' => $q['question']];
                if ($t === 'mc') $out['opts'] = $q['options'];
                $questions[] = $out;
            }
        }
    }
    shuffle($questions);
    $passPts  = (int)ceil($maxPts * $cfg['passPct']);
    $deadline = time() + $cfg['timeMins'] * 60;
    $_SESSION['exam'] = [
        'key' => $key, 'maxPts' => $maxPts, 'passPts' => $passPts,
        'deadline' => $deadline, 'started' => time(),
        'order' => array_column($questions, 'id')
    ];
    ok([
        'questions' => $questions,
        'deadlineMs' => $deadline * 1000,
        'serverNowMs' => (int)(microtime(true) * 1000),
        'timeMins' => $cfg['timeMins'], 'maxPts' => $maxPts, 'passPts' => $passPts,
        'catLabels' => $cfg['labels'], 'mcPts' => $cfg['mcPts'], 'tfPts' => $cfg['tfPts']
    ]);
    break;
}

case 'exam_submit': {
    $staff = examRequireStaff();
    if (empty($_SESSION['exam'])) err('ไม่มีการสอบที่กำลังดำเนินอยู่ กรุณาเริ่มการสอบใหม่', 400);
    $exam = $_SESSION['exam'];
    unset($_SESSION['exam']); // one submission per exam
    if (time() > $exam['deadline'] + 120) err('หมดเวลาการสอบแล้ว ไม่สามารถส่งคำตอบได้', 400);

    $answers = body()['answers'] ?? [];
    if (!is_array($answers)) $answers = [];

    $cfg = examConfig();
    $catR = []; $wrong = []; $totPts = 0; $totOk = 0; $totQ = 0;
    foreach ($cfg['labels'] as $c => $lbl) $catR[$c] = ['ok'=>0,'tot'=>0,'pts'=>0,'max'=>0];

    foreach ($exam['key'] as $qid => $k) {
        $totQ++;
        $catR[$k['cat']]['tot']++;
        $catR[$k['cat']]['max'] += $k['pts'];
        $ua = $answers[$qid] ?? null;
        $correct = false;
        if ($ua !== null) {
            $correct = $k['type'] === 'mc' ? ((int)$ua === (int)$k['ans']) : ((bool)$ua === (bool)$k['ans']);
        }
        if ($correct) {
            $totOk++; $totPts += $k['pts'];
            $catR[$k['cat']]['ok']++; $catR[$k['cat']]['pts'] += $k['pts'];
        } else {
            $wrong[] = [
                'id' => $qid, 'cat' => $k['cat'], 'type' => $k['type'],
                'q' => $k['q'], 'opts' => $k['opts'], 'ua' => $ua,
                'ans' => $k['ans'], 'exp' => $k['exp']
            ];
        }
    }

    $passed  = $totPts >= $exam['passPts'];
    $elapsed = time() - $exam['started'];

    $pdo->prepare("INSERT INTO exam_results
        (staff_id, staff_name, score, passed, time_taken, cat_a_score, cat_b_score, cat_c_score, cat_d_score, answers_json)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $staff['id'], $staff['fullname'], $totPts, $passed ? 1 : 0, $elapsed,
            $catR['A']['pts'] ?? null, $catR['B']['pts'] ?? null,
            $catR['C']['pts'] ?? null, $catR['D']['pts'] ?? null,
            json_encode($answers, JSON_UNESCAPED_UNICODE)
        ]);

    ok([
        'result' => [
            'user' => $staff['id'], 'name' => $staff['fullname'],
            'date' => date('c'),
            'totQ' => $totQ, 'totOk' => $totOk,
            'totPts' => $totPts, 'maxPts' => $exam['maxPts'],
            'pass' => $exam['passPts'], 'passed' => $passed,
            'catR' => $catR, 'catLabels' => $cfg['labels'],
            'wrong' => $wrong, 'elapsed' => $elapsed
        ]
    ]);
    break;
}

case 'exam_history': {
    $staff = examRequireStaff();
    if (!empty($staff['isAdmin'])) {
        $rows = $pdo->query("SELECT staff_id, staff_name, score, passed, time_taken, cat_a_score, cat_b_score, cat_c_score, cat_d_score, taken_at
                             FROM exam_results ORDER BY taken_at DESC LIMIT 1000")->fetchAll();
    } else {
        $s = $pdo->prepare("SELECT staff_id, staff_name, score, passed, time_taken, taken_at
                            FROM exam_results WHERE staff_id = ? ORDER BY taken_at DESC LIMIT 100");
        $s->execute([$staff['id']]);
        $rows = $s->fetchAll();
    }
    foreach ($rows as &$r) { $r['score'] = (int)$r['score']; $r['passed'] = (bool)$r['passed']; }
    ok(['results' => $rows, 'maxPts' => 100, 'passPts' => 90]);
    break;
}

case 'exam_staff_list': {
    examRequireAdmin();
    $rows = $pdo->query("SELECT staff_id, fullname, is_active, created_at FROM exam_staff ORDER BY staff_id")->fetchAll();
    ok(['staff' => $rows]);
    break;
}

case 'exam_staff_save': {
    $admin = examRequireAdmin();
    examMigrate($pdo);
    $b = body();
    $sid = strtoupper(trim($b['staffId'] ?? ''));
    $nm  = trim($b['fullname'] ?? '');
    $pw  = $b['password'] ?? '';
    if (!$sid || !$nm) err('กรุณากรอกรหัสพนักงานและชื่อ');
    if (strtolower($sid) === 'admin') err('ไม่สามารถใช้รหัส ADMIN');
    $s = $pdo->prepare('SELECT COUNT(*) FROM exam_staff WHERE staff_id = ?');
    $s->execute([$sid]);
    if ((int)$s->fetchColumn() > 0) {
        // Update existing (name and/or password reset)
        if ($pw !== '') {
            if (strlen($pw) < 6) err('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
            $pdo->prepare('UPDATE exam_staff SET fullname = ?, password_hash = ?, is_active = 1 WHERE staff_id = ?')
                ->execute([$nm, password_hash($pw, PASSWORD_BCRYPT), $sid]);
        } else {
            $pdo->prepare('UPDATE exam_staff SET fullname = ?, is_active = 1 WHERE staff_id = ?')->execute([$nm, $sid]);
        }
        ok(['updated' => true]);
    }
    if (strlen($pw) < 6) err('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
    $pdo->prepare('INSERT INTO exam_staff (staff_id, fullname, password_hash, created_by) VALUES (?,?,?,?)')
        ->execute([$sid, $nm, password_hash($pw, PASSWORD_BCRYPT), $admin['id']]);
    ok(['created' => true]);
    break;
}

case 'exam_staff_delete': {
    examRequireAdmin();
    $sid = strtoupper(trim(body()['staffId'] ?? ''));
    if (!$sid) err('staffId required');
    $pdo->prepare('DELETE FROM exam_staff WHERE staff_id = ?')->execute([$sid]);
    ok();
    break;
}

case 'exam_bank_stats': {
    examRequireAdmin();
    $bank = examLoadBank();
    $cfg  = examConfig();
    $stats = [];
    foreach ($cfg['cats'] as $cat => $counts) {
        $stats[$cat] = [
            'label' => $cfg['labels'][$cat],
            'mc' => count($bank[$cat]['mc'] ?? []), 'mcUsed' => $counts['mc'],
            'tf' => count($bank[$cat]['tf'] ?? []), 'tfUsed' => $counts['tf'],
        ];
    }
    ok(['bank' => $stats]);
    break;
}

case 'exam_clear_results': {
    examRequireAdmin();
    $pdo->exec('DELETE FROM exam_results');
    ok();
    break;
}

// Legacy endpoint (client-computed scores) — removed for security.
case 'save_exam_result':
    err('This endpoint has been replaced by server-side grading (exam_submit).', 410);
    break;

case 'get_exam_results': {
    requireAuth();
    $u = requireRole($pdo, ['Director', 'Super User']);
    $rows = $pdo->query(
        "SELECT * FROM exam_results ORDER BY taken_at DESC LIMIT 1000"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['score']     = (int)$r['score'];
        $r['passed']    = (bool)$r['passed'];
        $r['time_taken'] = (int)$r['time_taken'];
    }
    ok(['results' => $rows]);
    break;
}

// ============================================================
// DEFAULT
// ============================================================
default:
    err("Unknown action: '$action'", 404);
}
