<?php
date_default_timezone_set('Africa/Kinshasa');

try {
    $db = new PDO('mysql:host=localhost;dbname=sukali_db;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("<h2 style='color:#e74c3c;text-align:center;margin-top:50px;font-family:sans-serif;'>Erreur de connexion à la base de données Sukali</h2>");
}

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die("<h2 style='color:#e74c3c;text-align:center;margin-top:50px;font-family:sans-serif;'>Jeton de consultation manquant.</h2>");
}

// 1. Validation de la session de consultation
$stmt = $db->prepare("
    SELECT l.*, p.* 
    FROM liens_consultation l
    JOIN patient p ON p.patient_id = l.patient_id
    WHERE l.token = :token 
      AND l.statut = 'ACTIF' 
      AND l.date_expiration > NOW()
");
$stmt->execute([':token' => $token]);
$patient = $stmt->fetch();

if (!$patient) {
    echo "
    <div style='max-width:520px;margin:60px auto;text-align:center;font-family:-apple-system,BlinkMacSystemFont,sans-serif;padding:30px;border-radius:18px;box-shadow:0 12px 30px rgba(0,0,0,0.08);background:#fff;'>
        <div style='font-size:54px;margin-bottom:12px;'>🔒</div>
        <h2 style='color:#e74c3c;margin-bottom:10px;font-size:1.4rem;'>Session de Consultation Expirée</h2>
        <p style='color:#546e7a;line-height:1.6;font-size:0.95rem;'>L'accès temporaire à ce rapport a pris fin. Veuillez demander au patient de générer un nouveau QR Code depuis l'application Sukali.</p>
    </div>";
    exit;
}

$patient_id = $patient['patient_id'];

// 2. L'application synchronise ses mesures dans diabtrack_records (JSON).
// On garde aussi la lecture des anciennes mesures stockées dans glycemie.
$glycemiaData = [];
$stmtGly = $db->prepare("SELECT taux_glycemie, moment, commentaire, date_heure
    FROM glycemie WHERE patient_id = :id ORDER BY date_heure DESC LIMIT 100");
$stmtGly->execute([':id' => $patient_id]);
$glycemiaData = $stmtGly->fetchAll();

try {
    $stmtRecords = $db->prepare("SELECT record_json FROM diabtrack_records
        WHERE patient_id = :id AND record_type = 'readings' ORDER BY updated_at DESC LIMIT 100");
    $stmtRecords->execute([':id' => $patient_id]);
    $known = [];
    foreach ($glycemiaData as $row) {
        $key = date('Y-m-d H:i:s', strtotime((string)$row['date_heure'])) . '|' . round((float)$row['taux_glycemie'], 2);
        $known[$key] = true;
    }
    $contexts = [
        'fasting' => 'À jeun', 'beforeMeal' => 'Avant repas', 'afterMeal' => 'Après repas',
        'bedtime' => 'Au coucher', 'beforeSport' => 'Avant effort', 'afterSport' => 'Après effort',
        'other' => 'Autre',
    ];
    foreach ($stmtRecords->fetchAll() as $record) {
        $reading = json_decode((string)$record['record_json'], true);
        if (!is_array($reading) || !is_numeric($reading['valueMgdl'] ?? null) ||
            !is_string($reading['dateTime'] ?? null)) continue;
        $timestamp = strtotime($reading['dateTime']);
        if ($timestamp === false) continue;
        $dateTime = date('Y-m-d H:i:s', $timestamp);
        $value = (float)$reading['valueMgdl'];
        $key = $dateTime . '|' . round($value, 2);
        if (isset($known[$key])) continue;
        $known[$key] = true;
        $context = (string)($reading['context'] ?? 'other');
        $glycemiaData[] = [
            'taux_glycemie' => $value,
            'moment' => $contexts[$context] ?? 'Autre',
            'commentaire' => (string)($reading['note'] ?? ''),
            'date_heure' => $dateTime,
        ];
    }
} catch (Throwable $e) {
    // Le rapport reste compatible avec les anciennes installations sans cette table.
    error_log('rapport_consultation.php readings: ' . $e->getMessage());
}

usort($glycemiaData, static fn(array $a, array $b): int =>
    strcmp((string)$b['date_heure'], (string)$a['date_heure']));
$glycemiaData = array_slice($glycemiaData, 0, 30);

$total = count($glycemiaData);
$somme = 0; $dans_cible = 0; $hypo = 0; $hyper = 0;
foreach ($glycemiaData as $g) {
    $val = floatval($g['taux_glycemie']);
    $somme += $val;
    if ($val >= 70 && $val <= 180) $dans_cible++;
    elseif ($val < 70) $hypo++;
    else $hyper++;
}
$moyenne_mg = $total > 0 ? round($somme / $total) : 0;
$moyenne_gl = round($moyenne_mg / 100, 2);
$tir = $total > 0 ? round(($dans_cible / $total) * 100) : 0;
$hba1c_estimee = $total > 0 ? round(($moyenne_mg + 46.7) / 28.7, 1) : 0;

// Synthèse IA (optionnelle)
$rapportIA = null;
try {
    $stmtIA = $db->prepare("SELECT resultat_analyse FROM ia_rapports WHERE patient_id = :id ORDER BY date_creation DESC LIMIT 1");
    $stmtIA->execute([':id' => $patient_id]);
    $rapportIA = $stmtIA->fetch();
} catch (Exception $e) {}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Rapport Médical Sukali - <?php echo htmlspecialchars($patient['nom_complet']); ?></title>
    <style>
        /* --- RESET & BOX-SIZING GLOBAL --- */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
            font-size: 16px;
        }

        body {
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* --- BARRE SUPERIEURE FIXE (FULL WIDTH) --- */
        .top-navbar {
            position: sticky;
            top: 0;
            width: 100%;
            background: #00695c;
            color: #ffffff;
            padding: clamp(10px, 1.5vh, 16px) clamp(12px, 3vw, 32px);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
            z-index: 1000;
        }

        .top-navbar-brand {
            font-weight: 700;
            font-size: clamp(0.9rem, 2vw, 1.15rem);
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .btn-consultation {
            background: #27ae60;
            color: #ffffff;
            border: none;
            padding: clamp(8px, 1.2vh, 12px) clamp(12px, 2vw, 20px);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            font-size: clamp(0.8rem, 1.8vw, 0.92rem);
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 3px 10px rgba(39, 174, 96, 0.3);
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .btn-consultation:hover {
            background: #219150;
            transform: translateY(-1px);
        }

        .btn-consultation:active {
            transform: translateY(0);
        }

        /* --- CONTENEUR PRINCIPAL ADAPTATIF --- */
        .page-wrapper {
            width: 100%;
            max-width: 100vw;
            padding: clamp(12px, 2.5vw, 30px) clamp(8px, 2vw, 24px);
            display: flex;
            justify-content: center;
        }

        .document-card {
            width: 100%;
            max-width: 1100px; /* S'adapte parfaitement sur PC, TV et Tablettes */
            background: #ffffff;
            padding: clamp(16px, 4vw, 42px);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border-top: 6px solid #00695c;
            overflow: hidden; /* Garantit qu'aucun élément ne déborde du cadre blanc */
        }

        /* --- EN-TÊTE DU DOCUMENT --- */
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: clamp(12px, 2vw, 20px);
            margin-bottom: clamp(16px, 2.5vw, 28px);
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand-title {
            font-size: clamp(1.6rem, 4vw, 2.4rem);
            font-weight: 800;
            color: #00695c;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }

        .brand-subtitle {
            color: #64748b;
            font-size: clamp(0.82rem, 1.8vw, 0.95rem);
            margin-top: 4px;
        }

        .doc-meta {
            text-align: right;
            font-size: clamp(0.78rem, 1.6vw, 0.88rem);
            color: #64748b;
            line-height: 1.5;
        }

        /* --- CARTE INFO PATIENT --- */
        .patient-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 5px solid #00695c;
            padding: clamp(12px, 2vw, 20px);
            border-radius: 12px;
            margin-bottom: clamp(16px, 2.5vw, 28px);
        }

        .patient-card-title {
            font-size: 0.72rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.8px;
            margin-bottom: 8px;
        }

        .patient-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: clamp(8px, 1.5vw, 16px);
            font-size: clamp(0.88rem, 1.8vw, 1rem);
        }

        /* --- GRILLE MÉTROLOGIQUE & MÉTABOLIQUE --- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: clamp(10px, 2vw, 18px);
            margin-bottom: clamp(18px, 3vw, 30px);
        }

        .stat-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 5px solid #00695c;
            padding: clamp(14px, 2vw, 20px);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stat-label {
            font-size: 0.72rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: clamp(1.4rem, 3.5vw, 2rem);
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }

        .stat-sub {
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            color: #64748b;
            margin-top: 4px;
        }

        /* --- BLOC SYNTHÈSE IA --- */
        .ia-section {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: clamp(14px, 2vw, 20px);
            margin-bottom: clamp(18px, 3vw, 30px);
        }

        .ia-title {
            color: #166534;
            font-size: clamp(0.88rem, 2vw, 1.05rem);
            font-weight: 700;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ia-body {
            color: #14532d;
            font-size: clamp(0.82rem, 1.8vw, 0.92rem);
            line-height: 1.55;
        }

        /* --- TABLEAU RESPONSIVE DE GLYCÉMIE (SCROLL INTERNE STRICT) --- */
        .section-header-title {
            color: #00695c;
            font-size: clamp(1.05rem, 2.2vw, 1.3rem);
            font-weight: 700;
            margin-bottom: 12px;
        }

        .table-container {
            width: 100%;
            max-width: 100%;
            overflow-x: auto; /* Défilement horizontal interne sécurisé sur mobile */
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            margin-bottom: clamp(18px, 3vw, 30px);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 580px; /* Garantit que les données restent lisibles sans écraser le texte */
        }

        th, td {
            padding: clamp(10px, 1.5vw, 14px) clamp(10px, 1.8vw, 16px);
            text-align: left;
            border-bottom: 1px solid #edf2f7;
            font-size: clamp(0.8rem, 1.7vw, 0.9rem);
        }

        th {
            background: #f1f5f9;
            color: #475569;
            font-weight: 700;
            white-space: nowrap;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:nth-child(even) {
            background-color: #f8fafc;
        }

        /* --- BADGES DE STATUT --- */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-cible { background: #dcfce7; color: #15803d; }
        .badge-hypo  { background: #fee2e2; color: #b91c1c; }
        .badge-hyper { background: #ffedd5; color: #c2410c; }

        /* --- PIED DE PAGE --- */
        .doc-footer {
            text-align: center;
            margin-top: clamp(20px, 3vw, 35px);
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            font-size: clamp(0.72rem, 1.5vw, 0.82rem);
            color: #94a3b8;
        }

        /* --- ADAPTATION ÉCRANS MOBILES (PETITS SMARTHONES < 520px) --- */
        @media (max-width: 520px) {
            .top-navbar {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .top-navbar-brand {
                justify-content: center;
            }

            .btn-consultation {
                width: 100%;
            }

            .doc-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .doc-meta {
                text-align: left;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        /* --- ADAPTATION GRANDES TÉLÉVISONS & ÉCRANS 4K (> 1600px) --- */
        @media (min-width: 1600px) {
            .document-card {
                max-width: 1300px;
            }
            body {
                font-size: 18px;
            }
        }

        /* --- ADAPTATION IMPRESSION & EXPORT PDF --- */
        @media print {
            .top-navbar { display: none !important; }
            body { background: #ffffff !important; }
            .page-wrapper { padding: 0 !important; }
            .document-card {
                box-shadow: none !important;
                border-top: none !important;
                max-width: 100% !important;
                padding: 0 !important;
            }
            .table-container { border: none !important; overflow: visible !important; }
            table { min-width: 100% !important; }
        }
    </style>
</head>
<body>

    <!-- Barre supérieure de navigation fixe -->
    <header class="top-navbar">
        <div class="top-navbar-brand">
            📄 Rapport Médical Sukali : <strong><?php echo htmlspecialchars($patient['nom_complet']); ?></strong>
        </div>
        <a href="consultation.php?token=<?php echo urlencode($token); ?>" class="btn-consultation">
            💬 Ouvrir la Consultation Interactive ➔
        </a>
    </header>

    <!-- Conteneur principal adaptatif -->
    <div class="page-wrapper">
        <main class="document-card">
            
            <!-- En-tête du document médical -->
            <section class="doc-header">
                <div>
                    <h1 class="brand-title">SUKALI</h1>
                    <p class="brand-subtitle">Rapport Synthétique de Suivi Métabolique & Glycémique</p>
                </div>
                <div class="doc-meta">
                    <strong>Généré le :</strong> <?php echo date('d/m/Y H:i'); ?><br>
                    <strong>Durée d'accès temporaire :</strong> <?php echo (int)$patient['duree_minutes']; ?> minutes
                </div>
            </section>

            <!-- Fiche d'identification du Patient -->
            <section class="patient-card">
                <div class="patient-card-title">Profil du Patient</div>
                <div class="patient-grid">
                    <span><strong>Nom Complet :</strong> <?php echo htmlspecialchars($patient['nom_complet']); ?></span>
                    <span><strong>Sexe :</strong> <?php echo htmlspecialchars($patient['sexe']); ?></span>
                    <span><strong>Type de Diabète :</strong> <?php echo htmlspecialchars(str_replace('_', ' ', $patient['type_diabete'])); ?></span>
                </div>
            </section>

            <!-- Métriques Métaboliques -->
            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Moyenne Glycémique</div>
                    <div class="stat-value"><?php echo $moyenne_gl; ?> <small style="font-size:0.5em; font-weight:normal;">g/L</small></div>
                    <div class="stat-sub"><?php echo $moyenne_mg; ?> mg/dL</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Temps dans la Cible (TIR)</div>
                    <div class="stat-value" style="color: <?php echo $tir >= 70 ? '#15803d' : '#c2410c'; ?>;"><?php echo $tir; ?>%</div>
                    <div class="stat-sub">Objectif Clinique ≥ 70%</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">HbA1c Estimée</div>
                    <div class="stat-value"><?php echo $hba1c_estimee; ?>%</div>
                    <div class="stat-sub">Calcul sur les 30 derniers relevés</div>
                </div>
            </section>

            <!-- Synthèse de l'Assistant IA -->
            <?php if ($rapportIA): ?>
                <section class="ia-section">
                    <div class="ia-title">🤖 Analyse & Synthèse IA Sukali :</div>
                    <p class="ia-body">
                        <?php echo nl2br(htmlspecialchars($rapportIA['resultat_analyse'])); ?>
                    </p>
                </section>
            <?php endif; ?>

            <!-- Tableau des relevés avec scroll tactile interne -->
            <section>
                <h2 class="section-header-title">📊 Relevé des Glycémies Récentes</h2>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Heure</th>
                                <th>Valeur (g/L)</th>
                                <th>Valeur (mg/dL)</th>
                                <th>Moment</th>
                                <th>Commentaire / Note</th>
                                <th>Statut Clinique</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($glycemiaData as $g): 
                                $val_mg = floatval($g['taux_glycemie']);
                                $val_gl = round($val_mg / 100, 2);
                                $statutClass = $val_mg < 70 ? 'badge-hypo' : ($val_mg > 180 ? 'badge-hyper' : 'badge-cible');
                                $statutTxt = $val_mg < 70 ? 'Hypoglycémie' : ($val_mg > 180 ? 'Hyperglycémie' : 'Dans la cible');
                            ?>
                                <tr>
                                    <td style="white-space: nowrap;"><?php echo date('d/m/Y H:i', strtotime($g['date_heure'])); ?></td>
                                    <td><strong><?php echo $val_gl; ?> g/L</strong></td>
                                    <td><?php echo $val_mg; ?> mg/dL</td>
                                    <td><strong><?php echo htmlspecialchars($g['moment'] ?? 'À jeun'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($g['commentaire'] ?? '-'); ?></td>
                                    <td><span class="status-badge <?php echo $statutClass; ?>"><?php echo $statutTxt; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <footer class="doc-footer">
                Document médical informatif généré par l'Écosystème Médical Sukali — Ce rapport ne remplace pas une consultation médicale directe.
            </footer>

        </main>
    </div>

</body>
</html>
