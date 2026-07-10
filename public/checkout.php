<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../app/services/WaitTimeCalculator.php';
require_once __DIR__ . '/../app/services/ExpressSlotManager.php';
require_once __DIR__ . '/../app/services/OrderStatusService.php';

require_login();

$db = getDB();
$clientId = current_client_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/configurator.php');
    exit;
}

if (!csrf_verify()) {
    flash('error', 'Your session expired. Please try again.');
    header('Location: ' . BASE_URL . '/configurator.php');
    exit;
}

$garmentTypeId = (int) ($_POST['garment_type_id'] ?? 0);
$fabricId = (int) ($_POST['fabric_id'] ?? 0);
$monogramText = trim($_POST['monogram_text'] ?? '');

$stmt = $db->prepare('SELECT * FROM garment_types WHERE id = :id AND is_active = 1');
$stmt->execute(['id' => $garmentTypeId]);
$garment = $stmt->fetch();

$stmt = $db->prepare("SELECT * FROM fabrics WHERE id = :id AND stock_status != 'out_of_stock'");
$stmt->execute(['id' => $fabricId]);
$fabric = $stmt->fetch();

if (!$garment || !$fabric) {
    flash('error', 'Please choose a valid garment and fabric.');
    header('Location: ' . BASE_URL . '/configurator.php');
    exit;
}

// Collect chosen style options and compute their price contribution server-side
// (never trust a client-submitted total).
$styleConfig = [];
$stylePriceTotal = 0.00;
$categories = ['cut', 'lining', 'buttons', 'collar', 'cuff', 'monogram'];

foreach ($categories as $cat) {
    $key = 'style_' . $cat;
    if (!empty($_POST[$key])) {
        $optId = (int) $_POST[$key];
        $stmt = $db->prepare('SELECT * FROM style_options WHERE id = :id AND category = :cat AND is_active = 1');
        $stmt->execute(['id' => $optId, 'cat' => $cat]);
        $opt = $stmt->fetch();
        if ($opt) {
            $styleConfig[$cat] = ['id' => $opt['id'], 'name' => $opt['name']];
            $stylePriceTotal += (float) $opt['price_modifier'];
        }
    }
}

if ($monogramText !== '') {
    // Use mbstring if available (handles multi-byte names correctly);
    // fall back to a plain substr so this doesn't hard-fail on servers
    // without the extension enabled.
    $styleConfig['monogram_text'] = function_exists('mb_substr')
        ? mb_substr($monogramText, 0, 3)
        : substr($monogramText, 0, 3);
}

// A client needs a measurement on file before ordering. In this simplified
// flow we require one to already exist (created via a booking/video call);
// if none exists, send them to book one first rather than silently guessing.
$stmt = $db->prepare('SELECT id FROM measurements WHERE client_id = :client_id ORDER BY recorded_at DESC LIMIT 1');
$stmt->execute(['client_id' => $clientId]);
$measurement = $stmt->fetch();

if (!$measurement) {
    flash('error', 'Please book a measurement call before placing an order.');
    header('Location: ' . BASE_URL . '/booking.php');
    exit;
}

$tier = $_POST['order_tier'] ?? 'standard';
if (!in_array($tier, ['standard', 'express_5day', 'rush_48hr'], true)) {
    $tier = 'standard';
}

$slotMgr = new ExpressSlotManager($db);
if ($tier !== 'standard' && !$slotMgr->hasAvailability($tier)) {
    flash('error', 'That delivery tier is fully booked this week — please choose another.');
    $tier = 'standard';
}

$waitCalc = new WaitTimeCalculator($db);
$promisedDate = $waitCalc->getPromisedDate($tier);

$totalPrice = (float) $garment['base_price'] + (float) $fabric['price_modifier'] + $stylePriceTotal;
$depositAmount = round($totalPrice * 0.5, 2);
$balanceAmount = round($totalPrice - $depositAmount, 2);

$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO orders
           (client_id, garment_type_id, fabric_id, measurement_id, style_config, order_tier,
            status, total_price, deposit_amount, balance_amount, promised_pickup_date)
         VALUES
           (:client_id, :garment_id, :fabric_id, :measurement_id, :style_config, :tier,
            :status, :total, :deposit, :balance, :pickup)'
    );
    $stmt->execute([
        'client_id'      => $clientId,
        'garment_id'     => $garmentTypeId,
        'fabric_id'      => $fabricId,
        'measurement_id' => (int) $measurement['id'],
        'style_config'   => json_encode($styleConfig, JSON_UNESCAPED_UNICODE),
        'tier'           => $tier,
        'status'         => 'received',
        'total'          => $totalPrice,
        'deposit'        => $depositAmount,
        'balance'        => $balanceAmount,
        'pickup'         => $promisedDate,
    ]);

    $orderId = (int) $db->lastInsertId();

    if ($tier !== 'standard') {
        $slotMgr->claimSlot($tier);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('Order creation failed: ' . $e->getMessage());
    flash('error', 'Something went wrong placing your order. Please try again.');
    header('Location: ' . BASE_URL . '/configurator.php');
    exit;
}

// Log the initial status + queue the confirmation notification through
// the same service every later status change uses.
$statusService = new OrderStatusService($db);
$statusService->updateStatus($orderId, 'received');

flash('success', 'Order placed! Pickup expected ' . date('M j, Y', strtotime($promisedDate)) . '. Pay your deposit below to confirm it.');
header('Location: ' . BASE_URL . '/pay.php?order_id=' . $orderId);
exit;
