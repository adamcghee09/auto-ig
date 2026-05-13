<?php
declare(strict_types=1);
require_once __DIR__ . '/_config.php';

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
if (!is_dir(VIDEO_DIR)) mkdir(VIDEO_DIR, 0755, true);
if (!is_dir(RENDER_DIR)) mkdir(RENDER_DIR, 0755, true);
if (!is_dir(dirname(LOG_PATH))) mkdir(dirname(LOG_PATH), 0750, true);

function app_start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function is_installed(): bool { return file_exists(DB_PATH); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function now_utc(): string { return gmdate('Y-m-d H:i:s'); }

function require_install(): void { if (!is_installed() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') redirect('install.php'); }
function current_admin(): ?array {
    app_start_session();
    if (empty($_SESSION['admin_id']) || !is_installed()) return null;
    $stmt = db()->prepare('SELECT id, username FROM admins WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    return $stmt->fetch() ?: null;
}
function require_login(): array { require_install(); $a = current_admin(); if (!$a) redirect('login.php'); return $a; }
function csrf_token(): string { app_start_session(); $_SESSION['csrf'] ??= bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function verify_csrf(): void { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? null)) { http_response_code(403); exit('Invalid CSRF token.'); } }

function app_key(): string {
    if (!file_exists(KEY_PATH)) file_put_contents(KEY_PATH, random_bytes(32), LOCK_EX);
    return file_get_contents(KEY_PATH);
}
function encrypt_secret(string $plain): string {
    if ($plain === '') return '';
    $key = app_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'sodium:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    $iv = random_bytes(16);
    return 'openssl:' . base64_encode($iv . openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
}
function decrypt_secret(?string $cipher): string {
    if (!$cipher) return '';
    if (str_starts_with($cipher, 'sodium:') && function_exists('sodium_crypto_secretbox_open')) {
        $raw = base64_decode(substr($cipher, 7), true); if ($raw === false) return '';
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($box, $nonce, app_key()); return $plain === false ? '' : $plain;
    }
    if (str_starts_with($cipher, 'openssl:')) {
        $raw = base64_decode(substr($cipher, 8), true); if ($raw === false) return '';
        $iv = substr($raw, 0, 16); $data = substr($raw, 16);
        return openssl_decrypt($data, 'aes-256-cbc', app_key(), OPENSSL_RAW_DATA, $iv) ?: '';
    }
    return '';
}
function mask_secret(string $secret): string { return $secret === '' ? 'Not set' : substr($secret, 0, 4) . str_repeat('•', 12) . substr($secret, -4); }

function get_setting(string $key, string $default = ''): string {
    $stmt = db()->prepare('SELECT value, encrypted FROM settings WHERE key = ?'); $stmt->execute([$key]); $row = $stmt->fetch();
    if (!$row) return $default;
    return (int)$row['encrypted'] === 1 ? decrypt_secret($row['value']) : (string)$row['value'];
}
function set_setting(string $key, string $value, bool $encrypted = false): void {
    $stored = $encrypted ? encrypt_secret($value) : $value;
    $stmt = db()->prepare('INSERT INTO settings(key,value,encrypted,updated_at) VALUES(?,?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value, encrypted=excluded.encrypted, updated_at=excluded.updated_at');
    $stmt->execute([$key, $stored, $encrypted ? 1 : 0, now_utc()]);
}
function all_settings_meta(): array { $rows = db()->query('SELECT key,value,encrypted FROM settings')->fetchAll(); return array_column($rows, null, 'key'); }

function app_log(string $level, string $message, array $context = []): void {
    $line = json_encode(['ts' => now_utc(), 'level' => $level, 'message' => $message, 'context' => $context], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
    if (is_installed()) {
        $stmt = db()->prepare('INSERT INTO logs(level,message,context,created_at) VALUES(?,?,?,?)');
        $stmt->execute([$level, $message, json_encode($context), now_utc()]);
    }
}

function http_json(string $url, array $headers = [], ?array $post = null, int $timeout = 45): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post)); }
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false || $code >= 400) throw new RuntimeException("HTTP $code $err $body");
    $json = json_decode($body, true); return is_array($json) ? $json : [];
}

function download_file(string $url, string $dest): void {
    $fp = fopen($dest, 'wb'); if (!$fp) throw new RuntimeException('Cannot write file.');
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180]);
    $ok = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); fclose($fp);
    if (!$ok || $code >= 400) { @unlink($dest); throw new RuntimeException("Download failed HTTP $code $err"); }
}

function layout_header(string $title): void { $admin = current_admin(); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?=h($title)?> · <?=APP_NAME?></title><link rel="stylesheet" href="assets/app.css"></head><body><header class="top"><a class="brand" href="dashboard.php"><?=APP_NAME?></a><?php if($admin): ?><nav><a href="dashboard.php">Dashboard</a><a href="products.php">Products</a><a href="campaigns.php">Campaigns</a><a href="drafts.php">Drafts</a><a href="prompts.php">Prompts</a><a href="settings.php">Settings</a><a href="logout.php">Logout</a></nav><?php endif; ?></header><main class="wrap"><h1><?=h($title)?></h1><?php }
function layout_footer(): void { ?></main><footer class="foot">Admin-only affiliate content automation. Review every claim before publishing.</footer><script src="assets/app.js"></script></body></html><?php }
function flash(?string $msg = null): ?string { app_start_session(); if ($msg !== null) { $_SESSION['flash'] = $msg; return null; } $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m; }
function show_flash(): void { if ($m = flash()) echo '<div class="notice">' . h($m) . '</div>'; }

function default_prompt_templates(): array { return [
'motivational_affiliate_reel' => 'Create a motivational affiliate Reel for {{product_name}} targeting {{audience}}. Tone: {{tone}}. Benefits: {{benefits}}. CTA: {{cta}}. Include {{hashtags}}.',
'problem_solution_reel' => 'Create a problem/solution Reel for {{product_name}}. Problem audience faces: infer safely from {{audience}} and {{benefits}}. Include affiliate disclosure and {{cta}}.',
'things_i_wish_i_knew_reel' => 'Create a “things I wish I knew” Reel about choosing {{product_name}} for {{audience}}. Avoid guarantees. Include {{affiliate_url}} and {{hashtags}}.',
'quick_tip_reel' => 'Create a quick tip Reel connected to {{product_name}}. Use {{tone}} tone, mention benefits: {{benefits}}, and end with {{cta}}.',
'buyer_checklist_reel' => 'Create a buyer checklist Reel for {{product_name}} in the {{audience}} market. Include practical checklist points, disclaimer, and {{hashtags}}.',
'myth_vs_fact_reel' => 'Create a myth vs fact Reel about {{product_name}}. Keep claims conservative and evidence-neutral. CTA: {{cta}}.',
'before_after_style_reel' => 'Create a before/after style Reel for {{product_name}} without promising outcomes. Show relatable transformation framing for {{audience}}.',
]; }

function render_status_badge(string $status): string { $class = preg_replace('/[^a-z0-9]+/', '-', strtolower($status)); return '<span class="badge status-' . h($class) . '">' . h($status) . '</span>'; }
