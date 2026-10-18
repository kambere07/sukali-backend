<?php
// api/fermer_session.php
try {
    $config = require_once __DIR__ . '/config.php';
    $dbConfig = $config['db'];

    $host = $dbConfig['host'];
    $database = $dbConfig['database'];
    $dbUser = $dbConfig['user'];
    $dbPassword = $dbConfig['password'];

    $db = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    exit;
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';

if (!empty($token)) {
    $stmt = $db->prepare("UPDATE liens_consultation SET statut = 'EXPIRE' WHERE token = :token");
    $stmt->execute([':token' => $token]);
}
?>