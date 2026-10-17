<?php
// api/fermer_session.php
try {
    $db = new PDO('mysql:host=localhost;dbname=sukali_db;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
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