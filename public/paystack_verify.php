<?php
require_once __DIR__ . '/../config/bootstrap.php';

require_login();

$db = getDB();
$clientId = current_client_id();

$reference = trim($_GET['reference'] ?? '');

if ($reference === '') {
    flash('error', 'Missing payment reference.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// The payment row must exist, belong to an order owned by this client, and
// still be pending — this stops someone from re-submitting an old/foreign
// reference and having it silently accepted.
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
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

if ($payment['status'] === 'successful') {
    // Already verified (e.g. user hit back/refresh) — nothing more to do.
    flash('success', 'Payment already confirmed. Thank you!');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

if (!paystack_is_configured()) {
    flash('error', 'Online payment verification isn\'t set up yet. Please contact us to confirm your payment.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Ask Paystack directly whether this transaction actually succeeded.
// Never trust the browser's redirect/callback alone for this.
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
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$result = json_decode($response, true);
$txData = $result['data'] ?? null;

// Expected amount in the smallest currency unit (pesewas), matching what
// pay.php sent to Paystack — this is what stops a tampered/forged callback
// from marking a smaller (or zero) payment as the full deposit/balance.
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
    header('Location: ' . BASE_URL . '/pay.php?order_id=' . (int) $payment['order_id']);
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
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

flash('success', $payment['payment_type'] === 'deposit'
    ? 'Deposit received — your order is confirmed!'
    : 'Balance paid in full — thank you!');
header('Location: ' . BASE_URL . '/dashboard.php');
exit;
