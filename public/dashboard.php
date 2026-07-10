<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../app/services/WaitTimeCalculator.php';

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

$pageTitle = 'My Orders';
require __DIR__ . '/../app/views/partials/header.php';
?>

<section class="page-section">
  <div class="wrap">
    <div class="section-head">
      <h2>Welcome back, <?= e($firstName) ?></h2>
      <a href="<?= BASE_URL ?>/configurator.php" class="btn-dark">Start a New Order</a>
    </div>

    <?php if (empty($orders)): ?>
      <p style="color:#5b564f;">You don't have any orders yet. <a href="<?= BASE_URL ?>/configurator.php" style="color:var(--brass);">Design your first garment</a>.</p>
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
          <a href="<?= BASE_URL ?>/pay.php?order_id=<?= (int) $order['id'] ?>" class="btn-primary" style="margin-top:18px;">
            Pay <?= !$order['deposit_paid'] ? 'Deposit' : 'Balance' ?> &mdash; <?= money(!$order['deposit_paid'] ? $order['deposit_amount'] : $order['balance_amount']) ?>
          </a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
