<?php
// C:\wamp64\www\sukali\api\enregistrer_patient.php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = new PDO('mysql:host=localhost;dbname=sukali_db;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    $nom = trim($data['nom'] ?? 'MUKENDI');
    $prenom = trim($data['prenom'] ?? 'Claude');
    $age = intval($data['age'] ?? 30);
    $type_diabete = $data['type_diabete'] ?? 'TYPE_1';

    // Insère le patient id = 1 s'il est absent, ou met à jour ses infos
    $stmt = $db->prepare("
        INSERT INTO patient (id, nom, prenom, age, type_diabete)
        VALUES (1, :nom, :prenom, :age, :type_diabete)
        ON DUPLICATE KEY UPDATE nom=:nom, prenom=:prenom, age=:age, type_diabete=:type_diabete
    ");
    $stmt->execute([
        ':nom'          => $nom,
        ':prenom'       => $prenom,
        ':age'          => $age,
        ':type_diabete' => $type_diabete
    ]);

    ob_clean();
    echo json_encode([
        "success" => true,
        "message" => "Profil patient enregistré avec succès dans MySQL !"
    ]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}