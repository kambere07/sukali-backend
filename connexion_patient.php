<?php
// C:\wamp64\www\sukali\api\connexion_patient.php
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

date_default_timezone_set('Africa/Kinshasa');

try {
    $db = new PDO('mysql:host=localhost;dbname=sukali_db;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    $code_pin = trim($data['code_pin'] ?? '');

    if (empty($code_pin)) {
        ob_clean();
        echo json_encode(["success" => false, "message" => "Veuillez saisir votre code PIN."]);
        exit;
    }

    // Vérification du code PIN en joignant la table 'patient'
    $stmt = $db->prepare("
        SELECT c.patient_id, p.nom, p.prenom, p.type_diabete 
        FROM comptes_patient c
        JOIN patient p ON c.patient_id = p.patient_id
        WHERE c.code_pin = :pin AND c.statut_compte = 'ACTIF'
    ");
    $stmt->execute([':pin' => $code_pin]);
    $user = $stmt->fetch();

    if ($user) {
        // Mettre à jour la date de dernière connexion
        $updateStmt = $db->prepare("UPDATE comptes_patients SET derniere_connexion = NOW() WHERE patient_id = :pid");
        $updateStmt->execute([':pid' => $user['patient_id']]);

        ob_clean();
        echo json_encode([
            "success" => true,
            "message" => "Connexion réussie !",
            "patient" => [
                "id" => $user['patient_id'],
                "nom" => $user['nom'],
                "prenom" => $user['prenom'],
                "type_diabete" => $user['type_diabete']
            ]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        ob_clean();
        echo json_encode(["success" => false, "message" => "Code PIN incorrect ou compte introuvable."]);
    }

} catch (Exception $e) {
    ob_clean();
    echo json_encode(["success" => false, "message" => "Erreur serveur : " . $e->getMessage()]);
}