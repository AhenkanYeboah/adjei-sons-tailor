<?php
/**
 * Adjei & Sons — Bespoke Tailoring Platform
 * SINGLE-FILE BUILD
 *
 * Everything — config, services, every page, and the WhatsApp cron
 * worker — lives in this one file.
 */

// These imports are safe even if the Twilio SDK isn't installed
use Twilio\Rest\Client as TwilioClient;
use Twilio\Exceptions\TwilioException;

// ============================================================
// CONFIG — edit the CHANGE_ME_* values below for your environment
// ============================================================

// --- Database (Railway uses MYSQL_URL, these are fallbacks for local) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'bespoke_tailor');
define('DB_USER', 'root');
define('DB_PASS', '');

// --- Paystack ---
define('PAYSTACK_PUBLIC_KEY', 'pk_test_e7d2c45e0fbfe408a6a7cd79f633517490f6db90');
define('PAYSTACK_SECRET_KEY', 'sk_test_cedc917e734f3b0ab562f088c92f38ed3f76fd21');
define('PAYSTACK_CURRENCY', 'GHS');

// --- Twilio (WhatsApp) ---
define('TWILIO_ACCOUNT_SID', 'CHANGE_ME_twilio_account_sid');
define('TWILIO_AUTH_TOKEN', 'CHANGE_ME_twilio_auth_token');
define('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886');
define('TWILIO_CONTENT_SIDS', [
    'status_update'        => null,
    'booking_reminder'     => null,
    'measurement_summary'  => null,
]);
define('WHATSAPP_MAX_ATTEMPTS', 5);
define('WHATSAPP_BATCH_LIMIT', 50);

define('APP_CURRENCY_SYMBOL', 'GH₵');

// ============================================================
// BOOTSTRAP
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // Try Railway's MYSQL_URL first
        $mysqlUrl = getenv('MYSQL_URL');
        
        if ($mysqlUrl) {
            $parsed = parse_url($mysqlUrl);
            $host = $parsed['host'] ?? '127.0.0.1';
            $port = $parsed['port'] ?? 3306;
            $dbname = isset($parsed['path']) ? ltrim($parsed['path'], '/') : 'bespoke_tailor';
            $user = $parsed['user'] ?? 'root';
            $pass = $parsed['pass'] ?? '';
        } else {
            // Local development fallback
            $host = DB_HOST;
            $port = 3306;
            $dbname = DB_NAME;
            $user = DB_USER;
            $pass = DB_PASS;
        }

        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            die('Sorry, something went wrong connecting to the database. Please try again shortly.');
        }
    }

    return $pdo;
}

function paystack_is_configured(): bool
{
    return strpos(PAYSTACK_SECRET_KEY, 'CHANGE_ME') !== 0;
}

function twilio_is_configured(): bool
{
    return TWILIO_ACCOUNT_SID !== 'CHANGE_ME_twilio_account_sid'
        && TWILIO_AUTH_TOKEN !== 'CHANGE_ME_twilio_auth_token';
}

// ============================================================
// CSRF HELPERS
// ============================================================

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

// ============================================================
// HELPERS
// ============================================================

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

function url(string $page = 'index', string $query = ''): string
{
    $self = e($_SERVER['PHP_SELF'] ?? basename(__FILE__));
    $out = $self . '?page=' . rawurlencode($page);
    if ($query !== '') {
        $out .= '&' . $query;
    }
    return $out;
}

function require_login(): void
{
    if (!current_client_id()) {
        header('Location: ' . url('login'));
        exit;
    }
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

// ============================================================
// SERVICES
// ============================================================

class WaitTimeCalculator
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function getSettings(): array
    {
        $stmt = $this->db->query('SELECT * FROM production_settings LIMIT 1');
        $settings = $stmt->fetch();

        if (!$settings) {
            return [
                'days_added_per_n_orders' => 2,
                'orders_per_increment'    => 5,
                'min_wait_days'           => 7,
                'max_wait_days'           => 45,
            ];
        }

        return $settings;
    }

    private function countOpenOrders(): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM orders
             WHERE status NOT IN ('completed', 'cancelled')"
        );
        return (int) $stmt->fetchColumn();
    }

    public function getStandardWaitDays(): int
    {
        $settings = $this->getSettings();
        $openOrders = $this->countOpenOrders();

        $increments = intdiv($openOrders, (int) $settings['orders_per_increment']);
        $days = (int) $settings['min_wait_days'] + ($increments * (int) $settings['days_added_per_n_orders']);

        return min($days, (int) $settings['max_wait_days']);
    }

    public function getWaitDaysForTier(string $tier): int
    {
        return match ($tier) {
            'express_5day' => 5,
            'rush_48hr'    => 2,
            default        => $this->getStandardWaitDays(),
        };
    }

    public function getPromisedDate(string $tier = 'standard'): string
    {
        $days = $this->getWaitDaysForTier($tier);
        $date = new DateTime('today');
        $date->modify("+{$days} days");
        return $date->format('Y-m-d');
    }

    public function getDisplayEstimate(string $tier = 'standard'): string
    {
        $days = $this->getWaitDaysForTier($tier);

        return match ($tier) {
            'express_5day' => '5 business days (Express)',
            'rush_48hr'    => '48 hours (Rush)',
            default        => $days . ' days (Standard)',
        };
    }

    public function getQueueMeterPercent(): float
    {
        $settings = $this->getSettings();
        $max = max(1, (int) $settings['max_wait_days']);
        $days = $this->getStandardWaitDays();
        return round(min(100, ($days / $max) * 100), 1);
    }

    public function getPromisedDateShort(string $tier = 'standard'): string
    {
        $days = $this->getWaitDaysForTier($tier);
        $date = new DateTime('today');
        $date->modify("+{$days} days");
        return $date->format('M j');
    }
}

class ExpressSlotManager
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function currentWeekStart(): string
    {
        $date = new DateTime('monday this week');
        return $date->format('Y-m-d');
    }

    public function getAvailability(string $tier): array
    {
        $stmt = $this->db->prepare(
            'SELECT slots_total, slots_used FROM express_slots
             WHERE week_start_date = :week AND tier = :tier'
        );
        $stmt->execute(['week' => $this->currentWeekStart(), 'tier' => $tier]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['slots_total' => 0, 'slots_used' => 0, 'available' => 0];
        }

        $available = max(0, (int) $row['slots_total'] - (int) $row['slots_used']);
        return [
            'slots_total' => (int) $row['slots_total'],
            'slots_used'  => (int) $row['slots_used'],
            'available'   => $available,
        ];
    }

    public function hasAvailability(string $tier): bool
    {
        return $this->getAvailability($tier)['available'] > 0;
    }

    public function claimSlot(string $tier): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE express_slots
             SET slots_used = slots_used + 1
             WHERE week_start_date = :week AND tier = :tier AND slots_used < slots_total'
        );
        $stmt->execute(['week' => $this->currentWeekStart(), 'tier' => $tier]);

        return $stmt->rowCount() === 1;
    }

    public function setWeeklySlots(string $tier, int $slotsTotal): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO express_slots (week_start_date, tier, slots_total, slots_used)
             VALUES (:week, :tier, :total, 0)
             ON DUPLICATE KEY UPDATE slots_total = :total2'
        );
        $stmt->execute([
            'week'   => $this->currentWeekStart(),
            'tier'   => $tier,
            'total'  => $slotsTotal,
            'total2' => $slotsTotal,
        ]);
    }
}

class OrderStatusService
{
    private PDO $db;

    private const STATUS_MESSAGES = [
        'received'          => 'We\'ve received your order! Our team will begin measuring shortly.',
        'measuring'         => 'Your measurements are being finalized.',
        'cutting'           => 'Fabric cutting has begun on your garment.',
        'sewing'            => 'Your garment is now being sewn by our tailors.',
        'fitting'           => 'Your garment is ready for fitting review.',
        'ready_for_pickup'  => 'Great news — your garment is ready for pickup!',
        'completed'         => 'Order complete. Thank you for choosing us!',
        'cancelled'         => 'Your order has been cancelled. Contact us with any questions.',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function updateStatus(int $orderId, string $newStatus, ?int $staffId = null): void
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('UPDATE orders SET status = :status WHERE id = :id');
            $stmt->execute(['status' => $newStatus, 'id' => $orderId]);

            $stmt = $this->db->prepare(
                'INSERT INTO order_status_log (order_id, status, changed_by_staff_id, whatsapp_sent)
                 VALUES (:order_id, :status, :staff_id, 0)'
            );
            $stmt->execute([
                'order_id'  => $orderId,
                'status'    => $newStatus,
                'staff_id'  => $staffId,
            ]);

            $this->queueNotification($orderId, $newStatus);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('Order status update failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function queueNotification(int $orderId, string $status): void
    {
        $stmt = $this->db->prepare('SELECT client_id FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $clientId = $stmt->fetchColumn();

        if (!$clientId) {
            return;
        }

        $message = self::STATUS_MESSAGES[$status] ?? ('Order status updated: ' . $status);

        $stmt = $this->db->prepare(
            'INSERT INTO whatsapp_notifications (client_id, related_order_id, message_type, message_body, status)
             VALUES (:client_id, :order_id, :type, :body, :status)'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'order_id'  => $orderId,
            'type'      => 'status_update',
            'body'      => $message,
            'status'    => 'queued',
        ]);
    }
}

// ============================================================
// RENDER FUNCTIONS
// ============================================================

function render_header(?string $pageTitle = null): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ? e($pageTitle) . ' — ' : '' ?>Adjei &amp; Sons Bespoke Tailoring</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,500;0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --ink: #14213D;
  --ink-deep: #0D1730;
  --ivory: #F6F1E7;
  --ivory-dim: #EDE6D6;
  --brass: #B98B4E;
  --brass-light: #D6AE73;
  --burgundy: #6E2A34;
  --charcoal: #211E1B;
  --line: rgba(246,241,231,0.18);
  --line-dark: rgba(20,33,61,0.14);
  --good: #7FB88A;
  --warn: #D6AE73;
  --bad: #B9564E;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  background: var(--ivory);
  color: var(--charcoal);
  font-family: 'Inter', sans-serif;
  -webkit-font-smoothing: antialiased;
  line-height: 1.5;
}

h1, h2, h3 { font-family: 'Fraunces', serif; font-weight: 500; letter-spacing: -0.01em; }
a { color: inherit; text-decoration: none; }
.mono { font-family: 'IBM Plex Mono', monospace; letter-spacing: 0.02em; }
.wrap { max-width: 1180px; margin: 0 auto; padding: 0 32px; }
img { max-width: 100%; }

.tape-rule {
  height: 26px;
  background-image: repeating-linear-gradient(to right, currentColor 0, currentColor 1px, transparent 1px, transparent 12px);
  background-size: 100% 10px;
  background-position: top left;
  background-repeat: no-repeat;
  position: relative;
  opacity: 0.35;
}
.tape-rule::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: currentColor; }

header.site { background: var(--ink); color: var(--ivory); position: sticky; top: 0; z-index: 50; border-bottom: 1px solid var(--line); }
.nav-row { display: flex; align-items: center; justify-content: space-between; padding: 20px 0; }
.wordmark { font-family: 'Fraunces', serif; font-size: 22px; font-weight: 600; letter-spacing: 0.01em; }
.wordmark span { color: var(--brass-light); font-style: italic; }
nav.links { display: flex; gap: 36px; font-size: 14px; }
nav.links a { opacity: 0.82; transition: opacity 0.2s; }
nav.links a:hover { opacity: 1; }
.nav-cta { background: var(--brass); color: var(--ink-deep); padding: 10px 20px; border-radius: 2px; font-size: 13.5px; font-weight: 600; letter-spacing: 0.01em; }

.flash-bar { padding: 14px 0; }
.flash { padding: 12px 20px; border-radius: 2px; font-size: 0.9rem; margin-bottom: 8px; }
.flash-success { background: rgba(127,184,138,0.15); color: #3f6b4a; border: 1px solid var(--good); }
.flash-error { background: rgba(185,86,78,0.12); color: var(--bad); border: 1px solid var(--bad); }

.hero { background: var(--ink); color: var(--ivory); position: relative; overflow: hidden; }
.hero-grid { display: grid; grid-template-columns: 1.05fr 0.95fr; align-items: stretch; min-height: 640px; }
.hero-copy { padding: 80px 60px 60px 0; display: flex; flex-direction: column; justify-content: center; max-width: 560px; }
.eyebrow { font-family: 'IBM Plex Mono', monospace; font-size: 12px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--brass-light); margin-bottom: 22px; display: flex; align-items: center; gap: 10px; }
.eyebrow::before { content: ""; width: 28px; height: 1px; background: var(--brass-light); display: inline-block; }
.hero-copy h1 { font-size: 58px; line-height: 1.04; margin-bottom: 26px; }
.hero-copy h1 em { color: var(--brass-light); font-style: italic; font-weight: 500; }
.hero-copy p.lede { font-size: 17px; line-height: 1.6; color: rgba(246,241,231,0.78); max-width: 440px; margin-bottom: 36px; }
.hero-actions { display: flex; gap: 16px; margin-bottom: 44px; flex-wrap: wrap; }
.btn-primary { display: inline-block; background: var(--brass); color: var(--ink-deep); padding: 15px 26px; font-size: 14.5px; font-weight: 600; border: none; border-radius: 2px; letter-spacing: 0.01em; transition: background 0.2s; cursor: pointer; }
.btn-primary:hover { background: var(--brass-light); }
.btn-ghost { display: inline-block; border: 1px solid var(--line); padding: 15px 26px; font-size: 14.5px; font-weight: 500; border-radius: 2px; color: var(--ivory); background: transparent; cursor: pointer; }
.btn-ghost:hover { border-color: var(--brass-light); color: var(--brass-light); }
.btn-dark { display: inline-block; border: 1px solid var(--line-dark); padding: 13px 22px; font-size: 13.5px; font-weight: 500; border-radius: 2px; color: var(--ink); background: transparent; cursor: pointer; }
.btn-dark:hover { border-color: var(--brass); color: var(--brass); }

.wait-chip { display: inline-flex; align-items: center; gap: 14px; border: 1px solid var(--line); padding: 14px 18px; max-width: 460px; }
.wait-chip .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--good); box-shadow: 0 0 0 4px rgba(127,184,138,0.18); flex-shrink: 0; }
.wait-chip .txt { font-size: 13.5px; color: rgba(246,241,231,0.85); }
.wait-chip .txt b { color: var(--ivory); font-family: 'IBM Plex Mono', monospace; font-weight: 500; }

.hero-media { position: relative; }
.hero-media img { width: 100%; height: 100%; object-fit: cover; display: block; filter: saturate(0.92) contrast(1.02); }
.hero-media::after { content: ""; position: absolute; inset: 0; background: linear-gradient(100deg, var(--ink) 0%, rgba(20,33,61,0) 26%); }
.hero-media .credit { position: absolute; bottom: 16px; right: 16px; font-family: 'IBM Plex Mono', monospace; font-size: 10px; color: rgba(246,241,231,0.55); background: rgba(13,23,48,0.55); padding: 4px 8px; }
.hero .tape-rule { color: var(--ivory); }

.steps { background: var(--ivory); padding: 90px 0 70px; }
.section-head { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 50px; flex-wrap: wrap; gap: 16px; }
.section-head h2 { font-size: 34px; }
.section-head .num { font-family: 'IBM Plex Mono', monospace; font-size: 13px; color: var(--brass); opacity: 0.9; }
.steps-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; border-top: 1px solid var(--line-dark); }
.step { padding: 34px 30px 0 0; border-right: 1px solid var(--line-dark); }
.step:last-child { border-right: none; }
.step .step-num { font-family: 'IBM Plex Mono', monospace; font-size: 13px; color: var(--brass); margin-bottom: 18px; display: block; }
.step h3 { font-size: 21px; margin-bottom: 10px; font-weight: 500; }
.step p { font-size: 14.5px; color: #5b564f; max-width: 260px; }

.queue-section { background: var(--ink-deep); color: var(--ivory); padding: 90px 0; }
.queue-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: start; }
.queue-left .eyebrow { color: var(--brass-light); }
.queue-left h2 { font-size: 36px; margin-bottom: 18px; max-width: 420px; }
.queue-left p { color: rgba(246,241,231,0.72); font-size: 15px; max-width: 400px; margin-bottom: 34px; }

.tape-meter { border: 1px solid var(--line); padding: 26px 26px 20px; margin-bottom: 30px; }
.tape-meter .label-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 14px; }
.tape-meter .label-row .lbl { font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(246,241,231,0.55); }
.tape-meter .label-row .val { font-family: 'IBM Plex Mono', monospace; font-size: 22px; color: var(--brass-light); }
.tape-track { position: relative; height: 34px; background-image: repeating-linear-gradient(to right, rgba(246,241,231,0.28) 0, rgba(246,241,231,0.28) 1px, transparent 1px, transparent 10%); border-top: 1px solid rgba(246,241,231,0.3); border-bottom: 1px solid rgba(246,241,231,0.3); margin-bottom: 10px; }
.tape-fill { position: absolute; top: 0; left: 0; bottom: 0; background: linear-gradient(90deg, var(--brass), var(--brass-light)); }
.tape-track .marker { position: absolute; top: -20px; transform: translateX(-50%); font-family: 'IBM Plex Mono', monospace; font-size: 10px; color: rgba(246,241,231,0.5); }
.tape-meter .footnote { font-size: 12.5px; color: rgba(246,241,231,0.5); }

.express-toggle { display: flex; gap: 10px; margin-bottom: 8px; }
.express-option { flex: 1; border: 1px solid var(--line); padding: 16px 14px; cursor: pointer; transition: border-color 0.2s, background 0.2s; text-align: left; background: transparent; color: inherit; font-family: inherit; }
.express-option.active { border-color: var(--brass-light); background: rgba(185,139,78,0.08); }
.express-option:disabled { opacity: 0.4; cursor: not-allowed; }
.express-option .tier { font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.express-option .days { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: rgba(246,241,231,0.55); margin-bottom: 10px; }
.express-option .price { font-family: 'IBM Plex Mono', monospace; font-size: 16px; color: var(--brass-light); }

.queue-right { position: relative; }
.queue-right img { width: 100%; height: 560px; object-fit: cover; filter: saturate(0.9); display: block; }
.queue-right .caption-box { position: absolute; left: 0; right: 0; bottom: 0; background: linear-gradient(0deg, rgba(13,23,48,0.92), transparent); padding: 30px 26px 22px; }
.queue-right .caption-box p { font-family: 'Fraunces', serif; font-style: italic; font-size: 18px; color: var(--ivory); max-width: 320px; }
.queue-right .credit { position: absolute; top: 14px; right: 14px; font-family: 'IBM Plex Mono', monospace; font-size: 10px; color: rgba(246,241,231,0.6); background: rgba(13,23,48,0.5); padding: 4px 8px; }

.configurator { background: var(--ivory); padding: 90px 0; }
.fabric-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 40px; }
.fabric-card { background: white; border: 1px solid var(--line-dark); cursor: pointer; transition: border-color 0.15s; }
.fabric-card:hover, .fabric-card.selected { border-color: var(--brass); }
.fabric-card .swatch-img { width: 100%; aspect-ratio: 1/1; object-fit: cover; display: block; }
.fabric-card .fc-info { padding: 14px 16px 18px; }
.fabric-card .fc-name { font-size: 14.5px; font-weight: 600; margin-bottom: 4px; }
.fabric-card .fc-meta { font-family: 'IBM Plex Mono', monospace; font-size: 11.5px; color: #8a8478; }
.config-panel { background: white; border: 1px solid var(--line-dark); padding: 34px; display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 28px; align-items: end; }
.config-field label { display: block; font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #8a8478; margin-bottom: 10px; }
.config-field select, .config-field input { width: 100%; padding: 12px 12px; border: 1px solid var(--line-dark); background: var(--ivory); font-family: 'Inter', sans-serif; font-size: 14px; color: var(--charcoal); }
.config-price { text-align: right; }
.config-price .lbl { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: #8a8478; margin-bottom: 6px; }
.config-price .amt { font-family: 'IBM Plex Mono', monospace; font-size: 26px; color: var(--ink); }

.gallery { background: var(--ivory-dim); padding: 90px 0; }
.gallery-filters { display: flex; gap: 10px; margin-bottom: 40px; flex-wrap: wrap; }
.gf-btn { font-family: 'IBM Plex Mono', monospace; font-size: 12px; padding: 9px 16px; border: 1px solid var(--line-dark); color: #5b564f; cursor: pointer; background: transparent; }
.gf-btn.active { background: var(--ink); color: var(--ivory); border-color: var(--ink); }
.gallery-grid { display: grid; grid-template-columns: 1.3fr 1fr 1fr; grid-template-rows: 260px 260px; gap: 16px; }
.g-item { position: relative; overflow: hidden; }
.g-item.g-first { grid-row: span 2; }
.g-item img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.4s; }
.g-item:hover img { transform: scale(1.04); }
.g-item .g-tag { position: absolute; top: 14px; left: 14px; font-family: 'IBM Plex Mono', monospace; font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase; background: rgba(20,33,61,0.85); color: var(--ivory); padding: 6px 10px; }

.cta { background: var(--burgundy); color: var(--ivory); padding: 80px 0; text-align: center; }
.cta h2 { font-size: 38px; margin-bottom: 16px; }
.cta p { color: rgba(246,241,231,0.8); margin-bottom: 32px; font-size: 15.5px; }
.cta .btn-primary { background: var(--brass); color: var(--ink-deep); }

footer.site { background: var(--ink-deep); color: rgba(246,241,231,0.55); padding: 40px 0; font-size: 13px; }
footer.site .wrap { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 12px; }

.page-section { padding: 70px 0; }
.form-card { background: #fff; border: 1px solid var(--line-dark); padding: 40px; max-width: 460px; margin: 0 auto; }
.form-card h2 { font-size: 1.7rem; margin-bottom: 24px; }
.form-card label { display: block; font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #8a8478; margin: 18px 0 8px; }
.form-card input, .form-card select, .form-card textarea { width: 100%; padding: 12px; border: 1px solid var(--line-dark); background: var(--ivory); font-family: 'Inter', sans-serif; font-size: 15px; }
.form-card input:focus, .form-card select:focus, .form-card textarea:focus { outline: none; border-color: var(--brass); }
.form-actions { margin-top: 26px; }
.form-note { font-size: 0.85rem; color: #8a8478; margin-top: 16px; }

.stock-badge { display: inline-block; font-family: 'IBM Plex Mono', monospace; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.06em; padding: 3px 10px; margin-bottom: 8px; }
.stock-in { background: rgba(127,184,138,0.15); color: #3f6b4a; }
.stock-low { background: rgba(214,174,115,0.2); color: #8a6414; }
.stock-out { background: rgba(185,86,78,0.12); color: var(--bad); }

.status-track { display: flex; justify-content: space-between; margin: 40px 0; position: relative; }
.status-track::before { content: ''; position: absolute; top: 14px; left: 0; right: 0; height: 2px; background: var(--line-dark); z-index: 0; }
.status-step { position: relative; z-index: 1; text-align: center; flex: 1; }
.status-dot { width: 26px; height: 26px; border-radius: 50%; background: #fff; border: 2px solid var(--line-dark); margin: 0 auto 8px; }
.status-step.done .status-dot { background: var(--brass); border-color: var(--brass); }
.status-step.current .status-dot { border-color: var(--brass); box-shadow: 0 0 0 4px rgba(185,139,78,0.18); }
.status-step .label { font-family: 'IBM Plex Mono', monospace; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.04em; color: #6b6255; }

table.order-table { width: 100%; border-collapse: collapse; background: #fff; }
table.order-table th, table.order-table td { padding: 12px 16px; border-bottom: 1px solid var(--line-dark); text-align: left; font-size: 0.92rem; }
table.order-table th { background: var(--ink); color: var(--ivory); text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.05em; font-family: 'IBM Plex Mono', monospace; }

@media (max-width: 900px) {
  .hero-grid { grid-template-columns: 1fr; }
  .hero-media { height: 340px; order: -1; }
  .hero-copy { padding: 50px 24px; max-width: none; }
  .hero-copy h1 { font-size: 38px; }
  .steps-row { grid-template-columns: 1fr; }
  .step { border-right: none; border-bottom: 1px solid var(--line-dark); padding-bottom: 30px; margin-bottom: 30px; }
  .queue-inner { grid-template-columns: 1fr; }
  .queue-right img { height: 320px; }
  .fabric-grid { grid-template-columns: repeat(2,1fr); }
  .config-panel { grid-template-columns: 1fr; }
  .gallery-grid { grid-template-columns: 1fr 1fr; grid-template-rows: 200px 200px 200px; }
  .g-item.g-first { grid-row: span 1; grid-column: span 2; }
  .wrap { padding: 0 20px; }
  nav.links { display: none; }
}
</style>
</head>
<body>

<header class="site">
  <div class="wrap nav-row">
    <a href="<?= url('index') ?>" class="wordmark">Adjei &amp; <span>Sons</span></a>
    <nav class="links">
      <a href="<?= url('configurator') ?>">Design</a>
      <a href="<?= url('index') ?>#queue">Wait Time</a>
      <a href="<?= url('gallery') ?>">Gallery</a>
      <?php if (current_client_id()): ?>
        <a href="<?= url('dashboard') ?>">My Account</a>
      <?php else: ?>
        <a href="<?= url('login') ?>">My Account</a>
      <?php endif; ?>
    </nav>
    <a href="<?= url('booking') ?>" class="nav-cta">Book Consultation</a>
  </div>
</header>

<?php
$flashSuccess = flash('success');
$flashError = flash('error');
?>
<?php if ($flashSuccess || $flashError): ?>
<div class="flash-bar">
  <div class="wrap">
    <?php if ($flashSuccess): ?><div class="flash flash-success"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="flash flash-error"><?= e($flashError) ?></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<main>
<?php
}

function render_footer(): void
{
    ?>
</main>

<footer class="site">
  <div class="wrap">
    <span>&copy; <?= date('Y') ?> Adjei &amp; Sons Bespoke Tailoring, Accra</span>
    <span>Shipping to UK &middot; US &middot; Canada &middot; Worldwide</span>
  </div>
</footer>

</body>
</html>
<?php
}

// ============================================================
// PAGE FUNCTIONS
// ============================================================

function page_index(): void
{
    $db = getDB();
    $waitCalc = new WaitTimeCalculator($db);
    $slotMgr = new ExpressSlotManager($db);

    $standardDays   = $waitCalc->getStandardWaitDays();
    $expressDays    = $waitCalc->getWaitDaysForTier('express_5day');
    $rushDays       = $waitCalc->getWaitDaysForTier('rush_48hr');
    $queueMeterPct  = $waitCalc->getQueueMeterPercent();
    $pickupShort    = $waitCalc->getPromisedDateShort('standard');

    $expressAvail = $slotMgr->getAvailability('express_5day');
    $rushAvail    = $slotMgr->getAvailability('rush_48hr');

    $refGarment = $db->query(
        "SELECT * FROM garment_types WHERE is_active = 1 ORDER BY base_price ASC LIMIT 1"
    )->fetch();
    $refBase = $refGarment ? (float) $refGarment['base_price'] : 1200.00;

    $standardPrice = $refBase;
    $expressPrice  = round($refBase * 1.25, 2);
    $rushPrice     = round($refBase * 1.50, 2);

    $fabrics = $db->query(
        "SELECT * FROM fabrics WHERE stock_status != 'out_of_stock' ORDER BY id ASC LIMIT 4"
    )->fetchAll();

    $galleryItems = $db->query(
        "SELECT * FROM gallery_items ORDER BY is_featured DESC, created_at DESC LIMIT 5"
    )->fetchAll();

    render_header('Bespoke, On Your Time');
    ?>

<section class="hero">
  <div class="hero-grid wrap" style="padding-left:0; padding-right:0; max-width:1180px; margin:0 auto; display:grid;">
    <div class="hero-copy" style="padding-left:32px;">
      <div class="eyebrow">Accra &middot; Est. 2014</div>
      <h1>Bespoke,<br><em>on your time.</em></h1>
      <p class="lede">Every stitch cut to your measure &mdash; and a schedule you can see before you commit. No shop visit required.</p>
      <div class="wait-chip">
        <span class="dot"></span>
        <span class="txt">Current wait time <b><?= (int) $standardDays ?> days</b> &nbsp;&middot;&nbsp; Need it sooner? <b>Express from <?= (int) $expressDays ?> days</b></span>
      </div>
      <div class="hero-actions" style="margin-top:32px;">
        <a href="<?= url('configurator') ?>" class="btn-primary">Start Your Design</a>
        <a href="<?= url('booking') ?>" class="btn-ghost">Book Free Consultation</a>
      </div>
    </div>
    <div class="hero-media">
      <img src="https://images.unsplash.com/photo-1584184924103-e310d9dc82fc?w=900&q=80&fm=jpg&fit=crop" alt="Client in tailored black suit">
      <span class="credit">Photo &middot; Unsplash</span>
    </div>
  </div>
  <div class="tape-rule"></div>
</section>

<section class="steps">
  <div class="wrap">
    <div class="section-head">
      <h2>How it works</h2>
      <span class="num">01 &mdash; 03</span>
    </div>
    <div class="steps-row">
      <div class="step">
        <span class="step-num">01</span>
        <h3>Design</h3>
        <p>Choose your cut, fabric, lining and buttons. Watch the price update as you go, and save your design to revisit later.</p>
      </div>
      <div class="step">
        <span class="step-num">02</span>
        <h3>Measure</h3>
        <p>Book a guided video call. A tailor walks you through it step by step &mdash; no shop visit, no guesswork.</p>
      </div>
      <div class="step">
        <span class="step-num">03</span>
        <h3>Pick a date</h3>
        <p>See your exact pickup date before you pay. Choose Standard, Express or Rush depending on how soon you need it.</p>
      </div>
    </div>
  </div>
</section>

<section class="queue-section" id="queue">
  <div class="wrap queue-inner">
    <div class="queue-left">
      <div class="eyebrow">Live &middot; Updates Automatically</div>
      <h2>We show you the wait before you commit.</h2>
      <p>Most tailors keep this to themselves. Our production calendar updates in real time with every order in the queue &mdash; so you know exactly when to expect your piece.</p>

      <div class="tape-meter">
        <div class="label-row">
          <span class="lbl">Current Queue Load</span>
          <span class="val"><?= (int) $standardDays ?> days</span>
        </div>
        <div class="tape-track">
          <div class="tape-fill" style="width:<?= e((string) $queueMeterPct) ?>%;"></div>
          <span class="marker" style="left:0%;">0</span>
          <span class="marker" style="left:50%;">21d</span>
          <span class="marker" style="left:100%;">45d</span>
        </div>
        <p class="footnote">Order today &rarr; estimated pickup <strong style="color:var(--brass-light)"><?= e($pickupShort) ?></strong></p>
      </div>

      <label style="font-family:'IBM Plex Mono',monospace; font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:rgba(246,241,231,0.55); display:block; margin-bottom:12px;">Choose Your Timeline</label>
      <div class="express-toggle">
        <button type="button" class="express-option active" data-tier="standard">
          <div class="tier">Standard</div>
          <div class="days"><?= (int) $standardDays ?> days</div>
          <div class="price"><?= money($standardPrice) ?></div>
        </button>
        <button type="button" class="express-option" data-tier="express" <?= $expressAvail['available'] <= 0 ? 'disabled' : '' ?>>
          <div class="tier">Express</div>
          <div class="days"><?= (int) $expressDays ?> days</div>
          <div class="price"><?= money($expressPrice) ?></div>
        </button>
        <button type="button" class="express-option" data-tier="rush" <?= $rushAvail['available'] <= 0 ? 'disabled' : '' ?>>
          <div class="tier">Rush</div>
          <div class="days">48 hrs</div>
          <div class="price"><?= money($rushPrice) ?></div>
        </button>
      </div>
      <p class="footnote" style="margin-top:10px;">
        <?= (int) $expressAvail['available'] ?> Express slot<?= $expressAvail['available'] === 1 ? '' : 's' ?> and
        <?= (int) $rushAvail['available'] ?> Rush slot<?= $rushAvail['available'] === 1 ? '' : 's' ?> left this week.
      </p>
    </div>

    <div class="queue-right">
      <img src="https://images.unsplash.com/photo-1633655442356-ab2dbc69c772?w=800&q=80&fm=jpg&fit=crop" alt="Tailor working on fabric">
      <span class="credit">Photo &middot; Unsplash</span>
      <div class="caption-box">
        <p>&ldquo;We control how many express slots open each week &mdash; so quality never slips for speed.&rdquo;</p>
      </div>
    </div>
  </div>
</section>

<section class="configurator" id="configurator">
  <div class="wrap">
    <div class="section-head">
      <h2>Fabric &amp; style</h2>
      <span class="num">Free swatches available</span>
    </div>
    <div class="fabric-grid">
      <?php foreach ($fabrics as $f): ?>
      <a href="<?= url('configurator', 'fabric_id=' . (int) $f['id']) ?>" class="fabric-card">
        <img class="swatch-img" src="<?= e($f['image_url']) ?>" alt="<?= e($f['name']) ?>">
        <div class="fc-info">
          <div class="fc-name"><?= e($f['name']) ?></div>
          <div class="fc-meta">
            <?= $f['price_modifier'] > 0 ? '+' . money($f['price_modifier']) : money(0) ?> &middot;
            <?= $f['stock_status'] === 'in_stock' ? 'In Stock' : 'Low Stock' ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <div class="config-panel">
      <div class="config-field">
        <label>Cut</label>
        <select disabled>
          <?php foreach ($db->query("SELECT name FROM garment_types WHERE is_active = 1 LIMIT 5")->fetchAll() as $g): ?>
            <option><?= e($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="config-field">
        <label>Lining</label>
        <select disabled>
          <?php foreach ($db->query("SELECT name FROM style_options WHERE category = 'lining' AND is_active = 1")->fetchAll() as $opt): ?>
            <option><?= e($opt['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="config-field">
        <label>Monogram</label>
        <select disabled>
          <option>None</option>
          <?php foreach ($db->query("SELECT name FROM style_options WHERE category = 'monogram' AND is_active = 1")->fetchAll() as $opt): ?>
            <option><?= e($opt['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="config-price">
        <div class="lbl">Starting From</div>
        <div class="amt"><?= money($refBase) ?></div>
      </div>
    </div>
    <p style="text-align:center; margin-top:30px;">
      <a href="<?= url('configurator') ?>" class="btn-dark">Open Full Configurator</a>
    </p>
  </div>
</section>

<section class="gallery" id="gallery">
  <div class="wrap">
    <div class="section-head">
      <h2>Client gallery</h2>
      <span class="num">By occasion</span>
    </div>
    <div class="gallery-filters">
      <span class="gf-btn active">All</span>
      <?php
      $seenCats = [];
      foreach ($galleryItems as $gi) {
          $cat = ucfirst($gi['occasion_category']);
          if (!in_array($cat, $seenCats, true)) {
              $seenCats[] = $cat;
              echo '<span class="gf-btn">' . e($cat) . '</span>';
          }
      }
      ?>
    </div>
    <div class="gallery-grid">
      <?php foreach ($galleryItems as $i => $item): ?>
      <div class="g-item<?= $i === 0 ? ' g-first' : '' ?>">
        <span class="g-tag"><?= e(ucfirst($item['occasion_category'])) ?></span>
        <img src="<?= e($item['image_url']) ?>" alt="<?= e(ucfirst($item['occasion_category'])) ?> look">
      </div>
      <?php endforeach; ?>
    </div>
    <p style="text-align:center; margin-top:30px;">
      <a href="<?= url('gallery') ?>" class="btn-dark">View Full Gallery</a>
    </p>
  </div>
</section>

<section class="cta">
  <div class="wrap">
    <h2>Ready when you are.</h2>
    <p>Book a free consultation on WhatsApp &mdash; no shop visit needed to get started.</p>
    <a href="<?= url('booking') ?>" class="btn-primary">Book Free Consultation on WhatsApp</a>
  </div>
</section>

<script>
  document.querySelectorAll('.express-option').forEach(function (el) {
    el.addEventListener('click', function () {
      if (el.disabled) return;
      document.querySelectorAll('.express-option').forEach(function (o) { o.classList.remove('active'); });
      el.classList.add('active');
    });
  });
  document.querySelectorAll('.gf-btn').forEach(function (el) {
    el.addEventListener('click', function () {
      document.querySelectorAll('.gf-btn').forEach(function (o) { o.classList.remove('active'); });
      el.classList.add('active');
    });
  });
</script>

<?php
    render_footer();
}

// ============================================================
// PAGE: LOGIN
// ============================================================

function page_login(): void
{
    $db = getDB();
    $error = null;

    $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? [];
    $_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'], fn($t) => $t > time() - 600);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (count($_SESSION['login_attempts']) >= 8) {
            $error = 'Too many attempts. Please wait a few minutes and try again.';
        } elseif (!csrf_verify()) {
            $error = 'Your session expired. Please try again.';
        } else {
            $phone = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';

            $stmt = $db->prepare('SELECT id, password_hash FROM clients WHERE phone = :phone');
            $stmt->execute(['phone' => $phone]);
            $client = $stmt->fetch();

            $_SESSION['login_attempts'][] = time();

            if ($client && password_verify($password, $client['password_hash'])) {
                unset($_SESSION['login_attempts']);
                session_regenerate_id(true);
                $_SESSION['client_id'] = (int) $client['id'];
                header('Location: ' . url('dashboard'));
                exit;
            }

            $error = 'Incorrect phone number or password.';
        }
    }

    render_header('Log In');
    ?>
<section class="page-section">
    <div class="wrap">
        <div class="form-card">
            <h2>Welcome Back</h2>
            <?php if ($error): ?>
                <div class="flash flash-error"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= url('login') ?>">
                <?= csrf_field() ?>
                <label for="phone">Phone Number</label>
                <input type="text" id="phone" name="phone" required autofocus>

                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Log In</button>
                </div>
                <p class="form-note">New here? <a href="<?= url('register') ?>">Create an account</a></p>
            </form>
        </div>
    </div>
</section>
<?php
    render_footer();
}

// ============================================================
// PAGE: REGISTER
// ============================================================

function page_register(): void
{
    $db = getDB();
    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $phone    = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($fullName === '' || $phone === '' || $password === '') {
                $errors[] = 'Name, phone, and password are required.';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }

            if (empty($errors)) {
                $stmt = $db->prepare('SELECT id FROM clients WHERE phone = :phone');
                $stmt->execute(['phone' => $phone]);
                if ($stmt->fetch()) {
                    $errors[] = 'An account with that phone number already exists.';
                }
            }

            if (empty($errors)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare(
                    'INSERT INTO clients (full_name, email, phone, password_hash, country, timezone, preferred_currency)
                     VALUES (:name, :email, :phone, :hash, :country, :tz, :currency)'
                );
                $stmt->execute([
                    'name'     => $fullName,
                    'email'    => $email !== '' ? $email : null,
                    'phone'    => $phone,
                    'hash'     => $hash,
                    'country'  => 'Ghana',
                    'tz'       => 'Africa/Accra',
                    'currency' => 'GHS',
                ]);

                $_SESSION['client_id'] = (int) $db->lastInsertId();
                flash('success', 'Welcome! Your account has been created.');
                header('Location: ' . url('dashboard'));
                exit;
            }
        }
    }

    render_header('Create Account');
    ?>
<section class="page-section">
    <div class="wrap">
        <div class="form-card">
            <h2>Create Your Account</h2>
            <?php foreach ($errors as $err): ?>
                <div class="flash flash-error"><?= e($err) ?></div>
            <?php endforeach; ?>
            <form method="post" action="<?= url('register') ?>">
                <?= csrf_field() ?>
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>">

                <label for="phone">Phone (WhatsApp number)</label>
                <input type="text" id="phone" name="phone" placeholder="+233..." required value="<?= e($_POST['phone'] ?? '') ?>">

                <label for="email">Email (optional)</label>
                <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>">

                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8">

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Create Account</button>
                </div>
                <p class="form-note">Already have an account? <a href="<?= url('login') ?>">Log in</a></p>
            </form>
        </div>
    </div>
</section>
<?php
    render_footer();
}

// ============================================================
// PAGE: LOGOUT
// ============================================================

function page_logout(): void
{
    $_SESSION = [];
    session_destroy();
    header('Location: ' . url('index'));
    exit;
}

// ============================================================
// PAGE: GALLERY
// ============================================================

function page_gallery(): void
{
    $db = getDB();

    $validCategories = ['wedding', 'corporate', 'graduation', 'traditional', 'casual'];
    $filter = $_GET['occasion'] ?? '';
    if (!in_array($filter, $validCategories, true)) {
        $filter = '';
    }

    if ($filter !== '') {
        $stmt = $db->prepare('SELECT * FROM gallery_items WHERE occasion_category = :cat ORDER BY is_featured DESC, created_at DESC');
        $stmt->execute(['cat' => $filter]);
    } else {
        $stmt = $db->query('SELECT * FROM gallery_items ORDER BY is_featured DESC, created_at DESC');
    }
    $items = $stmt->fetchAll();

    render_header('Client Gallery');
    ?>

<section class="gallery" style="padding-top:50px;">
  <div class="wrap">
    <div class="section-head">
      <h2>Client gallery</h2>
      <span class="num"><?= count($items) ?> looks</span>
    </div>
    <div class="gallery-filters">
      <a href="<?= url('gallery') ?>" class="gf-btn<?= $filter === '' ? ' active' : '' ?>">All</a>
      <?php foreach ($validCategories as $cat): ?>
        <a href="<?= url('gallery', 'occasion=' . rawurlencode($cat)) ?>" class="gf-btn<?= $filter === $cat ? ' active' : '' ?>"><?= e(ucfirst($cat)) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($items)): ?>
      <p style="color:#5b564f;">No looks in this category yet — check back soon.</p>
    <?php else: ?>
    <div class="fabric-grid" style="grid-template-columns: repeat(3, 1fr);">
      <?php foreach ($items as $item): ?>
      <div class="fabric-card" style="cursor:default;">
        <img class="swatch-img" style="aspect-ratio:4/3;" src="<?= e($item['image_url']) ?>" alt="<?= e(ucfirst($item['occasion_category'])) ?> look">
        <div class="fc-info">
          <div class="fc-name"><?= e(ucfirst($item['occasion_category'])) ?><?= $item['client_name_display'] ? ' — ' . e($item['client_name_display']) : '' ?></div>
          <?php if ($item['client_story']): ?>
            <p style="font-size:13.5px; color:#5b564f; margin-top:6px;"><?= e($item['client_story']) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php
    render_footer();
}

// ============================================================
// PAGE: BOOKING
// ============================================================

function page_booking(): void
{
    $db = getDB();
    $errors = [];
    $success = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            $bookingType = $_POST['booking_type'] ?? '';
            $date = $_POST['date'] ?? '';
            $time = $_POST['time'] ?? '';
            $fullName = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            $validTypes = ['video_measurement', 'consultation', 'in_person_fitting'];
            if (!in_array($bookingType, $validTypes, true)) {
                $errors[] = 'Please choose a valid booking type.';
            }
            if ($fullName === '' || $phone === '') {
                $errors[] = 'Name and phone number are required.';
            }

            $slotDateTime = null;
            if ($date !== '' && $time !== '') {
                $slotDateTime = DateTime::createFromFormat('Y-m-d H:i', "$date $time");
                if (!$slotDateTime || $slotDateTime < new DateTime('now')) {
                    $errors[] = 'Please choose a valid future date and time.';
                }
            } else {
                $errors[] = 'Please choose a date and time.';
            }

            if (empty($errors)) {
                $clientId = current_client_id();

                if (!$clientId) {
                    $stmt = $db->prepare('SELECT id FROM clients WHERE phone = :phone');
                    $stmt->execute(['phone' => $phone]);
                    $existing = $stmt->fetch();

                    if ($existing) {
                        $clientId = (int) $existing['id'];
                    } else {
                        $randomPass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                        $stmt = $db->prepare(
                            'INSERT INTO clients (full_name, phone, password_hash, country, timezone, preferred_currency)
                             VALUES (:name, :phone, :hash, :country, :tz, :currency)'
                        );
                        $stmt->execute([
                            'name'     => $fullName,
                            'phone'    => $phone,
                            'hash'     => $randomPass,
                            'country'  => 'Ghana',
                            'tz'       => 'Africa/Accra',
                            'currency' => 'GHS',
                        ]);
                        $clientId = (int) $db->lastInsertId();
                    }
                }

                $stmt = $db->prepare(
                    'INSERT INTO bookings (client_id, booking_type, slot_datetime_utc, duration_minutes, status)
                     VALUES (:client_id, :type, :slot, :duration, :status)'
                );
                $stmt->execute([
                    'client_id' => $clientId,
                    'type'      => $bookingType,
                    'slot'      => $slotDateTime->format('Y-m-d H:i:s'),
                    'duration'  => $bookingType === 'video_measurement' ? 30 : 20,
                    'status'    => 'scheduled',
                ]);

                $stmt = $db->prepare(
                    'INSERT INTO whatsapp_notifications (client_id, related_booking_id, message_type, message_body, status)
                     VALUES (:client_id, :booking_id, :type, :body, :status)'
                );
                $stmt->execute([
                    'client_id'  => $clientId,
                    'booking_id' => (int) $db->lastInsertId(),
                    'type'       => 'booking_reminder',
                    'body'       => 'Your ' . str_replace('_', ' ', $bookingType) . ' is booked for ' . $slotDateTime->format('D, M j \a\t g:i A') . '. We\'ll send a reminder beforehand.',
                    'status'     => 'queued',
                ]);

                $success = true;
            }
        }
    }

    render_header('Book a Consultation');
    ?>

<section class="page-section">
  <div class="wrap">
    <div class="form-card" style="max-width:560px;">
      <h2>Book a Free Consultation</h2>
      <p style="color:#5b564f; font-size:14.5px; margin-bottom:10px;">Guided video call, in-person fitting, or a quick chat about your design — no shop visit required to get started.</p>

      <?php if ($success): ?>
        <div class="flash flash-success">Booked! We'll send a WhatsApp confirmation shortly.</div>
      <?php else: ?>
        <?php foreach ($errors as $err): ?>
          <div class="flash flash-error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= url('booking') ?>">
          <?= csrf_field() ?>

          <label for="booking_type">Type of Appointment</label>
          <select id="booking_type" name="booking_type" required>
            <option value="consultation" <?= ($_POST['booking_type'] ?? '') === 'consultation' ? 'selected' : '' ?>>Free Consultation</option>
            <option value="video_measurement" <?= ($_POST['booking_type'] ?? '') === 'video_measurement' ? 'selected' : '' ?>>Video Measurement Call</option>
            <option value="in_person_fitting" <?= ($_POST['booking_type'] ?? '') === 'in_person_fitting' ? 'selected' : '' ?>>In-Person Fitting (Accra studio)</option>
          </select>

          <label for="full_name">Full Name</label>
          <input type="text" id="full_name" name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>">

          <label for="phone">WhatsApp Number</label>
          <input type="text" id="phone" name="phone" placeholder="+233..." required value="<?= e($_POST['phone'] ?? '') ?>">

          <label for="date">Date</label>
          <input type="date" id="date" name="date" required min="<?= date('Y-m-d') ?>" value="<?= e($_POST['date'] ?? '') ?>">

          <label for="time">Time</label>
          <input type="time" id="time" name="time" required value="<?= e($_POST['time'] ?? '') ?>">

          <div class="form-actions">
            <button type="submit" class="btn-primary">Confirm Booking</button>
          </div>
          <p class="form-note">Times shown are Africa/Accra (GMT). We'll confirm by WhatsApp.</p>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php
    render_footer();
}

// ============================================================
// PAGE: CONFIGURATOR
// ============================================================

function page_configurator(): void
{
    $db = getDB();

    $garments = $db->query('SELECT * FROM garment_types WHERE is_active = 1 ORDER BY base_price ASC')->fetchAll();
    $fabrics = $db->query("SELECT * FROM fabrics WHERE stock_status != 'out_of_stock' ORDER BY name ASC")->fetchAll();
    $styleOptions = $db->query('SELECT * FROM style_options WHERE is_active = 1 ORDER BY category, price_modifier ASC')->fetchAll();

    $byCategory = [];
    foreach ($styleOptions as $opt) {
        $byCategory[$opt['category']][] = $opt;
    }

    $selectedGarmentId = (int) ($_GET['garment_id'] ?? ($garments[0]['id'] ?? 0));
    $selectedFabricId = (int) ($_GET['fabric_id'] ?? ($fabrics[0]['id'] ?? 0));

    render_header('Design Your Garment');
    ?>

<section class="configurator" style="padding-top:50px;">
  <div class="wrap">
    <div class="section-head">
      <h2>Design your garment</h2>
      <span class="num">Price updates live</span>
    </div>

    <form id="configForm" method="post" action="<?= url('checkout') ?>">
      <?= csrf_field() ?>

      <div class="config-field" style="margin-bottom:30px; max-width:340px;">
        <label>Garment</label>
        <select name="garment_type_id" id="garmentSelect">
          <?php foreach ($garments as $g): ?>
            <option value="<?= (int) $g['id'] ?>"
                    data-price="<?= e($g['base_price']) ?>"
                    data-days="<?= (int) $g['base_production_days'] ?>"
                    <?= $g['id'] == $selectedGarmentId ? 'selected' : '' ?>>
              <?= e($g['name']) ?> — <?= money($g['base_price']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fabric-grid">
        <?php foreach ($fabrics as $f): ?>
        <label class="fabric-card<?= $f['id'] == $selectedFabricId ? ' selected' : '' ?>">
          <input type="radio" name="fabric_id" value="<?= (int) $f['id'] ?>"
                 data-price="<?= e($f['price_modifier']) ?>"
                 style="position:absolute; opacity:0;"
                 <?= $f['id'] == $selectedFabricId ? 'checked' : '' ?>>
          <img class="swatch-img" src="<?= e($f['image_url']) ?>" alt="<?= e($f['name']) ?>">
          <div class="fc-info">
            <div class="fc-name"><?= e($f['name']) ?></div>
            <div class="fc-meta">
              <?= $f['price_modifier'] > 0 ? '+' . money($f['price_modifier']) : money(0) ?> &middot;
              <?= $f['stock_status'] === 'in_stock' ? 'In Stock' : 'Low Stock' ?>
            </div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="config-panel" style="grid-template-columns: 1fr 1fr 1fr 1fr; margin-bottom:30px;">
        <?php foreach (['cut', 'lining', 'buttons', 'collar', 'cuff', 'monogram'] as $cat): ?>
          <?php if (!empty($byCategory[$cat])): ?>
          <div class="config-field">
            <label><?= e(ucfirst($cat)) ?></label>
            <select name="style_<?= e($cat) ?>" class="styleSelect">
              <?php foreach ($byCategory[$cat] as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>" data-price="<?= e($opt['price_modifier']) ?>">
                  <?= e($opt['name']) ?><?= $opt['price_modifier'] > 0 ? ' (+' . money($opt['price_modifier']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <div class="config-panel" style="grid-template-columns: 1fr 1fr auto;">
        <div class="config-field">
          <label>Monogram Text (optional)</label>
          <input type="text" name="monogram_text" maxlength="3" placeholder="e.g. K.A.O">
        </div>
        <div class="config-field">
          <label>Delivery Tier</label>
          <select name="order_tier">
            <option value="standard">Standard</option>
            <option value="express_5day">Express (5 days)</option>
            <option value="rush_48hr">Rush (48 hrs)</option>
          </select>
        </div>
        <div class="config-price">
          <div class="lbl">Live Price</div>
          <div class="amt" id="livePrice"><?= money($garments[0]['base_price'] ?? 0) ?></div>
        </div>
      </div>

      <p style="text-align:center; margin-top:36px;">
        <?php if (current_client_id()): ?>
          <button type="submit" class="btn-primary">Continue to Checkout</button>
        <?php else: ?>
          <a href="<?= url('login') ?>" class="btn-primary">Log In to Continue</a>
        <?php endif; ?>
      </p>
    </form>
  </div>
</section>

<script>
(function () {
  const garmentSelect = document.getElementById('garmentSelect');
  const fabricRadios = document.querySelectorAll('input[name="fabric_id"]');
  const styleSelects = document.querySelectorAll('.styleSelect');
  const liveEl = document.getElementById('livePrice');

  function currentPrice() {
    let total = parseFloat(garmentSelect.selectedOptions[0]?.dataset.price || 0);
    document.querySelectorAll('input[name="fabric_id"]:checked').forEach(function (r) {
      total += parseFloat(r.dataset.price || 0);
    });
    styleSelects.forEach(function (sel) {
      total += parseFloat(sel.selectedOptions[0]?.dataset.price || 0);
    });
    return total;
  }

  function formatMoney(n) {
    return 'GH₵' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function recalc() {
    liveEl.textContent = formatMoney(currentPrice());
    document.querySelectorAll('.fabric-card').forEach(function (card) {
      const radio = card.querySelector('input[type="radio"]');
      card.classList.toggle('selected', radio.checked);
    });
  }

  garmentSelect.addEventListener('change', recalc);
  fabricRadios.forEach(function (r) { r.addEventListener('change', recalc); });
  styleSelects.forEach(function (s) { s.addEventListener('change', recalc); });
  document.querySelectorAll('.fabric-card').forEach(function (card) {
    card.addEventListener('click', function () {
      card.querySelector('input[type="radio"]').checked = true;
      recalc();
    });
  });

  recalc();
})();
</script>

<?php
    render_footer();
}

// ============================================================
// PAGE: CHECKOUT
// ============================================================

function page_checkout(): void
{
    require_login();

    $db = getDB();
    $clientId = current_client_id();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ' . url('configurator'));
        exit;
    }

    if (!csrf_verify()) {
        flash('error', 'Your session expired. Please try again.');
        header('Location: ' . url('configurator'));
        exit;
    }

    $garmentTypeId = (int) ($_POST['garment_type_id'] ?? 0);
    $fabricId = (int) ($_POST['fabric_id'] ?? 0);
    $monogramText = trim($_POST['monogram_text'] ?? '');

    $stmt = $db->prepare('SELECT * FROM garment_types WHERE id = :id AND is_active = 1');
    $stmt->execute(['id' => $garmentTypeId]);
    $garment = $stmt->fetch();

    $stmt = $db->prepare("SELECT * FROM fabrics WHERE id = :id AND stock_status != 'out_of_stock'");
    $stmt->execute(['id' => $fabricId]);
    $fabric = $stmt->fetch();

    if (!$garment || !$fabric) {
        flash('error', 'Please choose a valid garment and fabric.');
        header('Location: ' . url('configurator'));
        exit;
    }

    $styleConfig = [];
    $stylePriceTotal = 0.00;
    $categories = ['cut', 'lining', 'buttons', 'collar', 'cuff', 'monogram'];

    foreach ($categories as $cat) {
        $key = 'style_' . $cat;
        if (!empty($_POST[$key])) {
            $optId = (int) $_POST[$key];
            $stmt = $db->prepare('SELECT * FROM style_options WHERE id = :id AND category = :cat AND is_active = 1');
            $stmt->execute(['id' => $optId, 'cat' => $cat]);
            $opt = $stmt->fetch();
            if ($opt) {
                $styleConfig[$cat] = ['id' => $opt['id'], 'name' => $opt['name']];
                $stylePriceTotal += (float) $opt['price_modifier'];
            }
        }
    }

    if ($monogramText !== '') {
        $styleConfig['monogram_text'] = function_exists('mb_substr')
            ? mb_substr($monogramText, 0, 3)
            : substr($monogramText, 0, 3);
    }

    $stmt = $db->prepare('SELECT id FROM measurements WHERE client_id = :client_id ORDER BY recorded_at DESC LIMIT 1');
    $stmt->execute(['client_id' => $clientId]);
    $measurement = $stmt->fetch();

    if (!$measurement) {
        flash('error', 'Please book a measurement call before placing an order.');
        header('Location: ' . url('booking'));
        exit;
    }

    $tier = $_POST['order_tier'] ?? 'standard';
    if (!in_array($tier, ['standard', 'express_5day', 'rush_48hr'], true)) {
        $tier = 'standard';
    }

    $slotMgr = new ExpressSlotManager($db);
    if ($tier !== 'standard' && !$slotMgr->hasAvailability($tier)) {
        flash('error', 'That delivery tier is fully booked this week — please choose another.');
        $tier = 'standard';
    }

    $waitCalc = new WaitTimeCalculator($db);
    $promisedDate = $waitCalc->getPromisedDate($tier);

    $totalPrice = (float) $garment['base_price'] + (float) $fabric['price_modifier'] + $stylePriceTotal;
    $depositAmount = round($totalPrice * 0.5, 2);
    $balanceAmount = round($totalPrice - $depositAmount, 2);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO orders
               (client_id, garment_type_id, fabric_id, measurement_id, style_config, order_tier,
                status, total_price, deposit_amount, balance_amount, promised_pickup_date)
             VALUES
               (:client_id, :garment_id, :fabric_id, :measurement_id, :style_config, :tier,
                :status, :total, :deposit, :balance, :pickup)'
        );
        $stmt->execute([
            'client_id'      => $clientId,
            'garment_id'     => $garmentTypeId,
            'fabric_id'      => $fabricId,
            'measurement_id' => (int) $measurement['id'],
            'style_config'   => json_encode($styleConfig, JSON_UNESCAPED_UNICODE),
            'tier'           => $tier,
            'status'         => 'received',
            'total'          => $totalPrice,
            'deposit'        => $depositAmount,
            'balance'        => $balanceAmount,
            'pickup'         => $promisedDate,
        ]);

        $orderId = (int) $db->lastInsertId();

        if ($tier !== 'standard') {
            $slotMgr->claimSlot($tier);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Order creation failed: ' . $e->getMessage());
        flash('error', 'Something went wrong placing your order. Please try again.');
        header('Location: ' . url('configurator'));
        exit;
    }

    $statusService = new OrderStatusService($db);
    $statusService->updateStatus($orderId, 'received');

    flash('success', 'Order placed! Pickup expected ' . date('M j, Y', strtotime($promisedDate)) . '. Pay your deposit below to confirm it.');
    header('Location: ' . url('pay', 'order_id=' . $orderId));
    exit;
}

// ============================================================
// PAGE: DASHBOARD
// ============================================================

function page_dashboard(): void
{
    require_login();

    $db = getDB();
    $clientId = current_client_id();

    $stmt = $db->prepare(
        'SELECT o.*, g.name AS garment_name, f.name AS fabric_name
         FROM orders o
         JOIN garment_types g ON g.id = o.garment_type_id
         JOIN fabrics f ON f.id = o.fabric_id
         WHERE o.client_id = :client_id
         ORDER BY o.created_at DESC'
    );
    $stmt->execute(['client_id' => $clientId]);
    $orders = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT full_name FROM clients WHERE id = :id');
    $stmt->execute(['id' => $clientId]);
    $client = $stmt->fetch();

    $statusSteps = ['received', 'measuring', 'cutting', 'sewing', 'fitting', 'ready_for_pickup', 'completed'];
    $statusLabels = [
        'received'         => 'Received',
        'measuring'        => 'Measuring',
        'cutting'          => 'Cutting',
        'sewing'           => 'Sewing',
        'fitting'          => 'Fitting',
        'ready_for_pickup' => 'Ready',
        'completed'        => 'Complete',
    ];

    $firstName = explode(' ', $client['full_name'] ?? 'there')[0];

    render_header('My Orders');
    ?>

<section class="page-section">
  <div class="wrap">
    <div class="section-head">
      <h2>Welcome back, <?= e($firstName) ?></h2>
      <a href="<?= url('configurator') ?>" class="btn-dark">Start a New Order</a>
    </div>

    <?php if (empty($orders)): ?>
      <p style="color:#5b564f;">You don't have any orders yet. <a href="<?= url('configurator') ?>" style="color:var(--brass);">Design your first garment</a>.</p>
    <?php endif; ?>

    <?php foreach ($orders as $order): ?>
      <?php
      $isCancelled = $order['status'] === 'cancelled';
      $currentIndex = array_search($order['status'], $statusSteps, true);
      ?>
      <div class="form-card" style="max-width:100%; margin-bottom:28px;">
        <div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:10px;">
          <h3 style="font-size:1.3rem;"><?= e($order['garment_name']) ?> &mdash; <?= e($order['fabric_name']) ?></h3>
          <span class="mono" style="font-size:13px; color:#8a8478;">Order #<?= (int) $order['id'] ?></span>
        </div>

        <?php if ($isCancelled): ?>
          <p style="color:var(--bad); margin-top:16px;">This order was cancelled.</p>
        <?php else: ?>
          <div class="status-track">
            <?php foreach ($statusSteps as $i => $step): ?>
              <div class="status-step<?= $i < $currentIndex ? ' done' : '' ?><?= $i === $currentIndex ? ' current' : '' ?>">
                <div class="status-dot"></div>
                <div class="label"><?= e($statusLabels[$step]) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <table class="order-table" style="margin-top:20px;">
          <tr>
            <th>Tier</th>
            <th>Total Price</th>
            <th>Deposit</th>
            <th>Balance</th>
            <th>Promised Pickup</th>
          </tr>
          <tr>
            <td><?= e(ucfirst(str_replace('_', ' ', $order['order_tier']))) ?></td>
            <td><?= money($order['total_price']) ?></td>
            <td><?= $order['deposit_paid'] ? 'Paid (' . money($order['deposit_amount']) . ')' : 'Due — ' . money($order['deposit_amount']) ?></td>
            <td><?= $order['balance_paid'] ? 'Paid (' . money($order['balance_amount']) . ')' : 'Due — ' . money($order['balance_amount']) ?></td>
            <td><?= e(date('M j, Y', strtotime($order['promised_pickup_date']))) ?></td>
          </tr>
        </table>

        <?php if (!$isCancelled && (!$order['deposit_paid'] || !$order['balance_paid'])): ?>
          <a href="<?= url('pay', 'order_id=' . (int) $order['id']) ?>" class="btn-primary" style="margin-top:18px;">
            Pay <?= !$order['deposit_paid'] ? 'Deposit' : 'Balance' ?> &mdash; <?= money(!$order['deposit_paid'] ? $order['deposit_amount'] : $order['balance_amount']) ?>
          </a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php
    render_footer();
}

// ============================================================
// PAGE: SWATCH REQUEST
// ============================================================

function page_swatch_request(): void
{
    $db = getDB();

    $fabricId = (int) ($_GET['fabric_id'] ?? $_POST['fabric_id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM fabrics WHERE id = :id');
    $stmt->execute(['id' => $fabricId]);
    $fabric = $stmt->fetch();

    if (!$fabric || !$fabric['free_swatch_eligible']) {
        flash('error', 'That fabric is not eligible for a free swatch.');
        header('Location: ' . url('index') . '#fabrics');
        exit;
    }

    $errors = [];
    $success = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            $address = trim($_POST['shipping_address'] ?? '');
            if ($address === '') {
                $errors[] = 'Please enter a shipping address.';
            }

            $clientId = current_client_id();
            if (!$clientId) {
                $errors[] = 'Please log in or create an account to request a swatch.';
            }

            if (empty($errors)) {
                $stmt = $db->prepare(
                    'INSERT INTO swatch_requests (client_id, fabric_ids, shipping_address, status)
                     VALUES (:client_id, :fabric_ids, :address, :status)'
                );
                $stmt->execute([
                    'client_id'  => $clientId,
                    'fabric_ids' => json_encode([$fabricId]),
                    'address'    => $address,
                    'status'     => 'requested',
                ]);
                $success = true;
            }
        }
    }

    render_header('Request a Free Swatch');
    ?>

<section class="page-section">
  <div class="wrap">
    <div class="form-card">
      <h2>Request a Free Swatch</h2>
      <div class="fabric-card" style="cursor:default; margin-bottom:20px;">
        <img class="swatch-img" style="aspect-ratio:16/9;" src="<?= e($fabric['image_url']) ?>" alt="<?= e($fabric['name']) ?>">
        <div class="fc-info">
          <div class="fc-name"><?= e($fabric['name']) ?></div>
          <div class="fc-meta"><?= e($fabric['description']) ?></div>
        </div>
      </div>

      <?php if ($success): ?>
        <div class="flash flash-success">Swatch request received! We'll post it out shortly.</div>
      <?php elseif (!current_client_id()): ?>
        <p style="color:#5b564f; margin-bottom:16px;">Please <a href="<?= url('login') ?>" style="color:var(--brass);">log in</a> or <a href="<?= url('register') ?>" style="color:var(--brass);">create an account</a> to request a free swatch.</p>
      <?php else: ?>
        <?php foreach ($errors as $err): ?>
          <div class="flash flash-error"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" action="<?= url('swatch_request', 'fabric_id=' . (int) $fabricId) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="fabric_id" value="<?= (int) $fabricId ?>">
          <label for="shipping_address">Shipping Address</label>
          <textarea id="shipping_address" name="shipping_address" rows="4" required><?= e($_POST['shipping_address'] ?? '') ?></textarea>
          <div class="form-actions">
            <button type="submit" class="btn-primary">Request Swatch</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php
    render_footer();
}

// ============================================================
// PAGE: PAY
// ============================================================

function page_pay(): void
{
    require_login();

    $db = getDB();
    $clientId = current_client_id();

    $orderId = (int) ($_GET['order_id'] ?? 0);

    $stmt = $db->prepare(
        'SELECT o.*, g.name AS garment_name, f.name AS fabric_name
         FROM orders o
         JOIN garment_types g ON g.id = o.garment_type_id
         JOIN fabrics f ON f.id = o.fabric_id
         WHERE o.id = :id AND o.client_id = :client_id'
    );
    $stmt->execute(['id' => $orderId, 'client_id' => $clientId]);
    $order = $stmt->fetch();

    if (!$order) {
        flash('error', 'We couldn\'t find that order.');
        header('Location: ' . url('dashboard'));
        exit;
    }

    if ($order['status'] === 'cancelled') {
        flash('error', 'This order was cancelled, so no payment is needed.');
        header('Location: ' . url('dashboard'));
        exit;
    }

    if (!$order['deposit_paid']) {
        $paymentType = 'deposit';
        $amountDue = (float) $order['deposit_amount'];
    } elseif (!$order['balance_paid']) {
        $paymentType = 'balance';
        $amountDue = (float) $order['balance_amount'];
    } else {
        flash('success', 'This order is fully paid — nothing left to do here.');
        header('Location: ' . url('dashboard'));
        exit;
    }

    $stmt = $db->prepare('SELECT full_name, email, phone FROM clients WHERE id = :id');
    $stmt->execute(['id' => $clientId]);
    $client = $stmt->fetch();

    $emailError = '';
    $submittedEmail = trim($_POST['email'] ?? ($client['email'] ?? ''));
    $payReference = null;
    $payEmail = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_payment'])) {
        if (!csrf_verify()) {
            flash('error', 'Your session expired. Please try again.');
            header('Location: ' . url('pay', 'order_id=' . $orderId));
            exit;
        }

        if ($submittedEmail === '' || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
            $emailError = 'Please enter a valid email address — Paystack needs one for the receipt.';
        } else {
            if (empty($client['email'])) {
                $upd = $db->prepare('UPDATE clients SET email = :email WHERE id = :id');
                $upd->execute(['email' => $submittedEmail, 'id' => $clientId]);
            }

            $reference = 'ADJ-' . $orderId . '-' . strtoupper(bin2hex(random_bytes(5)));

            $stmt = $db->prepare(
                'INSERT INTO payments (order_id, payment_type, amount, currency, method, transaction_ref, status)
                 VALUES (:order_id, :type, :amount, :currency, :method, :ref, :status)'
            );
            $stmt->execute([
                'order_id' => $orderId,
                'type'     => $paymentType,
                'amount'   => $amountDue,
                'currency' => PAYSTACK_CURRENCY,
                'method'   => 'paystack',
                'ref'      => $reference,
                'status'   => 'pending',
            ]);

            $payReference = $reference;
            $payEmail = $submittedEmail;
        }
    }

    render_header('Payment');
    ?>

<section class="page-section">
  <div class="wrap">
    <div class="form-card">
      <h2><?= $paymentType === 'deposit' ? 'Pay Your Deposit' : 'Pay Remaining Balance' ?></h2>

      <table class="order-table" style="margin: 20px 0;">
        <tr><th>Garment</th><td><?= e($order['garment_name']) ?> &mdash; <?= e($order['fabric_name']) ?></td></tr>
        <tr><th>Order #</th><td class="mono"><?= (int) $order['id'] ?></td></tr>
        <tr><th>Amount Due</th><td><?= money($amountDue) ?></td></tr>
      </table>

      <?php if (!paystack_is_configured()): ?>
        <p style="color:var(--bad); margin-top:16px;">
          Online payment isn't switched on yet — the site owner still needs
          to add a Paystack secret key near the top of this file.
          Please contact us to arrange payment another way in the meantime.
        </p>
      <?php elseif (!empty($payReference)): ?>
        <p style="color:#5b564f; margin-top:16px;">Redirecting you to Paystack&hellip;</p>
        <script src="https://js.paystack.co/v2/inline.js"></script>
        <script>
          const popup = new PaystackPop();
          popup.newTransaction({
            key: '<?= e(PAYSTACK_PUBLIC_KEY) ?>',
            email: '<?= e($payEmail) ?>',
            amount: <?= (int) round($amountDue * 100) ?>,
            currency: '<?= e(PAYSTACK_CURRENCY) ?>',
            reference: '<?= e($payReference) ?>',
            onSuccess: function (transaction) {
              window.location.href = '<?= url('paystack_verify') ?>&reference=' + encodeURIComponent(transaction.reference);
            },
            onCancel: function () {
              window.location.href = '<?= url('pay', 'order_id=' . (int) $orderId) ?>';
            },
          });
        </script>
      <?php else: ?>
        <form method="POST" action="<?= url('pay', 'order_id=' . (int) $orderId) ?>">
          <?= csrf_field() ?>
          <label for="email">Email (for your payment receipt)</label>
          <input type="email" id="email" name="email" value="<?= e($submittedEmail) ?>" required>
          <?php if ($emailError): ?><p style="color:var(--bad); font-size: 13.5px; margin-top:6px;"><?= e($emailError) ?></p><?php endif; ?>

          <button type="submit" name="start_payment" value="1" class="btn-primary" style="margin-top:24px; width:100%;">
            Pay <?= money($amountDue) ?> with Paystack
          </button>
        </form>
      <?php endif; ?>

      <p style="margin-top:20px;"><a href="<?= url('dashboard') ?>" style="color:var(--brass);">&larr; Back to my orders</a></p>
    </div>
  </div>
</section>

<?php
    render_footer();
}

// ============================================================
// PAGE: PAYSTACK VERIFY
// ============================================================

function page_paystack_verify(): void
{
    require_login();

    $db = getDB();
    $clientId = current_client_id();

    $reference = trim($_GET['reference'] ?? '');

    if ($reference === '') {
        flash('error', 'Missing payment reference.');
        header('Location: ' . url('dashboard'));
        exit;
    }

    $stmt = $db->prepare(
        'SELECT p.*, o.client_id, o.id AS order_id, o.deposit_paid, o.balance_paid
         FROM payments p
         JOIN orders o ON o.id = p.order_id
         WHERE p.transaction_ref = :ref'
    );
    $stmt->execute(['ref' => $reference]);
    $payment = $stmt->fetch();

    if (!$payment || (int) $payment['client_id'] !== $clientId) {
        flash('error', 'We couldn\'t find that payment.');
        header('Location: ' . url('dashboard'));
        exit;
    }

    if ($payment['status'] === 'successful') {
        flash('success', 'Payment already confirmed. Thank you!');
        header('Location: ' . url('dashboard'));
        exit;
    }

    if (!paystack_is_configured()) {
        flash('error', 'Online payment verification isn\'t set up yet. Please contact us to confirm your payment.');
        header('Location: ' . url('dashboard'));
        exit;
    }

    $ch = curl_init("https://api.paystack.co/transaction/verify/" . rawurlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log('Paystack verify failed: ' . $curlError . ' (HTTP ' . $httpCode . ')');
        flash('error', 'We couldn\'t confirm your payment with Paystack just now. If money left your account, contact us with reference ' . $reference . '.');
        header('Location: ' . url('dashboard'));
        exit;
    }

    $result = json_decode($response, true);
    $txData = $result['data'] ?? null;

    $expectedAmount = (int) round((float) $payment['amount'] * 100);

    $isVerifiedSuccess = !empty($result['status'])
        && $txData
        && ($txData['status'] ?? '') === 'success'
        && (int) ($txData['amount'] ?? -1) === $expectedAmount
        && strtoupper((string) ($txData['currency'] ?? '')) === PAYSTACK_CURRENCY;

    if (!$isVerifiedSuccess) {
        $stmt = $db->prepare("UPDATE payments SET status = 'failed' WHERE id = :id");
        $stmt->execute(['id' => $payment['id']]);
        flash('error', 'That payment could not be confirmed as successful. No charge has been recorded.');
        header('Location: ' . url('pay', 'order_id=' . (int) $payment['order_id']));
        exit;
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE payments SET status = 'successful', paid_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $payment['id']]);

        $column = $payment['payment_type'] === 'balance' ? 'balance_paid' : 'deposit_paid';
        $stmt = $db->prepare("UPDATE orders SET {$column} = 1 WHERE id = :id");
        $stmt->execute(['id' => $payment['order_id']]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Recording successful payment failed: ' . $e->getMessage());
        flash('error', 'Your payment succeeded but we hit a snag recording it — please contact us with reference ' . $reference . '.');
        header('Location: ' . url('dashboard'));
        exit;
    }

    flash('success', $payment['payment_type'] === 'deposit'
        ? 'Deposit received — your order is confirmed!'
        : 'Balance paid in full — thank you!');
    header('Location: ' . url('dashboard'));
    exit;
}

// ============================================================
// WHATSAPP CRON WORKER (CLI only)
// ============================================================

function cli_send_whatsapp_notifications(): void
{
    if (!twilio_is_configured()) {
        fwrite(STDERR, "Twilio credentials not set — edit the CHANGE_ME_twilio_* constants near the top of this file first.\n");
        exit(1);
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        fwrite(STDERR, "Twilio SDK not installed. Run 'composer require twilio/sdk' in this file's folder first.\n");
        exit(1);
    }
    require_once $autoload;

    if (!class_exists(TwilioClient::class)) {
        fwrite(STDERR, "Twilio SDK classes not found. Please run 'composer require twilio/sdk'.\n");
        exit(1);
    }

    $db = getDB();
    $twilio = new TwilioClient(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);

    $stmt = $db->prepare(
        "SELECT n.*, c.phone
         FROM whatsapp_notifications n
         JOIN clients c ON c.id = n.client_id
         WHERE n.status = 'queued' AND n.attempts < :max_attempts
         ORDER BY n.id ASC
         LIMIT :limit"
    );
    $stmt->bindValue('max_attempts', WHATSAPP_MAX_ATTEMPTS, PDO::PARAM_INT);
    $stmt->bindValue('limit', WHATSAPP_BATCH_LIMIT, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        echo "Nothing queued.\n";
        return;
    }

    $sent = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $result = whatsapp_send_one($twilio, $db, $row);
        $result ? $sent++ : $failed++;
    }

    echo "Done. Sent: $sent, Failed: $failed\n";
}

function whatsapp_send_one(TwilioClient $twilio, PDO $db, array $row): bool
{
    $to = whatsapp_normalize_number($row['phone']);

    if ($to === null) {
        whatsapp_mark_failed($db, (int) $row['id'], (int) $row['attempts'], 'Invalid or missing phone number on client record');
        return false;
    }

    $contentSid = TWILIO_CONTENT_SIDS[$row['message_type']] ?? null;

    try {
        if ($contentSid) {
            $message = $twilio->messages->create($to, [
                'from'             => TWILIO_WHATSAPP_FROM,
                'contentSid'       => $contentSid,
                'contentVariables' => json_encode(['1' => $row['message_body']]),
            ]);
        } else {
            $message = $twilio->messages->create($to, [
                'from' => TWILIO_WHATSAPP_FROM,
                'body' => $row['message_body'],
            ]);
        }

        $stmt = $db->prepare(
            "UPDATE whatsapp_notifications
             SET status = 'sent', sent_at = NOW(), twilio_sid = :sid, attempts = attempts + 1, last_error = NULL
             WHERE id = :id"
        );
        $stmt->execute(['sid' => $message->sid, 'id' => $row['id']]);
        return true;

    } catch (TwilioException $e) {
        whatsapp_mark_failed($db, (int) $row['id'], (int) $row['attempts'], $e->getMessage());
        return false;
    } catch (Throwable $e) {
        whatsapp_mark_failed($db, (int) $row['id'], (int) $row['attempts'], 'Unexpected error: ' . $e->getMessage());
        return false;
    }
}

function whatsapp_mark_failed(PDO $db, int $id, int $attemptsSoFar, string $error): void
{
    $newAttempts = $attemptsSoFar + 1;
    $finalStatus = $newAttempts >= WHATSAPP_MAX_ATTEMPTS ? 'failed' : 'queued';

    $stmt = $db->prepare(
        'UPDATE whatsapp_notifications
         SET attempts = :attempts, last_error = :error, status = :status
         WHERE id = :id'
    );
    $stmt->execute([
        'attempts' => $newAttempts,
        'error'    => function_exists('mb_substr') ? mb_substr($error, 0, 255) : substr($error, 0, 255),
        'status'   => $finalStatus,
        'id'       => $id,
    ]);

    error_log("WhatsApp notification #$id failed (attempt $newAttempts): $error");
}

function whatsapp_normalize_number(?string $rawPhone): ?string
{
    if (!$rawPhone) {
        return null;
    }

    $digits = preg_replace('/[^\d+]/', '', $rawPhone);

    if (str_starts_with($digits, '+')) {
        $e164 = $digits;
    } elseif (str_starts_with($digits, '0') && strlen($digits) === 10) {
        $e164 = '+233' . substr($digits, 1);
    } elseif (str_starts_with($digits, '233')) {
        $e164 = '+' . $digits;
    } else {
        return null;
    }

    if (!preg_match('/^\+\d{8,15}$/', $e164)) {
        return null;
    }

    return 'whatsapp:' . $e164;
}

// ============================================================
// ROUTER
// ============================================================

if (PHP_SAPI === 'cli') {
    $cliCommand = $argv[1] ?? '';
    if ($cliCommand === 'send_whatsapp_notifications') {
        cli_send_whatsapp_notifications();
    } else {
        fwrite(STDERR, "Usage: php " . basename(__FILE__) . " send_whatsapp_notifications\n");
        exit(1);
    }
    exit(0);
}

// Web mode: dispatch on ?page=
$page = $_GET['page'] ?? 'index';

switch ($page) {
    case 'index':
        page_index();
        break;
    case 'login':
        page_login();
        break;
    case 'register':
        page_register();
        break;
    case 'logout':
        page_logout();
        break;
    case 'gallery':
        page_gallery();
        break;
    case 'booking':
        page_booking();
        break;
    case 'configurator':
        page_configurator();
        break;
    case 'checkout':
        page_checkout();
        break;
    case 'dashboard':
        page_dashboard();
        break;
    case 'swatch_request':
        page_swatch_request();
        break;
    case 'pay':
        page_pay();
        break;
    case 'paystack_verify':
        page_paystack_verify();
        break;
    default:
        http_response_code(404);
        render_header('Page Not Found');
        echo '<section class="page-section"><div class="wrap"><h2>Page not found</h2><p><a href="' . url('index') . '">Return home</a></p></div></section>';
        render_footer();
        break;
}