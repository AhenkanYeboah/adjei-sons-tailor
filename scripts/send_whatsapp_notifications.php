<?php
/**
 * send_whatsapp_notifications.php
 *
 * Sends every queued row in whatsapp_notifications via Twilio's WhatsApp
 * API, then marks each as 'sent' or 'failed'. Meant to run on a schedule
 * (cron), not from a browser — see the "Deploying" section in the README
 * or the note at the bottom of this file.
 *
 * Requires:
 *   composer require twilio/sdk
 * run from the project root (where composer.json will be created).
 *
 * Usage:
 *   php scripts/send_whatsapp_notifications.php
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/twilio.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Twilio SDK not installed. Run 'composer require twilio/sdk' in the project root first.\n");
    exit(1);
}
require_once $autoload;

use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;

const MAX_ATTEMPTS = 5;
const BATCH_LIMIT = 50; // cap per run so one invocation can't run forever

function run(): void
{
    if (!twilio_is_configured()) {
        fwrite(STDERR, "Twilio credentials not set — edit config/twilio.php first.\n");
        exit(1);
    }

    $db = getDB();
    $twilio = new Client(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);

    $stmt = $db->prepare(
        "SELECT n.*, c.phone
         FROM whatsapp_notifications n
         JOIN clients c ON c.id = n.client_id
         WHERE n.status = 'queued' AND n.attempts < :max_attempts
         ORDER BY n.id ASC
         LIMIT :limit"
    );
    $stmt->bindValue('max_attempts', MAX_ATTEMPTS, PDO::PARAM_INT);
    $stmt->bindValue('limit', BATCH_LIMIT, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        echo "Nothing queued.\n";
        return;
    }

    $sent = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $result = sendOne($twilio, $db, $row);
        $result ? $sent++ : $failed++;
    }

    echo "Done. Sent: $sent, Failed: $failed, Skipped (bad phone): " . (count($rows) - $sent - $failed) . "\n";
}

function sendOne(Client $twilio, PDO $db, array $row): bool
{
    $to = normalizeWhatsappNumber($row['phone']);

    if ($to === null) {
        markFailed($db, $row['id'], (int) $row['attempts'], 'Invalid or missing phone number on client record');
        return false;
    }

    $contentSid = TWILIO_CONTENT_SIDS[$row['message_type']] ?? null;

    try {
        if ($contentSid) {
            // Approved template — required for business-initiated messages
            // outside a 24-hour reply window. The template's only variable
            // slot ({{1}}) carries our pre-built message body.
            $message = $twilio->messages->create($to, [
                'from'             => TWILIO_WHATSAPP_FROM,
                'contentSid'       => $contentSid,
                'contentVariables' => json_encode(['1' => $row['message_body']]),
            ]);
        } else {
            // No approved template for this message_type yet. This only
            // succeeds if the client messaged us within the last 24 hours;
            // otherwise Twilio will reject it. See config/twilio.php.
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
        markFailed($db, $row['id'], (int) $row['attempts'], $e->getMessage());
        return false;
    } catch (Throwable $e) {
        // Network errors, timeouts, etc. — anything not specific to Twilio.
        markFailed($db, $row['id'], (int) $row['attempts'], 'Unexpected error: ' . $e->getMessage());
        return false;
    }
}

function markFailed(PDO $db, int $id, int $attemptsSoFar, string $error): void
{
    $newAttempts = $attemptsSoFar + 1;
    $finalStatus = $newAttempts >= MAX_ATTEMPTS ? 'failed' : 'queued';

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

/**
 * Twilio requires E.164 format with a "whatsapp:" prefix, e.g.
 * "whatsapp:+233201234567". Client phone numbers in this app are meant
 * to already be entered as +233..., but we defensively normalize common
 * local Ghana formats (0XXXXXXXXX) too, rather than silently failing on
 * every number a client typed slightly differently at signup.
 */
function normalizeWhatsappNumber(?string $rawPhone): ?string
{
    if (!$rawPhone) {
        return null;
    }

    $digits = preg_replace('/[^\d+]/', '', $rawPhone);

    if (str_starts_with($digits, '+')) {
        $e164 = $digits;
    } elseif (str_starts_with($digits, '0') && strlen($digits) === 10) {
        // Ghana local format 0XXXXXXXXX -> +233XXXXXXXXX
        $e164 = '+233' . substr($digits, 1);
    } elseif (str_starts_with($digits, '233')) {
        $e164 = '+' . $digits;
    } else {
        return null;
    }

    // Sanity check: E.164 is max 15 digits after the +.
    if (!preg_match('/^\+\d{8,15}$/', $e164)) {
        return null;
    }

    return 'whatsapp:' . $e164;
}

run();
