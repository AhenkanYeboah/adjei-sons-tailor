# Adjei & Sons — Bespoke Tailoring Platform

A working PHP/MySQL app built against `schema.sql`, styled to match the
"Adjei & Sons" homepage mockup (brass/ivory palette, Fraunces + Inter +
IBM Plex Mono, measuring-tape motif). Every number on every page — wait
time, prices, stock, gallery — is read live from the database, nothing
is hardcoded.

This has been tested end-to-end against a real MySQL/MariaDB instance
loaded from your `schema.sql`: registration, login, booking, the full
configurator → checkout → order flow, and the tracking dashboard all
verified working with zero PHP warnings/errors.

## Requirements

- PHP 8.1+ with the `pdo_mysql` extension (mbstring recommended but not required)
- MySQL 8 or MariaDB 10.5+

## Setup

1. **Load the schema, then the seed data** (in that order):
   ```bash
   mysql -u root -p < schema.sql
   mysql -u root -p bespoke_tailor < config/seed_data.sql
   ```
   `schema.sql` creates the database and one `production_settings` row.
   `seed_data.sql` adds sample garments, fabrics, style options, staff,
   a demo client, gallery photos, and this week's express/rush slots.

2. **Create a dedicated DB user** (don't use `root` in production):
   ```sql
   CREATE USER 'tailor_app'@'localhost' IDENTIFIED BY 'a-strong-password';
   GRANT ALL PRIVILEGES ON bespoke_tailor.* TO 'tailor_app'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. **Edit `config/database.php`** and replace the `CHANGE_ME_*` placeholders
   with that user's credentials.

4. **Point your web server's document root at `public/`** (not the project
   root — `config/`, `app/`, and the `.sql` files should not be
   web-accessible). For local testing:
   ```bash
   php -S localhost:8000 -t public
   ```
   then visit `http://localhost:8000/index.php`.

5. Demo login (from the seed data): phone `+233201234567`, but you'll need
   to register a fresh account through `/register.php` since the seeded
   password hash is a placeholder — use "Create Account" on the site.

## What's included

| File | Purpose |
|---|---|
| `public/index.php` | Homepage — hero, live wait-time widget, tape-meter, fabric grid, gallery preview |
| `public/configurator.php` | Garment/fabric/style picker with live client-side price preview |
| `public/checkout.php` | Server-side order creation — recalculates price from the DB, never trusts client totals |
| `public/dashboard.php` | Logged-in client's orders + visual status tracker |
| `public/gallery.php` | Full gallery with occasion filtering |
| `public/booking.php` | Books consultation / video-measurement / fitting calls |
| `public/swatch_request.php` | Free fabric swatch requests |
| `public/login.php`, `register.php`, `logout.php` | Auth |
| `app/services/WaitTimeCalculator.php` | Single source of truth for wait-time estimates — used identically by the homepage widget and checkout |
| `app/services/ExpressSlotManager.php` | Weekly express/rush slot capacity tracking |
| `app/services/OrderStatusService.php` | Updates order status, writes the audit log, and queues the matching WhatsApp notification — all three from one call |
| `scripts/send_whatsapp_notifications.php` | Cron worker — sends queued WhatsApp notifications via Twilio |
| `config/twilio.php` | Twilio credentials + message-template Content SIDs |
| `config/migration_whatsapp_worker.sql` | Adds retry-tracking columns to `whatsapp_notifications` |
| `config/seed_data.sql` | Sample content, including stock photo URLs for every fabric/garment/gallery image |

## Images

Every image is a hotlinked Unsplash stock photo (free to use under the
Unsplash License, no attribution required, though the mockup's original
credit badges are preserved on the homepage hero/queue photos as a nod
to that). Swap the URLs in `config/seed_data.sql` for your own product
photography whenever you have it — the `fabrics.image_url`,
`gallery_items.image_url`, and hero `<img>` tags are the places to edit.

## Security notes

- All queries use PDO prepared statements.
- Every POST form is CSRF-protected (`csrf_field()` / `csrf_verify()`).
- Passwords are hashed with `password_hash()` (bcrypt).
- Checkout recalculates the order total from `garment_types`, `fabrics`,
  and `style_options` server-side — a tampered client-submitted price is
  never trusted.
- Login has basic rate limiting (8 attempts / 10 minutes per session).

## Payments (Paystack)

Checkout now hands the customer straight to `public/pay.php`, which
collects deposit and balance payments via Paystack:

1. **`config/paystack.php`** holds the two API keys.
   - `PAYSTACK_PUBLIC_KEY` is already set and safe to expose in the browser
     — it only opens the payment popup.
   - `PAYSTACK_SECRET_KEY` is a placeholder (`CHANGE_ME_paystack_secret_key`).
     Get your real one from the Paystack dashboard under
     **Settings → API Keys & Webhooks** and paste it in there. Don't
     commit it to a public repo or share it anywhere outside that file —
     treat it like a password. Until it's set, `pay.php` will tell
     customers online payment isn't available yet instead of trying to
     process anything.
2. **`public/pay.php`** — shown right after an order is placed, and
   linked from the dashboard for any order still owing a deposit or
   balance. Confirms/collects the client's email (Paystack requires one),
   creates a `payments` row (`status = 'pending'`), then opens the
   Paystack Inline popup for that amount.
3. **`public/paystack_verify.php`** — where the popup sends the browser
   after payment. This is the step that actually matters: it calls
   Paystack's `GET /transaction/verify/:reference` endpoint **server-side
   with the secret key**, checks the transaction is `success` and that
   the amount/currency match what was charged, and only then marks the
   `payments` row `successful` and flips `orders.deposit_paid` or
   `orders.balance_paid`. The client-side popup callback alone is never
   trusted for this — it can be spoofed by a modified page.
4. Deposit is paid first; once that's done, the same `pay.php` page
   automatically switches to collecting the balance.

**Not included yet:** a webhook endpoint. The verify-on-redirect flow
above covers the normal case, but if a customer pays and then closes the
tab before the redirect fires, that payment won't get recorded. For
production, add a webhook route that listens for Paystack's
`charge.success` event and runs the same verify-and-record logic — see
[Paystack's webhook docs](https://paystack.com/docs/payments/webhooks/).

## WhatsApp Notifications (Twilio)

Every status change, order confirmation, and booking already queues a
row in `whatsapp_notifications` — `OrderStatusService` and
`checkout.php`/`booking.php` do this automatically, no changes needed
there. What was missing was something to actually send them; that's
`scripts/send_whatsapp_notifications.php`, tested end-to-end against a
real copy of the Twilio SDK (verified it makes a real signed HTTPS call
and correctly parses Twilio's response, including a genuine 403 from
fake credentials — only real credentials are missing to go live).

1. **Run once, from the project root:**
   ```bash
   composer install
   ```
   This pulls in `twilio/sdk` per `composer.json` and creates `vendor/`.

2. **Apply the migration** (adds retry-tracking columns the worker needs):
   ```bash
   mysql -u your_user -p bespoke_tailor < config/migration_whatsapp_worker.sql
   ```
   Safe to run even on a database you've already been using — it only
   adds columns, nothing existing is touched.

3. **Edit `config/twilio.php`** and replace the two `CHANGE_ME_*`
   placeholders with your Account SID and Auth Token from the
   [Twilio Console](https://console.twilio.com). While testing, leave
   `TWILIO_WHATSAPP_FROM` as the Sandbox number; switch it to your
   approved WhatsApp Business sender once that's live.

4. **Message templates**: WhatsApp requires a Meta-approved template
   for any business-initiated message sent outside a 24-hour window
   after the customer last messaged you — which covers nearly every
   notification this app sends (status updates, booking reminders).
   Create templates in the Twilio Console under **Messaging → Content
   Editor**, wait for Meta's approval (usually hours, sometimes a day),
   then paste each template's Content SID into the
   `TWILIO_CONTENT_SIDS` array in `config/twilio.php`. Until a
   message_type has an approved template, the worker falls back to a
   free-form message, which only succeeds if the client messaged you
   in the last 24 hours — so filling these in is what makes delivery
   reliable rather than hit-or-miss.

5. **Run the worker on a schedule.** It processes up to 50 queued
   messages per run and exits — it's not a long-running daemon. Add it
   to cron:
   ```
   * * * * * cd /path/to/project && php scripts/send_whatsapp_notifications.php >> /var/log/whatsapp_worker.log 2>&1
   ```
   Every minute is reasonable for a shop this size; adjust as needed.

**How failures are handled:** each row tracks `attempts` and
`last_error`. A failed send (bad number, Twilio rejection, network
error) stays `queued` and retries on the next cron run, up to 5
attempts, then flips to `failed` so it stops retrying forever. Ghana
local numbers (`0XXXXXXXXX`) are automatically normalized to the
`+233...` format Twilio requires — this was verified against real
Ghana number formats during testing, not just assumed to work.

## Known gaps to fill before going live

- **Paystack webhook**: see above — recommended as a backstop for the
  popup-redirect flow.
- **Hubtel/Stripe**: only Paystack is wired up; the `payments.method`
  column supports the others if you add them later.
- **WhatsApp template approval**: the worker is ready to send, but you
  still need to get your message templates approved by Meta (see
  above) before delivery is reliable — free-form messages only work
  within a customer's 24-hour reply window.
- **Staff/admin panel**: order status updates currently have no UI —
  `OrderStatusService::updateStatus()` is ready to be called from an
  admin page, but that page doesn't exist yet.
- **Measurements**: checkout requires an existing `measurements` row for
  the client; there's no page yet for staff to record one after a
  booking call (currently would need to be inserted directly or via a
  future admin tool).
