<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Traitement de la requête OPTIONS pour CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
if (!is_array($input)) {
    reply(400, ['success' => false, 'message' => 'Requête JSON invalide.']);
}

$config = require_once __DIR__ . '/config.php';
$dbConfig = $config['db'];

$host = $dbConfig['host'];
$database = $dbConfig['database'];
$dbUser = $dbConfig['user'];
$dbPassword = $dbConfig['password'];

$action = (string)($input['action'] ?? '');
$pin = trim((string)($input['code_pin'] ?? ''));

// Validation du code PIN (sauf pour la récupération)
if ($action !== 'recover' && !preg_match('/^[0-9]{4,6}$/', $pin)) {
    reply(422, ['success' => false, 'message' => 'Le code PIN doit contenir entre 4 et 6 chiffres.']);
}

try {
    $db = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // ------------------------------------------------------------------------
    // ACTION 1 : RÉCUPÉRATION DU PIN
    // ------------------------------------------------------------------------
    if ($action === 'recover') {
        $nom = trim((string)($input['nom'] ?? ''));
        $phone = trim((string)($input['telephone'] ?? ''));

        if ($nom === '' || $phone === '') {
            reply(422, ['success' => false, 'message' => 'Veuillez remplir votre nom et numéro de téléphone.']);
        }

        $stmt = $db->prepare("
            SELECT c.code_pin 
            FROM comptes_patients c 
            JOIN patient p ON p.patient_id = c.patient_id 
            WHERE LOWER(p.nom) = LOWER(?) AND p.telephone = ? AND c.statut_compte = 'ACTIF'
            LIMIT 1
        ");
        $stmt->execute([$nom, $phone]);
        $account = $stmt->fetch();

        if (!$account) {
            reply(404, ['success' => false, 'message' => 'Compte introuvable avec ces informations.']);
        }

        reply(200, [
            'success' => true,
            'message' => 'Compte retrouvé avec succès.',
            'code_pin' => $account['code_pin']
        ]);
    }

    // ------------------------------------------------------------------------
    // ACTION 2 : INSCRIPTION PATIENT
    // ------------------------------------------------------------------------
    if ($action === 'register') {
        $nom = trim((string)($input['nom'] ?? ''));
        $prenom = trim((string)($input['prenom'] ?? ''));
        $phone = trim((string)($input['telephone'] ?? ''));
        $sexe = (string)($input['sexe'] ?? '');
        $diabetes = (string)($input['type_diabete'] ?? '');

        if ($nom === '' || $prenom === '' || !preg_match('/^\+?[0-9]{8,20}$/', $phone) ||
            !in_array($sexe, ['M', 'F'], true) ||
            !in_array($diabetes, ['TYPE_1', 'TYPE_2', 'GESTATIONNEL'], true)) {
            reply(422, ['success' => false, 'message' => 'Informations du profil invalides ou incomplètes.']);
        }

        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO patient (nom, prenom, telephone, sexe, date_naissance, type_diabete) VALUES (?, ?, ?, ?, '2000-01-01', ?)");
        $stmt->execute([$nom, $prenom, $phone, $sexe, $diabetes]);
        $patientId = (int)$db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO comptes_patients (patient_id, code_pin, statut_compte) VALUES (?, ?, 'ACTIF')");
        $stmt->execute([$patientId, $pin]);

        $displayName = "$prenom $nom";
        $diabetesType = $diabetes;
        $patientSex = $sexe;
    } 

    // ------------------------------------------------------------------------
    // ACTION 3 : CONNEXION PATIENT (TÉLÉPHONE + PIN)
    // ------------------------------------------------------------------------
    elseif ($action === 'login') {
        $phone = trim((string)($input['telephone'] ?? ''));

        if ($phone === '') {
            reply(422, ['success' => false, 'message' => 'Veuillez saisir votre numéro de téléphone.']);
        }

        $stmt = $db->prepare("
            SELECT c.patient_id, p.nom, p.prenom, p.type_diabete, p.sexe
            FROM comptes_patients c 
            JOIN patient p ON p.patient_id = c.patient_id
            WHERE p.telephone = ? AND c.code_pin = ? AND c.statut_compte = 'ACTIF' 
            LIMIT 1
        ");
        $stmt->execute([$phone, $pin]);
        $account = $stmt->fetch();

        if (!$account) {
            reply(401, ['success' => false, 'message' => 'Numéro de téléphone ou code PIN incorrect.']);
        }

        $patientId = (int)$account['patient_id'];
        $displayName = trim($account['prenom'] . ' ' . $account['nom']);
        $diabetesType = (string)$account['type_diabete'];
        $patientSex = (string)$account['sexe'];

        // Mise à jour de la dernière connexion
        $stmtUpdate = $db->prepare("UPDATE comptes_patients SET derniere_connexion = NOW() WHERE patient_id = ?");
        $stmtUpdate->execute([$patientId]);
    } 
    else {
        reply(400, ['success' => false, 'message' => 'Action non reconnue.']);
    }

    // ------------------------------------------------------------------------
    // GÉNÉRATION DU JETON DE SESSION API
    // ------------------------------------------------------------------------
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare('INSERT INTO api_sessions (patient_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))');
    $stmt->execute([$patientId, hash('sha256', $token)]);

    if ($db->inTransaction()) {
        $db->commit();
    }

    reply($action === 'register' ? 201 : 200, [
        'success' => true,
        'patient_id' => $patientId,
        'nom_patient' => $displayName,
        'type_diabete' => $diabetesType,
        'sexe' => $patientSex,
        'access_token' => $token,
    ]);

} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('auth.php PDOError: ' . $e->getMessage());

    if ($e->getCode() === '23000') {
        reply(409, ['success' => false, 'message' => 'Ce numéro de téléphone est déjà associé à un compte.']);
    }

    reply(503, ['success' => false, 'message' => 'Erreur de base de données : ' . $e->getMessage()]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('auth.php Error: ' . $e->getMessage());
    reply(500, ['success' => false, 'message' => 'Erreur interne du serveur.']);
}
