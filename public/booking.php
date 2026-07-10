<?php
require_once __DIR__ . '/../config/bootstrap.php';

$db = getDB();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $bookingType = $_POST['booking_type'] ?? '';
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $validTypes = ['video_measurement', 'consultation', 'in_person_fitting'];
        if (!in_array($bookingType, $validTypes, true)) {
            $errors[] = 'Please choose a valid booking type.';
        }
        if ($fullName === '' || $phone === '') {
            $errors[] = 'Name and phone number are required.';
        }

        $slotDateTime = null;
        if ($date !== '' && $time !== '') {
            $slotDateTime = DateTime::createFromFormat('Y-m-d H:i', "$date $time");
            if (!$slotDateTime || $slotDateTime < new DateTime('now')) {
                $errors[] = 'Please choose a valid future date and time.';
            }
        } else {
            $errors[] = 'Please choose a date and time.';
        }

        if (empty($errors)) {
            $clientId = current_client_id();

            // Guest booking: find or create a lightweight client record by phone.
            if (!$clientId) {
                $stmt = $db->prepare('SELECT id FROM clients WHERE phone = :phone');
                $stmt->execute(['phone' => $phone]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $clientId = (int) $existing['id'];
                } else {
                    $randomPass = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                    $stmt = $db->prepare(
                        'INSERT INTO clients (full_name, phone, password_hash, country, timezone, preferred_currency)
                         VALUES (:name, :phone, :hash, :country, :tz, :currency)'
                    );
                    $stmt->execute([
                        'name'     => $fullName,
                        'phone'    => $phone,
                        'hash'     => $randomPass,
                        'country'  => 'Ghana',
                        'tz'       => 'Africa/Accra',
                        'currency' => 'GHS',
                    ]);
                    $clientId = (int) $db->lastInsertId();
                }
            }

            $stmt = $db->prepare(
                'INSERT INTO bookings (client_id, booking_type, slot_datetime_utc, duration_minutes, status)
                 VALUES (:client_id, :type, :slot, :duration, :status)'
            );
            $stmt->execute([
                'client_id' => $clientId,
                'type'      => $bookingType,
                'slot'      => $slotDateTime->format('Y-m-d H:i:s'),
                'duration'  => $bookingType === 'video_measurement' ? 30 : 20,
                'status'    => 'scheduled',
            ]);

            // Queue a WhatsApp confirmation, mirroring how order status changes notify.
            $stmt = $db->prepare(
                'INSERT INTO whatsapp_notifications (client_id, related_booking_id, message_type, message_body, status)
                 VALUES (:client_id, :booking_id, :type, :body, :status)'
            );
            $stmt->execute([
                'client_id'  => $clientId,
                'booking_id' => (int) $db->lastInsertId(),
                'type'       => 'booking_reminder',
                'body'       => 'Your ' . str_replace('_', ' ', $bookingType) . ' is booked for ' . $slotDateTime->format('D, M j \a\t g:i A') . '. We\'ll send a reminder beforehand.',
                'status'     => 'queued',
            ]);

            $success = true;
        }
    }
}

$pageTitle = 'Book a Consultation';
require __DIR__ . '/../app/views/partials/header.php';
?>

<section class="page-section">
  <div class="wrap">
    <div class="form-card" style="max-width:560px;">
      <h2>Book a Free Consultation</h2>
      <p style="color:#5b564f; font-size:14.5px; margin-bottom:10px;">Guided video call, in-person fitting, or a quick chat about your design — no shop visit required to get started.</p>

      <?php if ($success): ?>
        <div class="flash flash-success">Booked! We'll send a WhatsApp confirmation shortly.</div>
      <?php else: ?>
        <?php foreach ($errors as $err): ?>
          <div class="flash flash-error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= BASE_URL ?>/booking.php">
          <?= csrf_field() ?>

          <label for="booking_type">Type of Appointment</label>
          <select id="booking_type" name="booking_type" required>
            <option value="consultation" <?= ($_POST['booking_type'] ?? '') === 'consultation' ? 'selected' : '' ?>>Free Consultation</option>
            <option value="video_measurement" <?= ($_POST['booking_type'] ?? '') === 'video_measurement' ? 'selected' : '' ?>>Video Measurement Call</option>
            <option value="in_person_fitting" <?= ($_POST['booking_type'] ?? '') === 'in_person_fitting' ? 'selected' : '' ?>>In-Person Fitting (Accra studio)</option>
          </select>

          <label for="full_name">Full Name</label>
          <input type="text" id="full_name" name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>">

          <label for="phone">WhatsApp Number</label>
          <input type="text" id="phone" name="phone" placeholder="+233..." required value="<?= e($_POST['phone'] ?? '') ?>">

          <label for="date">Date</label>
          <input type="date" id="date" name="date" required min="<?= date('Y-m-d') ?>" value="<?= e($_POST['date'] ?? '') ?>">

          <label for="time">Time</label>
          <input type="time" id="time" name="time" required value="<?= e($_POST['time'] ?? '') ?>">

          <div class="form-actions">
            <button type="submit" class="btn-primary">Confirm Booking</button>
          </div>
          <p class="form-note">Times shown are Africa/Accra (GMT). We'll confirm by WhatsApp.</p>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
