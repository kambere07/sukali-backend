<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['success' => false, 'message' => 'Méthode non autorisée.']);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) reply(400, ['success' => false, 'message' => 'Requête invalide.']);

$config = require_once __DIR__ . '/config.php';
$dbConfig = $config['db'];

$host = $dbConfig['host'];
$database = $dbConfig['database'];
$dbUser = $dbConfig['user'];
$dbPassword = $dbConfig['password'];

$action = (string)($input['action'] ?? '');
$pin = trim((string)($input['code_pin'] ?? ''));
if ($action !== 'recover' && !preg_match('/^[0-9]{4,6}$/', $pin)) {
    reply(422, ['success' => false, 'message' => 'Le code PIN doit contenir 4 à 6 chiffres.']);
}

try {
    $db = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($action === 'recover') {
        reply(501, ['success' => false, 'message' => 'Contactez le centre Sukali pour réinitialiser votre code PIN.']);
    }

    if ($action === 'register') {
        $nom = trim((string)($input['nom'] ?? ''));
        $prenom = trim((string)($input['prenom'] ?? ''));
        $phone = trim((string)($input['telephone'] ?? ''));
        $sexe = (string)($input['sexe'] ?? '');
        $diabetes = (string)($input['type_diabete'] ?? '');
        if ($nom === '' || $prenom === '' || !preg_match('/^\+?[0-9]{8,20}$/', $phone) ||
            !in_array($sexe, ['M', 'F'], true) ||
            !in_array($diabetes, ['TYPE_1', 'TYPE_2', 'GESTATIONNEL'], true)) {
            reply(422, ['success' => false, 'message' => 'Vérifiez les informations du profil.']);
        }

        $db->beginTransaction();
        // Le schéma sukali_db en service impose encore date_naissance.
        $stmt = $db->prepare("INSERT INTO patient (nom, prenom, telephone, sexe, date_naissance, type_diabete)
            VALUES (?, ?, ?, ?, '2000-01-01', ?)");
        $stmt->execute([$nom, $prenom, $phone, $sexe, $diabetes]);
        $patientId = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO comptes_patients (patient_id, code_pin, statut_compte) VALUES (?, ?, 'ACTIF')");
        $stmt->execute([$patientId, $pin]);
        $displayName = "$prenom $nom";
        $diabetesType = $diabetes;
        $patientSex = $sexe;
    } elseif ($action === 'login') {
        $stmt = $db->prepare("SELECT c.patient_id, p.nom_complet, p.type_diabete, p.sexe
            FROM comptes_patients c JOIN patient p ON p.patient_id = c.patient_id
            WHERE c.code_pin = ? AND c.statut_compte = 'ACTIF' LIMIT 1");
        $stmt->execute([$pin]);
        $account = $stmt->fetch();
        if (!$account) reply(401, ['success' => false, 'message' => 'Code PIN incorrect ou compte introuvable.']);
        $patientId = (int)$account['patient_id'];
        $displayName = (string)$account['nom_complet'];
        $diabetesType = (string)$account['type_diabete'];
        $patientSex = (string)$account['sexe'];
    } else {
        reply(400, ['success' => false, 'message' => 'Action invalide.']);
    }

    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO api_sessions (patient_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))');
    $stmt->execute([$patientId, hash('sha256', $token)]);
    if ($db->inTransaction()) $db->commit();
    reply($action === 'register' ? 201 : 200, [
        'success' => true,
        'patient_id' => $patientId,
        'nom_patient' => $displayName,
        'type_diabete' => $diabetesType,
        'sexe' => $patientSex,
        'access_token' => $token,
    ]);
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('auth.php: ' . $e->getMessage());
    if ($e->getCode() === '23000') {
        reply(409, ['success' => false, 'message' => 'Ce téléphone ou ce code PIN est déjà utilisé.']);
    }
    reply(503, ['success' => false, 'message' => 'Service temporairement indisponible. Vérifiez la configuration MySQL. ' . $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('auth.php: ' . $e->getMessage());
    reply(500, ['success' => false, 'message' => 'Impossible de traiter la demande.']);
}
