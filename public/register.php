<?php
require_once __DIR__ . '/../config/bootstrap.php';

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullName === '' || $phone === '' || $password === '') {
            $errors[] = 'Name, phone, and password are required.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if (empty($errors)) {
            $stmt = $db->prepare('SELECT id FROM clients WHERE phone = :phone');
            $stmt->execute(['phone' => $phone]);
            if ($stmt->fetch()) {
                $errors[] = 'An account with that phone number already exists.';
            }
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare(
                'INSERT INTO clients (full_name, email, phone, password_hash, country, timezone, preferred_currency)
                 VALUES (:name, :email, :phone, :hash, :country, :tz, :currency)'
            );
            $stmt->execute([
                'name'     => $fullName,
                'email'    => $email !== '' ? $email : null,
                'phone'    => $phone,
                'hash'     => $hash,
                'country'  => 'Ghana',
                'tz'       => 'Africa/Accra',
                'currency' => 'GHS',
            ]);

            $_SESSION['client_id'] = (int) $db->lastInsertId();
            flash('success', 'Welcome! Your account has been created.');
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
    }
}

$pageTitle = 'Create Account';
require __DIR__ . '/../app/views/partials/header.php';
?>
<section class="page-section">
    <div class="wrap">
        <div class="form-card">
            <h2>Create Your Account</h2>
            <?php foreach ($errors as $err): ?>
                <div class="flash flash-error"><?= e($err) ?></div>
            <?php endforeach; ?>
            <form method="post" action="<?= BASE_URL ?>/register.php">
                <?= csrf_field() ?>
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>">

                <label for="phone">Phone (WhatsApp number)</label>
                <input type="text" id="phone" name="phone" placeholder="+233..." required value="<?= e($_POST['phone'] ?? '') ?>">

                <label for="email">Email (optional)</label>
                <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>">

                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8">

                <div class="form-actions">
                    <button type="submit" class="btn">Create Account</button>
                </div>
                <p class="form-note">Already have an account? <a href="<?= BASE_URL ?>/login.php">Log in</a></p>
            </form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
