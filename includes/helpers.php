<?php
require_once __DIR__ . '/../config.php';

/**
 * Build a signed, tamper-proof unsubscribe token for a contact.
 */
function make_unsub_token(int $contactId, string $email): string {
    $payload = $contactId . '|' . $email;
    $sig = hash_hmac('sha256', $payload, APP_SECRET);
    return base64_encode($payload) . '.' . $sig;
}

/**
 * Verify a token produced by make_unsub_token(). Returns contact id or null.
 */
function verify_unsub_token(string $token): ?int {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;
    [$b64, $sig] = $parts;
    $payload = base64_decode($b64, true);
    if ($payload === false) return null;
    [$contactId, $email] = array_pad(explode('|', $payload, 2), 2, '');
    $expected = hash_hmac('sha256', $payload, APP_SECRET);
    if (!hash_equals($expected, $sig)) return null;
    return (int)$contactId;
}

function unsubscribe_link(int $contactId, string $email): string {
    $token = make_unsub_token($contactId, $email);
    return rtrim(APP_URL, '/') . '/unsubscribe.php?token=' . urlencode($token);
}

/**
 * Replace {tag} merge fields in a template with contact data.
 * Supports {name}, {email}, {unsubscribe_link}, and any key inside custom_fields JSON.
 */
function render_template(string $template, array $contact): string {
    $custom = [];
    if (!empty($contact['custom_fields'])) {
        $decoded = json_decode($contact['custom_fields'], true);
        if (is_array($decoded)) $custom = $decoded;
    }

    $tags = array_merge($custom, [
        'name'             => $contact['name'] ?? '',
        'email'            => $contact['email'] ?? '',
        'unsubscribe_link' => unsubscribe_link((int)$contact['id'], $contact['email']),
    ]);

    return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($m) use ($tags) {
        $key = $m[1];
        return array_key_exists($key, $tags) ? htmlspecialchars_decode((string)$tags[$key]) : $m[0];
    }, $template);
}

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        die('Invalid CSRF token. Please go back and try again.');
    }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function flash(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function get_flashes(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
