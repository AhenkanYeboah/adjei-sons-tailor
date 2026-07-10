<?php
require_once __DIR__ . '/../config/bootstrap.php';

$db = getDB();

$fabricId = (int) ($_GET['fabric_id'] ?? $_POST['fabric_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM fabrics WHERE id = :id');
$stmt->execute(['id' => $fabricId]);
$fabric = $stmt->fetch();

if (!$fabric || !$fabric['free_swatch_eligible']) {
    flash('error', 'That fabric is not eligible for a free swatch.');
    header('Location: ' . BASE_URL . '/index.php#fabrics');
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

$pageTitle = 'Request a Free Swatch';
require __DIR__ . '/../app/views/partials/header.php';
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
        <p style="color:#5b564f; margin-bottom:16px;">Please <a href="<?= BASE_URL ?>/login.php" style="color:var(--brass);">log in</a> or <a href="<?= BASE_URL ?>/register.php" style="color:var(--brass);">create an account</a> to request a free swatch.</p>
      <?php else: ?>
        <?php foreach ($errors as $err): ?>
          <div class="flash flash-error"><?= e($err) ?></div>
        <?php endforeach; ?>
        <form method="post" action="<?= BASE_URL ?>/swatch_request.php?fabric_id=<?= (int) $fabricId ?>">
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

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
