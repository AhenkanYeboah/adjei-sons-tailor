<?php
// db.php - FIXED VERSION
$url = getenv('MYSQL_URL');

// For debugging - show what URL is being used (remove in production)
echo "DEBUG: Using URL: " . str_replace($url, '***HIDDEN***', $url) . "<br>";

// If the URL from Railway has the wrong password, override it here temporarily
// Get the password from Railway's MySQL Variables tab
$correct_password = "kwtQxwrjEHAXzCFuWhtuLqEmGptDqMEu"; // <-- PUT THE REAL ONE HERE

// If using MYSQL_URL, replace the password in it
if ($url && strpos($url, 'mysql://') === 0) {
    // Parse the URL
    $parsed = parse_url($url);
    
    // Replace with correct password
    $url = "mysql://" . $parsed['user'] . ":" . $correct_password . "@" . $parsed['host'] . ":" . ($parsed['port'] ?? 47698) . "/" . ltrim($parsed['path'] ?? '/railway', '/');
    
    echo "DEBUG: Using corrected password<br>";
}

// Fallback for local (if no MYSQL_URL)
if (!$url) {
    $url = "mysql://root:" . $correct_password . "@hayabusa.proxy.rlwy.net:47698/railway";
}

$parsed = parse_url($url);
$host = $parsed['host'];
$port = $parsed['port'] ?? 3306;
$db   = ltrim($parsed['path'] ?? '/railway', '/');
$user = $parsed['user'] ?? 'root';
$pass = $parsed['pass'] ?? '';

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_SSL_CA => null,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false
    ]);
    echo "✅ Connected successfully to Railway DB: $db";
} catch (PDOException $e) {
    die("❌ REAL DB ERROR: " . $e->getMessage());
}
?>