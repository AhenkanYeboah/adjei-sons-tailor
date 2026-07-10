<?php
require_once __DIR__ . '/../config/bootstrap.php';

$db = getDB();
$error = null;

// Very light rate limiting to slow down brute-force / spam attempts.
$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? [];
$_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'], fn($t) => $t > time() - 600);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (count($_SESSION['login_attempts']) >= 8) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } elseif (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $db->prepare('SELECT id, password_hash FROM clients WHERE phone = :phone');
        $stmt->execute(['phone' => $phone]);
        $client = $stmt->fetch();

        $_SESSION['login_attempts'][] = time();

        if ($client && password_verify($password, $client['password_hash'])) {
            unset($_SESSION['login_attempts']);
            session_regenerate_id(true);
            $_SESSION['client_id'] = (int) $client['id'];
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }

        $error = 'Incorrect phone number or password.';
    }
}

$pageTitle = 'Log In';
require __DIR__ . '/../app/views/partials/header.php';
?>
<section class="page-section">
    <div class="wrap">
        <div class="form-card">
            <h2>Welcome Back</h2>
            <?php if ($error): ?>
                <div class="flash flash-error"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= BASE_URL ?>/login.php">
                <?= csrf_field() ?>
                <label for="phone">Phone Number</label>
                <input type="text" id="phone" name="phone" required autofocus>

                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>

                <div class="form-actions">
                    <button type="submit" class="btn">Log In</button>
                </div>
                <p class="form-note">New here? <a href="<?= BASE_URL ?>/register.php">Create an account</a></p>
            </form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../app/views/partials/footer.php'; ?>
