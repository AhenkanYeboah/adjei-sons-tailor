<?php
/**
 * Database connection (PDO, prepared statements only).
 * Adjust these four constants for your environment, or better,
 * load them from a .env file that is NOT committed to version control.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'bespoke_tailor');
define('DB_USER', 'CHANGE_ME_db_user');
define('DB_PASS', 'CHANGE_ME_db_password');

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never leak DB credentials or raw exception details to the browser.
            error_log('DB connection failed: ' . $e->getMessage());
            die('Sorry, something went wrong connecting to the database. Please try again shortly.');
        }
    }

    return $pdo;
}
