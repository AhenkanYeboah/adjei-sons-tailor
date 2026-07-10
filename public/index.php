<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../app/services/WaitTimeCalculator.php';
require_once __DIR__ . '/../app/services/ExpressSlotManager.php';

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

// Reference garment for the tier price cards (cheapest active garment,
// so the express/rush prices shown are real, not made up).
$refGarment = $db->query(
    "SELECT * FROM garment_types WHERE is_active = 1 ORDER BY base_price ASC LIMIT 1"
)->fetch();
$refBase = $refGarment ? (float) $refGarment['base_price'] : 1200.00;

// Tier price deltas — express/rush carry a premium over standard.
// Kept simple and transparent: +25% for express, +50% for rush.
$standardPrice = $refBase;
$expressPrice  = round($refBase * 1.25, 2);
$rushPrice     = round($refBase * 1.50, 2);

$fabrics = $db->query(
    "SELECT * FROM fabrics WHERE stock_status != 'out_of_stock' ORDER BY id ASC LIMIT 4"
)->fetchAll();

$galleryItems = $db->query(
    "SELECT * FROM gallery_items ORDER BY is_featured DESC, created_at DESC LIMIT 5"
)->fetchAll();

$pageTitle = 'Bespoke, On Your Time';
require __DIR__ . '/../app/views/partials/header.php';
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
        <a href="<?= BASE_URL ?>/configurator.php" class="btn-primary">Start Your Design</a>
        <a href="<?= BASE_URL ?>/booking.php" class="btn-ghost">Book Free Consultation</a>
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
      <a href="<?= BASE_URL ?>/configurator.php?fabric_id=<?= (int) $f['id'] ?>" class="fabric-card">
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
      <a href="<?= BASE_URL ?>/configurator.php" class="btn-dark">Open Full Configurator</a>
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
      <a href="<?= BASE_URL ?>/gallery.php" class="btn-dark">View Full Gallery</a>
    </p>
  </div>
</section>

<section class="cta">
  <div class="wrap">
    <h2>Ready when you are.</h2>
    <p>Book a free consultation on WhatsApp &mdash; no shop visit needed to get started.</p>
    <a href="<?= BASE_URL ?>/booking.php" class="btn-primary">Book Free Consultation on WhatsApp</a>
  </div>
</section>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>

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
