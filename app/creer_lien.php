<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/^Bearer ([a-f0-9]{64})$/', $authorization, $matches)) {
    reply(401, ['success' => false, 'message' => 'Reconnectez-vous pour créer un lien.']);
}

$config = require_once __DIR__ . '/config.php';
$dbConfig = $config['db'];

$host = $dbConfig['host'];
$database = $dbConfig['database'];
$dbUser = $dbConfig['user'];
$dbPassword = $dbConfig['password'];

$duration = filter_var($_POST['duree_minutes'] ?? 10, FILTER_VALIDATE_INT);
if (!in_array($duration, [5, 10, 20], true)) {
    reply(422, ['success' => false, 'message' => 'La durée doit être de 5, 10 ou 20 minutes.']);
}

try {
    $db = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $session = $db->prepare('SELECT patient_id FROM api_sessions WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1');
    $session->execute([hash('sha256', $matches[1])]);
    $patientId = $session->fetchColumn();
    if (!$patientId) {
        reply(401, ['success' => false, 'message' => 'Session expirée. Reconnectez-vous.']);
    }

    $db->exec("UPDATE liens_consultation SET statut='EXPIRE' WHERE statut='ACTIF' AND date_expiration <= NOW()");
    $token = bin2hex(random_bytes(32));
    $insert = $db->prepare("INSERT INTO liens_consultation
        (patient_id, rapport_id, token, duree_minutes, date_expiration, statut)
        VALUES (?, NULL, ?, ?, DATE_ADD(NOW(), INTERVAL {$duration} MINUTE), 'ACTIF')");
    $insert->execute([(int)$patientId, $token, $duration]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($requestHost === '') throw new RuntimeException('Hôte absent.');
    $apiPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/API/creer_lien.php')), '/');
    $link = $scheme . '://' . $requestHost . $apiPath . '/rapport_consultation.php?token=' . rawurlencode($token);
    reply(200, ['success' => true, 'token' => $token, 'lien' => $link]);
} catch (Throwable $e) {
    error_log('creer_lien.php: ' . $e->getMessage());
    reply(500, ['success' => false, 'message' => 'Impossible de créer le lien de consultation.']);
}
