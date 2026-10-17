<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Kinshasa');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pageError(int $status, string $message): never
{
    http_response_code($status);
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Carte médicale Sukali</title><body style="font:16px Arial,sans-serif;background:#f4f8f7;padding:24px;color:#18312d">';
    echo '<main style="max-width:560px;margin:10vh auto;background:white;border-radius:18px;padding:28px;border-top:6px solid #00796b">';
    echo '<h1 style="color:#00796b">SUKALI</h1><h2>Carte médicale indisponible</h2><p>' . escapeHtml($message) . '</p></main></body></html>';
    exit;
}

function readRecords(PDO $db, int $patientId, string $type, int $limit): array
{
    $limit = max(1, min(10, $limit));
    $jsonPath = match ($type) {
        'readings', 'insulinEntries', 'vitals' => '$.dateTime',
        'hba1c' => '$.date',
        default => null,
    };
    $sort = $jsonPath === null
        ? 'updated_at DESC'
        : "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(record_json, '$jsonPath')), updated_at) DESC";
    $stmt = $db->prepare("SELECT record_json FROM diabtrack_records WHERE patient_id=? AND record_type=? ORDER BY $sort LIMIT $limit");
    $stmt->execute([$patientId, $type]);
    $records = [];
    foreach ($stmt->fetchAll() as $row) {
        $record = json_decode((string)$row['record_json'], true);
        if (is_array($record)) $records[] = $record;
    }
    return $records;
}

function displayDate(mixed $value): string
{
    if (!is_string($value) || $value === '') return 'Date non renseignée';
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Africa/Kinshasa'))->format('d/m/Y à H:i');
    } catch (Throwable) {
        return 'Date non renseignée';
    }
}

function patientAge(?string $birthDate, mixed $fallbackAge): ?int
{
    if (is_string($birthDate) && $birthDate !== '') {
        try {
            $birth = new DateTimeImmutable($birthDate);
            $age = $birth->diff(new DateTimeImmutable('today'))->y;
            if ($age >= 0 && $age <= 130) return $age;
        } catch (Throwable) {}
    }
    if (is_numeric($fallbackAge)) {
        $age = (int)$fallbackAge;
        if ($age >= 0 && $age <= 130) return $age;
    }
    return null;
}

function phoneLink(string $phone): string
{
    return preg_replace('/[^0-9+]/', '', $phone) ?? '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') pageError(405, 'Méthode non autorisée.');
$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) pageError(404, 'QR code invalide ou désactivé.');

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
    $stmt = $db->prepare('SELECT p.patient_id, p.nom_complet, p.sexe, p.type_diabete,
            p.groupe_sanguin, p.allergies, p.traitement_principal, c.updated_at
        FROM cartes_urgence c JOIN patient p ON p.patient_id=c.patient_id
        WHERE c.public_token=? AND c.is_active=1 LIMIT 1');
    $stmt->execute([$token]);
    $patient = $stmt->fetch();
    if (!$patient) pageError(404, 'Cette carte n’est pas active ou son lien a été remplacé.');

    $patientId = (int)$patient['patient_id'];
    $profileRecord = readRecords($db, $patientId, 'profile', 1)[0] ?? [];
    $identity = is_array($profileRecord['identity'] ?? null) ? $profileRecord['identity'] : [];
    $name = trim((string)($identity['firstName'] ?? '') . ' ' . (string)($identity['lastName'] ?? ''));
    if ($name === '') $name = trim((string)($patient['nom_complet'] ?? ''));
    if ($name === '' || strcasecmp($name, 'Moi') === 0) {
        $name = trim((string)($profileRecord['displayName'] ?? 'Patient Sukali'));
    }
    $age = patientAge($identity['birthDate'] ?? null, $profileRecord['age'] ?? null);
    $sex = match ((string)($patient['sexe'] ?? '')) { 'M' => 'Homme', 'F' => 'Femme', default => 'Non renseigné' };
    $diabetes = strtolower((string)($profileRecord['diabetesType'] ?? '')) === 'other'
        ? 'Autre / non précisé'
        : match ((string)($patient['type_diabete'] ?? '')) {
        'TYPE_1' => 'Type 1', 'TYPE_2' => 'Type 2', 'GESTATIONNEL' => 'Gestationnel',
        'PREDIABETE' => 'Prédiabète', 'LADA' => 'LADA',
        default => match (strtolower((string)($profileRecord['diabetesType'] ?? ''))) {
            'type1' => 'Type 1', 'type2' => 'Type 2', 'other' => 'Autre / non précisé', default => 'Non renseigné',
        },
    };
    $allergies = trim((string)($identity['allergies'] ?? ''));
    if ($allergies === '') $allergies = trim((string)($patient['allergies'] ?? ''));
    $bloodType = trim((string)($identity['bloodType'] ?? ''));
    if ($bloodType === '') $bloodType = trim((string)($patient['groupe_sanguin'] ?? ''));
    $mainTreatment = trim((string)($patient['traitement_principal'] ?? ''));
    $doctorName = trim((string)($identity['doctorName'] ?? ''));
    $doctorPhone = trim((string)($identity['doctorPhone'] ?? ''));
    $contactName = trim((string)($identity['emergencyName'] ?? ''));
    $contactPhone = trim((string)($identity['emergencyPhone'] ?? ''));
    $emergencyNumber = trim((string)($identity['emergencyNumber'] ?? ''));

    $insulinEntries = readRecords($db, $patientId, 'insulinEntries', 5);
    usort($insulinEntries, static fn(array $a, array $b): int => strcmp((string)($b['dateTime'] ?? ''), (string)($a['dateTime'] ?? '')));
    $readings = readRecords($db, $patientId, 'readings', 1);
    $hba1cRecords = readRecords($db, $patientId, 'hba1c', 1);
    $vitalRecords = readRecords($db, $patientId, 'vitals', 1);
} catch (Throwable $e) {
    error_log('carte_urgence.php: ' . $e->getMessage());
    pageError(503, 'Connexion au dossier impossible. Réessayez dès que le réseau est disponible ou contactez les secours locaux.');
}

$lastReading = $readings[0] ?? null;
$lastHba1c = $hba1cRecords[0] ?? null;
$lastVitals = $vitalRecords[0] ?? null;
$lastSync = displayDate((string)$patient['updated_at']);
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Carte médicale SUKALI — <?= escapeHtml($name) ?></title>
  <style>
    :root{font-family:Arial,Helvetica,sans-serif;color:#18312d;background:#f1f6f5;font-synthesis:none;text-rendering:optimizeLegibility}
    *{box-sizing:border-box}body{margin:0;padding:18px 12px 36px}.page{max-width:760px;margin:auto}
    header{background:#00695c;color:white;border-radius:20px 20px 8px 8px;padding:22px 24px}
    .brand{font-size:26px;font-weight:900;letter-spacing:.04em}.subtitle{margin-top:5px;color:#d5f2ec;font-size:14px}
    .stamp{display:inline-block;margin-top:16px;background:#b71c1c;color:#fff;border-radius:7px;padding:8px 12px;font-size:12px;font-weight:800;letter-spacing:.04em}
    main{background:white;border-radius:0 0 20px 20px;padding:18px;box-shadow:0 8px 28px #1232}
    .updated{font-size:12px;color:#647572;margin:0 0 14px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .field,.panel{border:1px solid #dce8e5;border-radius:13px;padding:13px;background:#fbfdfc;overflow-wrap:anywhere}
    .label{font-size:11px;color:#637571;font-weight:700;text-transform:uppercase;letter-spacing:.04em}.value{font-size:16px;font-weight:800;margin-top:5px}
    h2{font-size:17px;margin:20px 0 10px;color:#00695c}.danger{background:#fff0ef;border:1px solid #ffc8c4;border-left:5px solid #c62828;border-radius:13px;padding:14px;color:#79231f}
    .danger h2{color:#b71c1c;margin:0 0 8px}.danger p{margin:7px 0;line-height:1.45}.muted{color:#637571;font-size:12px;line-height:1.45}
    ul{margin:0;padding-left:20px}li{margin:8px 0;line-height:1.4}.phone{display:inline-block;color:#00695c;font-weight:800;text-decoration:none;margin-top:5px}
    .notice{border-radius:10px;background:#fff8e7;padding:12px;color:#65511f;font-size:12px;line-height:1.45;margin-top:14px}
    footer{text-align:center;color:#687a76;font-size:11px;padding:14px 8px}
    @media(max-width:520px){body{padding:10px 8px 24px}header{padding:18px}main{padding:13px}.grid{grid-template-columns:1fr 1fr}.value{font-size:14px}}
  </style>
</head>
<body>
<div class="page">
  <header>
    <div class="brand">SUKALI</div>
    <div class="subtitle">Carte médicale d’urgence — informations synchronisées</div>
    <div class="stamp">DIABÈTE <?= escapeHtml(strtoupper($diabetes)) ?></div>
  </header>
  <main>
    <p class="updated">Dernière synchronisation du profil : <?= escapeHtml($lastSync) ?></p>
    <div class="grid">
      <div class="field"><div class="label">Nom</div><div class="value"><?= escapeHtml($name) ?></div></div>
      <div class="field"><div class="label">Âge</div><div class="value"><?= $age === null ? 'Non renseigné' : escapeHtml((string)$age . ' ans') ?></div></div>
      <div class="field"><div class="label">Sexe</div><div class="value"><?= escapeHtml($sex) ?></div></div>
      <div class="field"><div class="label">Diabète</div><div class="value"><?= escapeHtml($diabetes) ?></div></div>
    </div>

    <?php if ($allergies !== ''): ?>
      <section class="danger"><h2>⚠ Allergies et précautions</h2><p><?= nl2br(escapeHtml($allergies)) ?></p></section>
    <?php else: ?>
      <section class="panel"><div class="label">Allergies</div><div class="value">Aucune renseignée — cela ne confirme pas l’absence d’allergie.</div></section>
    <?php endif; ?>

    <?php if ($bloodType !== ''): ?>
      <p class="muted"><strong>Groupe sanguin déclaré :</strong> <?= escapeHtml($bloodType) ?> — à confirmer par un professionnel; ne pas l’utiliser seul pour une décision de transfusion.</p>
    <?php endif; ?>

    <?php if ($lastReading && is_numeric($lastReading['valueMgdl'] ?? null)): ?>
      <h2>Dernière glycémie enregistrée</h2>
      <section class="panel"><div class="value"><?= escapeHtml(number_format((float)$lastReading['valueMgdl'] / 100, 2, ',', ' ')) ?> g/L <span class="muted">(<?= escapeHtml((string)round((float)$lastReading['valueMgdl'])) ?> mg/dL)</span></div>
      <div class="muted"><?= escapeHtml(displayDate($lastReading['dateTime'] ?? null)) ?> · mesure déclarée dans l’application; vérifier si possible.</div></section>
    <?php endif; ?>

    <?php if ($mainTreatment !== ''): ?>
      <h2>Traitement déclaré dans le profil</h2>
      <section class="panel"><div class="value"><?= nl2br(escapeHtml($mainTreatment)) ?></div><p class="muted">À confirmer auprès du patient ou de son équipe soignante.</p></section>
    <?php endif; ?>

    <?php if ($insulinEntries): ?>
      <h2>Dernières injections consignées</h2>
      <section class="panel"><ul>
        <?php foreach (array_slice($insulinEntries, 0, 3) as $entry): ?>
          <?php
            $kind = ($entry['kind'] ?? '') === 'basal' ? 'Basale' : 'Rapide / repas';
            $product = trim((string)($entry['productName'] ?? ''));
            $units = is_numeric($entry['units'] ?? null) ? number_format((float)$entry['units'], 1, ',', ' ') . ' UI' : 'dose non renseignée';
          ?>
          <li><strong><?= escapeHtml($kind) ?></strong><?= $product === '' ? '' : ' — ' . escapeHtml($product) ?> · <?= escapeHtml($units) ?><br><span class="muted"><?= escapeHtml(displayDate($entry['dateTime'] ?? null)) ?></span></li>
        <?php endforeach; ?>
      </ul><p class="muted">Journal d’injections déclaré par le patient; ce n’est pas une ordonnance. Ne pas administrer une dose sur la seule base de cette page.</p></section>
    <?php endif; ?>

    <?php if ($lastHba1c && is_numeric($lastHba1c['valuePercent'] ?? null)): ?>
      <h2>Dernière HbA1c enregistrée</h2>
      <section class="panel"><div class="value"><?= escapeHtml(number_format((float)$lastHba1c['valuePercent'], 1, ',', ' ')) ?> %</div><div class="muted"><?= escapeHtml(displayDate($lastHba1c['date'] ?? null)) ?></div></section>
    <?php endif; ?>

    <?php if ($lastVitals): ?>
      <?php $v = $lastVitals; $hasVitals = is_numeric($v['heartRateBpm'] ?? null) || is_numeric($v['spo2Percent'] ?? null) || is_numeric($v['temperatureC'] ?? null); ?>
      <?php if ($hasVitals): ?>
        <h2>Dernières constantes enregistrées</h2>
        <section class="panel"><div class="value">
          <?= is_numeric($v['heartRateBpm'] ?? null) ? 'Pouls ' . escapeHtml((string)round((float)$v['heartRateBpm'])) . ' bpm · ' : '' ?>
          <?= is_numeric($v['spo2Percent'] ?? null) ? 'SpO₂ ' . escapeHtml(number_format((float)$v['spo2Percent'], 0)) . ' % · ' : '' ?>
          <?= is_numeric($v['temperatureC'] ?? null) ? 'Temp. ' . escapeHtml(number_format((float)$v['temperatureC'], 1, ',', ' ')) . ' °C' : '' ?>
        </div><div class="muted"><?= escapeHtml(displayDate($v['dateTime'] ?? null)) ?> · valeurs à recontrôler.</div></section>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($doctorName !== '' || $doctorPhone !== '' || $contactName !== '' || $contactPhone !== '' || $emergencyNumber !== ''): ?>
      <h2>Personnes à contacter</h2>
      <section class="panel">
        <?php if ($contactName !== '' || $contactPhone !== ''): ?><p><strong>Proche :</strong> <?= escapeHtml($contactName !== '' ? $contactName : 'Contact d’urgence') ?><?php if ($contactPhone !== ''): ?><br><a class="phone" href="tel:<?= escapeHtml(phoneLink($contactPhone)) ?>"><?= escapeHtml($contactPhone) ?></a><?php endif; ?></p><?php endif; ?>
        <?php if ($doctorName !== '' || $doctorPhone !== ''): ?><p><strong>Médecin :</strong> <?= escapeHtml($doctorName) ?><?php if ($doctorPhone !== ''): ?><br><a class="phone" href="tel:<?= escapeHtml(phoneLink($doctorPhone)) ?>"><?= escapeHtml($doctorPhone) ?></a><?php endif; ?></p><?php endif; ?>
        <?php if ($emergencyNumber !== ''): ?><p><strong>Numéro d’urgence renseigné :</strong><br><a class="phone" href="tel:<?= escapeHtml(phoneLink($emergencyNumber)) ?>"><?= escapeHtml($emergencyNumber) ?></a></p><?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="danger" style="margin-top:18px">
      <h2>Premiers gestes en cas de malaise</h2>
      <p><strong>Appelez les secours locaux si la personne est inconsciente, convulse, respire difficilement ou ne récupère pas.</strong></p>
      <p>Si elle est consciente et peut avaler : vérifiez sa glycémie si possible et suivez son plan de soins; en cas d’hypoglycémie, utilisez du sucre rapide selon ce plan.</p>
      <p>Si elle est inconsciente ou ne peut pas avaler : ne rien donner par la bouche. Si elle respire, mettez-la en position latérale de sécurité et restez avec elle.</p>
      <p>Le glucagon ne doit être utilisé que s’il est disponible pour cette personne et par quelqu’un qui sait l’administrer. Ne donnez pas d’insuline sur la seule base de cette carte.</p>
    </section>
    <div class="notice">Ces informations sont déclarées par le patient et reflètent la dernière synchronisation disponible. Elles peuvent être incomplètes ou ne pas refléter son état actuel. Vérifiez la situation, mesurez la glycémie si possible et contactez un professionnel ou les secours.</div>
  </main>
  <footer>Carte médicale SUKALI · Accès par QR/NFC · Pour urgence immédiate, contactez les secours locaux.</footer>
</div>
</body>
</html>
