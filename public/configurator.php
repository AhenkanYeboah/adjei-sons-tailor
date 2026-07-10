<?php
require_once __DIR__ . '/../config/bootstrap.php';

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

$pageTitle = 'Design Your Garment';
require __DIR__ . '/../app/views/partials/header.php';
?>

<section class="configurator" style="padding-top:50px;">
  <div class="wrap">
    <div class="section-head">
      <h2>Design your garment</h2>
      <span class="num">Price updates live</span>
    </div>

    <form id="configForm" method="post" action="<?= BASE_URL ?>/checkout.php">
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
          <a href="<?= BASE_URL ?>/login.php" class="btn-primary">Log In to Continue</a>
        <?php endif; ?>
      </p>
    </form>
  </div>
</section>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>

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
