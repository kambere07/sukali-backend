<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) { http_response_code(405); exit; }
function reply(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function recordDateTime(mixed $value): ?string {
    if (!is_string($value) || $value === '') return null;
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Africa/Kinshasa'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}
function recordNumber(mixed $value): ?float {
    return is_numeric($value) ? (float)$value : null;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/^Bearer ([a-f0-9]{64})$/', $auth, $matches)) reply(401, ['success' => false, 'message' => 'Session requise.']);
$configPath = getenv('SUKALI_CONFIG_FILE') ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'sukali.php';
$privateConfig = is_file($configPath) ? require $configPath : [];
if (!is_array($privateConfig)) $privateConfig = [];
$host = getenv('SUKALI_DB_HOST') ?: ($privateConfig['db_host'] ?? '127.0.0.1');
$database = getenv('SUKALI_DB_NAME') ?: ($privateConfig['db_name'] ?? 'sukali_db');
$dbUser = getenv('SUKALI_DB_USER') ?: ($privateConfig['db_user'] ?? 'root');
$dbPassword = getenv('SUKALI_DB_PASSWORD') ?: ($privateConfig['db_password'] ?? '');
try {
    $db = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmt = $db->prepare('SELECT patient_id FROM api_sessions WHERE token_hash=? AND expires_at > UTC_TIMESTAMP()');
    $stmt->execute([hash('sha256', $matches[1])]);
    $patientId = $stmt->fetchColumn();
    if (!$patientId) reply(401, ['success' => false, 'message' => 'Session expirée.']);
} catch (Throwable $e) {
    reply(503, ['success' => false, 'message' => 'Service temporairement indisponible.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $db->prepare('SELECT record_type, record_id, record_json FROM diabtrack_records WHERE patient_id=? ORDER BY updated_at ASC');
        $stmt->execute([$patientId]);
        $remote = ['profile' => null, 'readings' => [], 'meals' => [], 'insulinEntries' => [], 'hba1c' => [], 'reminders' => [], 'vitals' => [], 'notifications' => [], 'emergencyEvents' => [], 'contacts' => [], 'messages' => [], 'facilities' => [], 'shareSessions' => []];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode($row['record_json'], true);
            if ($row['record_type'] === 'profile') $remote['profile'] = $decoded;
            elseif (isset($remote[$row['record_type']]) && is_array($decoded)) $remote[$row['record_type']][] = $decoded;
        }
        $demographicsStmt = $db->prepare('SELECT sexe, type_diabete FROM patient WHERE patient_id=? LIMIT 1');
        $demographicsStmt->execute([(int)$patientId]);
        $demographics = $demographicsStmt->fetch() ?: [];
        reply(200, ['success' => true, 'records' => $remote, 'patient' => $demographics]);
    } catch (Throwable $e) {
        reply(500, ['success' => false, 'message' => 'Lecture du dossier impossible.']);
    }
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) reply(400, ['success' => false, 'message' => 'Requête invalide.']);
$groups = ['readings' => 'id', 'meals' => 'id', 'insulinEntries' => 'id', 'hba1c' => 'id', 'reminders' => 'id', 'vitals' => 'id', 'notifications' => 'id', 'emergencyEvents' => 'id', 'contacts' => 'id', 'messages' => 'id', 'facilities' => 'id', 'shareSessions' => 'code'];
$allowed = ['profile' => 'profile'];
foreach ($groups as $group => $_idField) $allowed[$group] = $group;
$records = [];
if (isset($input['profile']) && is_array($input['profile'])) $records[] = ['profile', 'profile', $input['profile']];
foreach ($groups as $group => $idField) {
    if (!isset($input[$group])) continue;
    if (!is_array($input[$group]) || count($input[$group]) > 10000) reply(422, ['success' => false, 'message' => 'Dossier trop volumineux ou invalide.']);
    foreach ($input[$group] as $item) {
        if (!is_array($item) || !isset($item[$idField]) || !is_string($item[$idField]) || strlen($item[$idField]) > 80) {
            reply(422, ['success' => false, 'message' => 'Une entrée du dossier est invalide.']);
        }
        $records[] = [$allowed[$group], $item[$idField], $item];
    }
}
if (count($records) > 20000) reply(413, ['success' => false, 'message' => 'Dossier trop volumineux.']);

try {
    $db->beginTransaction();
    $upsert = $db->prepare('INSERT INTO diabtrack_records (patient_id, record_type, record_id, record_json) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE record_json=VALUES(record_json)');
    $upsertGlucose = $db->prepare('INSERT INTO glycemie
        (patient_id, taux_glycemie, moment, commentaire, date_heure, diabtrack_record_id)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE taux_glycemie=VALUES(taux_glycemie), moment=VALUES(moment),
          commentaire=VALUES(commentaire), date_heure=VALUES(date_heure)');
    $upsertMeal = $db->prepare('INSERT INTO repas_nutrition
        (patient_id, type_repas, glucides_grammes, description_repas, date_heure, diabtrack_record_id)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE type_repas=VALUES(type_repas), glucides_grammes=VALUES(glucides_grammes),
          description_repas=VALUES(description_repas), date_heure=VALUES(date_heure)');
    $upsertInsulin = $db->prepare('INSERT INTO injections_traitements
        (patient_id, type_traitement, nom_medicament_insuline, dose_valeur, unite,
         statut_prise, date_heure, commentaire, diabtrack_record_id)
        VALUES (?, ?, ?, ?, \'UI\', \'PRIS\', ?, ?, ?)
        ON DUPLICATE KEY UPDATE type_traitement=VALUES(type_traitement),
          nom_medicament_insuline=VALUES(nom_medicament_insuline), dose_valeur=VALUES(dose_valeur),
          date_heure=VALUES(date_heure), commentaire=VALUES(commentaire)');
    $upsertHba1c = $db->prepare('INSERT INTO analyses_labo
        (patient_id, hba1c_pourcentage, date_analyse, diabtrack_record_id)
        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE
          hba1c_pourcentage=VALUES(hba1c_pourcentage), date_analyse=VALUES(date_analyse)');
    $upsertVital = $db->prepare('INSERT INTO constantes_vitales
        (patient_id, frequence_cardiaque, spo2, temperature, date_heure, diabtrack_record_id)
        VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE
          frequence_cardiaque=VALUES(frequence_cardiaque), spo2=VALUES(spo2),
          temperature=VALUES(temperature), date_heure=VALUES(date_heure)');
    $upsertReminder = $db->prepare('INSERT INTO rappels
        (patient_id, titre, type_rappel, heure_rappel, jours_repetition, est_actif, diabtrack_record_id)
        VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE titre=VALUES(titre),
          type_rappel=VALUES(type_rappel), heure_rappel=VALUES(heure_rappel),
          jours_repetition=VALUES(jours_repetition), est_actif=VALUES(est_actif)');
    $upsertContact = $db->prepare('INSERT INTO contacts_urgence
        (patient_id, nom_contact, relation, telephone, diabtrack_record_id)
        VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nom_contact=VALUES(nom_contact),
          relation=VALUES(relation), telephone=VALUES(telephone)');
    $upsertEmergency = $db->prepare('INSERT INTO alertes_urgence
        (patient_id, statut, date_declenchement, diabtrack_record_id)
        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE statut=VALUES(statut),
          date_declenchement=VALUES(date_declenchement)');
    $upsertMessage = $db->prepare('INSERT INTO messages_patient
        (patient_id, message, date_envoi, diabtrack_record_id)
        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE message=VALUES(message), date_envoi=VALUES(date_envoi)');
    foreach ($records as [$type, $id, $record]) {
        $upsert->execute([$patientId, $type, $id, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        if ($type === 'profile') {
            $profileType = (string)($record['diabetesType'] ?? '');
            $dbType = match ($profileType) {
                'type1' => 'TYPE_1', 'type2' => 'TYPE_2', default => null,
            };
            if ($dbType !== null) {
                $updatePatientType = $db->prepare('UPDATE patient SET type_diabete=? WHERE patient_id=?');
                $updatePatientType->execute([$dbType, (int)$patientId]);
            }
        } elseif ($type === 'readings') {
            $dateTime = recordDateTime($record['dateTime'] ?? null);
            $value = recordNumber($record['valueMgdl'] ?? null);
            if ($dateTime !== null && $value !== null) {
                $contexts = ['fasting' => 'À jeun', 'beforeMeal' => 'Avant repas',
                    'afterMeal' => 'Après repas', 'bedtime' => 'Au coucher',
                    'beforeSport' => 'Avant effort', 'afterSport' => 'Après effort', 'other' => 'Autre'];
                $context = (string)($record['context'] ?? 'other');
                $upsertGlucose->execute([$patientId, $value, $contexts[$context] ?? 'Autre',
                    (string)($record['note'] ?? ''), $dateTime, $id]);
            }
        } elseif ($type === 'meals') {
            $dateTime = recordDateTime($record['dateTime'] ?? null);
            $carbs = recordNumber($record['carbsG'] ?? null);
            if ($dateTime !== null && $carbs !== null) {
                $mealTypes = ['breakfast' => 'PETIT_DEJEUNER', 'lunch' => 'DEJEUNER',
                    'snack' => 'COLLATION', 'dinner' => 'DINER', 'supper' => 'DINER', 'other' => 'COLLATION'];
                $mealType = (string)($record['type'] ?? 'other');
                $upsertMeal->execute([$patientId, $mealTypes[$mealType] ?? 'COLLATION', $carbs,
                    (string)($record['description'] ?? ''), $dateTime, $id]);
            }
        } elseif ($type === 'insulinEntries') {
            $dateTime = recordDateTime($record['dateTime'] ?? null);
            $dose = recordNumber($record['units'] ?? null);
            if ($dateTime !== null && $dose !== null) {
                $kind = ($record['kind'] ?? '') === 'basal' ? 'INSULINE_LENTE' : 'INSULINE_RAPIDE';
                $product = trim((string)($record['productName'] ?? '')) ?: 'Insuline non précisée';
                $note = trim((string)($record['note'] ?? ''));
                if (!empty($record['isCorrection'])) $note = trim($note . ' (correction)');
                $upsertInsulin->execute([$patientId, $kind, $product, $dose, $dateTime, $note, $id]);
            }
        } elseif ($type === 'hba1c') {
            $date = recordDateTime($record['date'] ?? null);
            $value = recordNumber($record['valuePercent'] ?? null);
            if ($date !== null && $value !== null) {
                $upsertHba1c->execute([$patientId, $value, substr($date, 0, 10), $id]);
            }
        } elseif ($type === 'vitals') {
            $dateTime = recordDateTime($record['dateTime'] ?? null);
            if ($dateTime !== null) {
                $heartRate = recordNumber($record['heartRateBpm'] ?? null);
                $upsertVital->execute([$patientId, $heartRate === null ? null : (int)round($heartRate),
                    recordNumber($record['spo2Percent'] ?? null), recordNumber($record['temperatureC'] ?? null),
                    $dateTime, $id]);
            }
        } elseif ($type === 'reminders') {
            $hour = filter_var($record['hour'] ?? null, FILTER_VALIDATE_INT);
            $minute = filter_var($record['minute'] ?? null, FILTER_VALIDATE_INT);
            if ($hour !== false && $minute !== false && $hour >= 0 && $hour < 24 && $minute >= 0 && $minute < 60) {
                $kinds = ['measurement' => 'GLYCEMIE', 'medication' => 'INSULINE',
                    'appointment' => 'RDV_MEDECIN', 'hydration' => 'HYDRATATION', 'custom' => 'GLYCEMIE'];
                $kind = (string)($record['kind'] ?? 'custom');
                $days = $record['days'] ?? [];
                $days = is_array($days) ? implode(',', array_map('intval', $days)) : '';
                $title = trim((string)($record['label'] ?? '')) ?: $kind;
                $upsertReminder->execute([$patientId, $title, $kinds[$kind] ?? 'GLYCEMIE',
                    sprintf('%02d:%02d:00', $hour, $minute), $days,
                    !empty($record['enabled']) ? 1 : 0, $id]);
            }
        } elseif ($type === 'contacts') {
            $name = trim((string)($record['name'] ?? ''));
            if ($name !== '') {
                $phone = trim((string)($record['phone'] ?? '')) ?: 'Non renseigné';
                $relation = trim((string)($record['role'] ?? ''));
                $upsertContact->execute([$patientId, $name, $relation, $phone, $id]);
            }
        } elseif ($type === 'emergencyEvents') {
            $dateTime = recordDateTime($record['triggeredAt'] ?? null);
            if ($dateTime !== null) {
                $status = !empty($record['resolvedAt']) ? 'TRAITE' : 'ENVOYE';
                $upsertEmergency->execute([$patientId, $status, $dateTime, $id]);
            }
        } elseif ($type === 'messages') {
            $dateTime = recordDateTime($record['sentAt'] ?? null);
            $message = trim((string)($record['text'] ?? ''));
            if ($dateTime !== null && $message !== '') {
                $upsertMessage->execute([$patientId, $message, $dateTime, $id]);
            }
        }
    }
    // La page publique de la carte affiche quand le dossier a été synchronisé pour la dernière fois.
    $touchEmergencyCard = $db->prepare('UPDATE cartes_urgence SET updated_at=CURRENT_TIMESTAMP WHERE patient_id=? AND is_active=1');
    $touchEmergencyCard->execute([(int)$patientId]);
    $db->commit();
    reply(200, ['success' => true, 'synced' => count($records)]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    reply(500, ['success' => false, 'message' => 'Synchronisation non enregistrée.']);
}
