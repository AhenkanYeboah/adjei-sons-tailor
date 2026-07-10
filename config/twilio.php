<?php
/**
 * Twilio WhatsApp credentials.
 *
 * TWILIO_ACCOUNT_SID is not secret by itself but is still best kept out
 * of a public repo. Find both values in the Twilio Console at
 * https://console.twilio.com — Account SID and Auth Token are on the
 * dashboard's home page.
 *
 * TWILIO_AUTH_TOKEN must NEVER be exposed to the browser, committed to
 * a public repo, or pasted into chat/email — anyone with it can send
 * messages and make calls billed to your account. Load it from a .env
 * file or environment variable in production if you can.
 *
 * TWILIO_WHATSAPP_FROM is your WhatsApp-enabled sender number in
 * Twilio's required format: "whatsapp:+1415XXXXXXX". While testing,
 * this is your Sandbox number (Console → Messaging → Try it Out →
 * Send a WhatsApp message). For production, apply for a dedicated
 * WhatsApp Business sender under Messaging → Senders.
 *
 * Message templates: WhatsApp requires Meta-approved templates for any
 * message sent outside a 24-hour customer-reply window — which is true
 * for nearly all of this app's notifications (status updates, booking
 * reminders), since they're business-initiated, not replies. Create
 * templates in the Twilio Console (Messaging → Content Editor), get
 * each approved by Meta (usually within a few hours to a day), then
 * paste its Content SID below. Until a template exists for a given
 * message_type, the worker sends a plain free-form message instead,
 * which Twilio will reject unless the client messaged you in the last
 * 24 hours — so filling these in is what makes delivery reliable.
 */

define('TWILIO_ACCOUNT_SID', 'CHANGE_ME_twilio_account_sid');   // starts with AC...
define('TWILIO_AUTH_TOKEN', 'CHANGE_ME_twilio_auth_token');
define('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886');        // Sandbox default; replace once approved

// Maps our internal whatsapp_notifications.message_type values to
// Twilio Content SIDs for approved templates. Add a row here each time
// a new template is approved. Leave a type out (or set it to null) to
// have the worker fall back to a free-form message for that type.
define('TWILIO_CONTENT_SIDS', [
    'status_update'        => null, // e.g. 'HXb5b62575e6e4ff6129ad7c8efe1f983e'
    'booking_reminder'     => null,
    'measurement_summary'  => null,
]);

function twilio_is_configured(): bool
{
    return TWILIO_ACCOUNT_SID !== 'CHANGE_ME_twilio_account_sid'
        && TWILIO_AUTH_TOKEN !== 'CHANGE_ME_twilio_auth_token';
}
