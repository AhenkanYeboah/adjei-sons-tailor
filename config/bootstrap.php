<?php
/**
 * Bootstrap: session start, CSRF helpers, small shared utilities.
 * Every entry-point PHP file requires this first.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/paystack.php';

define('APP_CURRENCY_SYMBOL', 'GH₵');
define('BASE_URL', ''); // e.g. '/tailor' if not served from webroot

/* ---------------- CSRF ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $submitted = $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $submitted);
}

/* ---------------- Small helpers ---------------- */

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string
{
    return APP_CURRENCY_SYMBOL . number_format($amount, 2);
}

function current_client_id(): ?int
{
    return $_SESSION['client_id'] ?? null;
}

function require_login(): void
{
    if (!current_client_id()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function paystack_is_configured(): bool
{
    return defined('PAYSTACK_SECRET_KEY') && strpos(PAYSTACK_SECRET_KEY, 'CHANGE_ME') !== 0;
}

function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}
