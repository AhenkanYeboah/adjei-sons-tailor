<?php
/**
 * Paystack API credentials.
 *
 * PAYSTACK_PUBLIC_KEY is safe to expose in the browser — it's used
 * client-side to open the Paystack payment popup (see pay.php).
 *
 * PAYSTACK_SECRET_KEY must NEVER be exposed to the browser, committed
 * to a public repo, or pasted into chat/email. It's used server-side
 * only, in paystack_verify.php, to confirm with Paystack's API that a
 * payment actually succeeded before we mark an order as paid — the
 * client-side callback alone can be spoofed, so this check is what
 * actually protects your revenue. Get it from your Paystack dashboard
 * under Settings → API Keys & Webhooks, and paste it below (or better,
 * load it from a .env file / environment variable that isn't committed).
 */

define('PAYSTACK_PUBLIC_KEY', 'pk_test_e7d2c45e0fbfe408a6a7cd79f633517490f6db90');
define('PAYSTACK_SECRET_KEY', 'CHANGE_ME_paystack_secret_key'); // sk_test_...

define('PAYSTACK_CURRENCY', 'GHS');
