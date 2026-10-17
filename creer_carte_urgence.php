<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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
$action = is_array($input) ? (string)($input['action'] ?? '') : '';
if (!in_array($action, ['status', 'issue', 'disable'], true)) {
    reply(400, ['success' => false, 'message' => 'Action invalide.']);
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/^Bearer ([a-f0-9]{64})$/', $authorization, $matches)) {
    reply(401, ['success' => false, 'message' => 'Reconnectez-vous pour préparer votre carte.']);
}

$config = require_once __DIR__ . '/config.php';
$dbConfig = $config['db'];

$host = $dbConfig['host'];
$database = $dbConfig['database'];
$dbUser = $dbConfig['user'];
$dbPassword = $dbConfig['password'];

try {
    $db = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $session = $db->prepare('SELECT patient_id FROM api_sessions WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $session->execute([hash('sha256', $matches[1])]);
    $patientId = $session->fetchColumn();
    if (!$patientId) reply(401, ['success' => false, 'message' => 'Session expirée. Reconnectez-vous.']);

    if ($action === 'status') {
        $lookup = $db->prepare('SELECT public_token FROM cartes_urgence WHERE patient_id = ? AND is_active = 1 LIMIT 1');
        $lookup->execute([(int)$patientId]);
        $token = $lookup->fetchColumn();
        if (!is_string($token)) reply(200, ['success' => true, 'active' => false]);
        reply(200, ['success' => true, 'active' => true, 'token' => $token]);
    }

    if ($action === 'disable') {
        $disable = $db->prepare('UPDATE cartes_urgence SET is_active=0 WHERE patient_id=?');
        $disable->execute([(int)$patientId]);
        reply(200, ['success' => true, 'active' => false]);
    }

    // INSERT IGNORE makes simultaneous requests converge on the same permanent token.
    $candidate = bin2hex(random_bytes(16));
    $insert = $db->prepare('INSERT IGNORE INTO cartes_urgence (patient_id, public_token) VALUES (?, ?)');
    $insert->execute([(int)$patientId, $candidate]);
    $check = $db->prepare('SELECT is_active FROM cartes_urgence WHERE patient_id=? LIMIT 1');
    $check->execute([(int)$patientId]);
    if ((int)$check->fetchColumn() === 0) {
        $rotate = $db->prepare('UPDATE cartes_urgence SET public_token=?, is_active=1 WHERE patient_id=?');
        $rotate->execute([$candidate, (int)$patientId]);
    }
    $lookup = $db->prepare('SELECT public_token FROM cartes_urgence WHERE patient_id = ? AND is_active = 1 LIMIT 1');
    $lookup->execute([(int)$patientId]);
    $token = $lookup->fetchColumn();
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        reply(409, ['success' => false, 'message' => 'La carte d’urgence est désactivée. Contactez Sukali.']);
    }
    reply(200, ['success' => true, 'active' => true, 'token' => $token]);
} catch (Throwable $e) {
    error_log('creer_carte_urgence.php: ' . $e->getMessage());
    reply(503, ['success' => false, 'message' => 'Service indisponible. Vérifiez la migration carte_urgence.sql sur sukali_db.']);
}
