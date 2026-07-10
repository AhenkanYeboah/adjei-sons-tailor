<?php
require_once __DIR__ . '/../config/bootstrap.php';

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
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

if ($order['status'] === 'cancelled') {
    flash('error', 'This order was cancelled, so no payment is needed.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Decide what's being paid for: deposit first, then balance. If both are
// already paid, there's nothing left to do here.
if (!$order['deposit_paid']) {
    $paymentType = 'deposit';
    $amountDue = (float) $order['deposit_amount'];
} elseif (!$order['balance_paid']) {
    $paymentType = 'balance';
    $amountDue = (float) $order['balance_amount'];
} else {
    flash('success', 'This order is fully paid — nothing left to do here.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$stmt = $db->prepare('SELECT full_name, email, phone FROM clients WHERE id = :id');
$stmt->execute(['id' => $clientId]);
$client = $stmt->fetch();

$emailError = '';
$submittedEmail = trim($_POST['email'] ?? ($client['email'] ?? ''));

// Paystack requires an email on every transaction. Most of this app treats
// email as optional at signup, so we collect/confirm it here rather than
// forcing it earlier for people who only ever pay in person.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_payment'])) {
    if (!csrf_verify()) {
        flash('error', 'Your session expired. Please try again.');
        header('Location: ' . BASE_URL . '/pay.php?order_id=' . $orderId);
        exit;
    }

    if ($submittedEmail === '' || !filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
        $emailError = 'Please enter a valid email address — Paystack needs one for the receipt.';
    } else {
        // Keep the client record in sync if they didn't have an email on file.
        if (empty($client['email'])) {
            $upd = $db->prepare('UPDATE clients SET email = :email WHERE id = :id');
            $upd->execute(['email' => $submittedEmail, 'id' => $clientId]);
        }

        // A fresh reference per attempt (transaction_ref is UNIQUE), so
        // retries after a failed/abandoned payment don't collide.
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

$pageTitle = 'Payment';
require __DIR__ . '/../app/views/partials/header.php';
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
          to add a Paystack secret key to <code>config/paystack.php</code>.
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
            amount: <?= (int) round($amountDue * 100) ?>, // Paystack expects the amount in the smallest currency unit (pesewas)
            currency: '<?= e(PAYSTACK_CURRENCY) ?>',
            reference: '<?= e($payReference) ?>',
            onSuccess: function (transaction) {
              window.location.href = '<?= BASE_URL ?>/paystack_verify.php?reference=' + encodeURIComponent(transaction.reference);
            },
            onCancel: function () {
              window.location.href = '<?= BASE_URL ?>/pay.php?order_id=<?= (int) $orderId ?>';
            },
          });
        </script>
      <?php else: ?>
        <form method="POST" action="<?= BASE_URL ?>/pay.php?order_id=<?= (int) $orderId ?>">
          <?= csrf_field() ?>
          <label for="email">Email (for your payment receipt)</label>
          <input type="email" id="email" name="email" value="<?= e($submittedEmail) ?>" required>
          <?php if ($emailError): ?><p style="color:var(--bad); font-size: 13.5px; margin-top:6px;"><?= e($emailError) ?></p><?php endif; ?>

          <button type="submit" name="start_payment" value="1" class="btn-primary" style="margin-top:24px; width:100%;">
            Pay <?= money($amountDue) ?> with Paystack
          </button>
        </form>
      <?php endif; ?>

      <p style="margin-top:20px;"><a href="<?= BASE_URL ?>/dashboard.php" style="color:var(--brass);">&larr; Back to my orders</a></p>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
