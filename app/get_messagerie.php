<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization');

function reply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') reply(405, ['success' => false, 'message' => 'Méthode non autorisée.']);

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/^Bearer ([a-f0-9]{64})$/', $authorization, $matches)) {
    reply(401, ['success' => false, 'message' => 'Session requise.']);
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
    $stmt = $db->prepare('SELECT patient_id FROM api_sessions WHERE token_hash=? AND expires_at > UTC_TIMESTAMP()');
    $stmt->execute([hash('sha256', $matches[1])]);
    $patientId = (int)$stmt->fetchColumn();
    if ($patientId < 1) reply(401, ['success' => false, 'message' => 'Session expirée.']);

    $stmt = $db->prepare('SELECT id, nom_expediteur, role_expediteur, message, type_message, date_envoi
        FROM messages_consultation WHERE patient_id=? ORDER BY date_envoi ASC, id ASC');
    $stmt->execute([$patientId]);
    reply(200, ['success' => true, 'messages' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('get_messagerie.php: ' . $e->getMessage());
    reply(503, ['success' => false, 'message' => 'Messagerie temporairement indisponible.']);
}
