<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?>Adjei &amp; Sons Bespoke Tailoring</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,500;0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<header class="site">
  <div class="wrap nav-row">
    <a href="<?= BASE_URL ?>/index.php" class="wordmark">Adjei &amp; <span>Sons</span></a>
    <nav class="links">
      <a href="<?= BASE_URL ?>/configurator.php">Design</a>
      <a href="<?= BASE_URL ?>/index.php#queue">Wait Time</a>
      <a href="<?= BASE_URL ?>/gallery.php">Gallery</a>
      <?php if (current_client_id()): ?>
        <a href="<?= BASE_URL ?>/dashboard.php">My Account</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php">My Account</a>
      <?php endif; ?>
    </nav>
    <a href="<?= BASE_URL ?>/booking.php" class="nav-cta">Book Consultation</a>
  </div>
</header>

<?php
$flashSuccess = flash('success');
$flashError = flash('error');
?>
<?php if ($flashSuccess || $flashError): ?>
<div class="flash-bar">
  <div class="wrap">
    <?php if ($flashSuccess): ?><div class="flash flash-success"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="flash flash-error"><?= e($flashError) ?></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<main>
