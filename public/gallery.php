<?php
require_once __DIR__ . '/../config/bootstrap.php';

$db = getDB();

$validCategories = ['wedding', 'corporate', 'graduation', 'traditional', 'casual'];
$filter = $_GET['occasion'] ?? '';
if (!in_array($filter, $validCategories, true)) {
    $filter = '';
}

if ($filter !== '') {
    $stmt = $db->prepare('SELECT * FROM gallery_items WHERE occasion_category = :cat ORDER BY is_featured DESC, created_at DESC');
    $stmt->execute(['cat' => $filter]);
} else {
    $stmt = $db->query('SELECT * FROM gallery_items ORDER BY is_featured DESC, created_at DESC');
}
$items = $stmt->fetchAll();

$pageTitle = 'Client Gallery';
require __DIR__ . '/../app/views/partials/header.php';
?>

<section class="gallery" style="padding-top:50px;">
  <div class="wrap">
    <div class="section-head">
      <h2>Client gallery</h2>
      <span class="num"><?= count($items) ?> looks</span>
    </div>
    <div class="gallery-filters">
      <a href="<?= BASE_URL ?>/gallery.php" class="gf-btn<?= $filter === '' ? ' active' : '' ?>">All</a>
      <?php foreach ($validCategories as $cat): ?>
        <a href="<?= BASE_URL ?>/gallery.php?occasion=<?= e($cat) ?>" class="gf-btn<?= $filter === $cat ? ' active' : '' ?>"><?= e(ucfirst($cat)) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($items)): ?>
      <p style="color:#5b564f;">No looks in this category yet — check back soon.</p>
    <?php else: ?>
    <div class="fabric-grid" style="grid-template-columns: repeat(3, 1fr);">
      <?php foreach ($items as $item): ?>
      <div class="fabric-card" style="cursor:default;">
        <img class="swatch-img" style="aspect-ratio:4/3;" src="<?= e($item['image_url']) ?>" alt="<?= e(ucfirst($item['occasion_category'])) ?> look">
        <div class="fc-info">
          <div class="fc-name"><?= e(ucfirst($item['occasion_category'])) ?><?= $item['client_name_display'] ? ' — ' . e($item['client_name_display']) : '' ?></div>
          <?php if ($item['client_story']): ?>
            <p style="font-size:13.5px; color:#5b564f; margin-top:6px;"><?= e($item['client_story']) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
