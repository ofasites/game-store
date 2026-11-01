<?php
// IP-based Grand Opening 50% promo code claim service.
// One 50% discount code per visitor IP during the 7-day Grand Opening countdown.
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Allowed origins
$origin = 'https://oneforallgameservice.com';
$allowed = [$origin, 'https://www.oneforallgameservice.com'];
if (!empty($_SERVER['HTTP_ORIGIN']) && !in_array($_SERVER['HTTP_ORIGIN'], $allowed, true)) {
    // allow local testing when origin starts with http://localhost or file://
    if (!preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', (string)$_SERVER['HTTP_ORIGIN'])) {
        reply(403, ['ok' => false, 'error' => 'Invalid origin.']);
    }
}

// 7-day countdown start and end (Bangkok UTC+7)
// Starts Monday Nov 2, 2026 00:00:00 (timestamp 1793552400)
// Ends Monday Nov 9, 2026 00:00:00 (timestamp 1794157200)
$promoStart = 1793552400; // 2026-11-02 00:00:00 UTC+7
$promoEnd   = 1794157200; // 2026-11-09 00:00:00 UTC+7
$now = time();

// Client IP detection (safe hash)
$rawIp = $_SERVER['HTTP_CF_CONNECTING_IP'] 
    ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
    ?? $_SERVER['REMOTE_ADDR'] 
    ?? '127.0.0.1';
// If comma separated, take first valid IP
if (strpos($rawIp, ',') !== false) {
    $parts = explode(',', $rawIp);
    $rawIp = trim($parts[0]);
}
$ipHash = hash('sha256', 'ofa_promo_' . $rawIp);

// Connect to SQLite DB in ofa-private
function promoDb(): PDO {
    $dir = dirname(__DIR__) . '/ofa-private';
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Private storage unavailable');
    }
    $db = new PDO('sqlite:' . $dir . '/customers.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS ip_promo_claims (
        ip_hash TEXT PRIMARY KEY,
        code TEXT NOT NULL UNIQUE,
        claimed_at INTEGER NOT NULL,
        percent INTEGER NOT NULL DEFAULT 50,
        used INTEGER NOT NULL DEFAULT 0
    )');
    @chmod($dir . '/customers.sqlite', 0600);
    return $db;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');

try {
    $db = promoDb();
} catch (Throwable $e) {
    reply(500, ['ok' => false, 'error' => 'Storage temporarily unavailable.']);
}

// Action: STATUS (check if this IP already claimed or can claim)
if ($action === 'status') {
    $q = $db->prepare('SELECT code, claimed_at, percent FROM ip_promo_claims WHERE ip_hash = ?');
    $q->execute([$ipHash]);
    $claim = $q->fetch(PDO::FETCH_ASSOC);

    reply(200, [
        'ok' => true,
        'active' => ($now < $promoEnd),
        'start' => $promoStart,
        'end' => $promoEnd,
        'now' => $now,
        'claimed' => (bool)$claim,
        'code' => $claim ? $claim['code'] : null,
        'percent' => $claim ? (int)$claim['percent'] : 50,
    ]);
}

// Action: CLAIM
if ($action === 'claim') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }

    if ($now >= $promoEnd) {
        reply(400, ['ok' => false, 'error' => 'The Grand Opening 50% promotion has ended.']);
    }

    // Check if IP already claimed
    $q = $db->prepare('SELECT code, claimed_at, percent FROM ip_promo_claims WHERE ip_hash = ?');
    $q->execute([$ipHash]);
    $existing = $q->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        reply(200, [
            'ok' => true,
            'alreadyClaimed' => true,
            'code' => $existing['code'],
            'percent' => (int)$existing['percent'],
            'message' => 'You have already claimed your 50% discount code (1 Per Customer).'
        ]);
    }

    // Generate unique code in format OFA-50-XXXX-XXXX
    $db->exec('BEGIN IMMEDIATE');
    try {
        // Double check within transaction
        $q = $db->prepare('SELECT code FROM ip_promo_claims WHERE ip_hash = ?');
        $q->execute([$ipHash]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $db->exec('COMMIT');
            reply(200, [
                'ok' => true,
                'alreadyClaimed' => true,
                'code' => $row['code'],
                'percent' => 50
            ]);
        }

        // Generate a new 50% opening promo code
        $code = 'OFA-OPEN50-' . strtoupper(bin2hex(random_bytes(4)));

        $ins = $db->prepare('INSERT INTO ip_promo_claims (ip_hash, code, claimed_at, percent) VALUES (?, ?, ?, 50)');
        $ins->execute([$ipHash, $code, $now]);

        $db->exec('COMMIT');

        reply(200, [
            'ok' => true,
            'code' => $code,
            'percent' => 50,
            'message' => '50% discount code claimed successfully!'
        ]);
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        reply(500, ['ok' => false, 'error' => 'Could not complete claim. Please try again.']);
    }
}

reply(400, ['ok' => false, 'error' => 'Unknown action.']);
