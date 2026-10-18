<?php
// C:\wamp64\www\sukali\API\consultation.php
date_default_timezone_set('Africa/Kinshasa');

try {
    $config = require_once __DIR__ . '/config.php';
    $dbConfig = $config['db'];

    $host = $dbConfig['host'];
    $database = $dbConfig['database'];
    $dbUser = $dbConfig['user'];
    $dbPassword = $dbConfig['password'];

    $db = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Auto-création de la table de messagerie si elle n'existe pas
    $db->exec("CREATE TABLE IF NOT EXISTS messages_consultation (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        token VARCHAR(255) NOT NULL,
        nom_expediteur VARCHAR(100) DEFAULT 'Médecin',
        role_expediteur ENUM('MEDECIN', 'PATIENT') DEFAULT 'MEDECIN',
        message TEXT NOT NULL,
        type_message VARCHAR(50) DEFAULT 'AVIS',
        date_envoi DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

} catch (PDOException $e) {
    die("<h2 style='color:red;text-align:center;margin-top:50px;font-family:sans-serif;'>Erreur de connexion à sukali_db</h2>");
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';

if (empty($token)) {
    die("<h2 style='color:red;text-align:center;margin-top:50px;font-family:sans-serif;'>Jeton de consultation manquant.</h2>");
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
    <div style='max-width:500px;margin:50px auto;text-align:center;font-family:-apple-system,sans-serif;padding:25px;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,0.08);background:#fff;'>
        <div style='font-size:48px;margin-bottom:10px;'>🔒</div>
        <h2 style='color:#e74c3c;margin-bottom:10px;'>Session Expirée</h2>
        <p style='color:#555;line-height:1.5;font-size:0.95em;'>Le canal interactif de consultation n'est plus actif. Demandez au patient de générer un nouveau QR Code Sukali.</p>
    </div>";
    exit;
}

$patient_id = $patient['patient_id'];
$notification = null;
if (isset($_GET['sent']) && $_GET['sent'] == '1') {
    $notification = "Message et recommandations transmis avec succès au patient !";
}

// 2. Traitement de l'envoi d'un message par le médecin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_message'])) {
    $nom_expediteur = trim($_POST['nom_expediteur'] ?? 'Dr. Référent');
    $message = trim($_POST['message'] ?? '');
    $type_message = $_POST['type_message'] ?? 'AVIS';

    if (!empty($message)) {
        $stmtIns = $db->prepare("
            INSERT INTO messages_consultation (patient_id, token, nom_expediteur, role_expediteur, message, type_message, date_envoi)
            VALUES (:patient_id, :token, :nom, 'MEDECIN', :msg, :type_msg, NOW())
        ");
        $stmtIns->execute([
            ':patient_id' => $patient_id,
            ':token'      => $token,
            ':nom'        => $nom_expediteur,
            ':msg'        => $message,
            ':type_msg'   => $type_message
        ]);
        header("Location: consultation.php?token=" . urlencode($token) . "&sent=1");
79 |     exit;

    }
}

// 3. Chargement de l'historique des échanges
$stmtMsg = $db->prepare("
    SELECT * FROM messages_consultation 
    WHERE patient_id = :patient_id 
    ORDER BY date_envoi ASC
");
$stmtMsg->execute([':patient_id' => $patient_id]);
$messages = $stmtMsg->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Portail Consultation Sukali - <?php echo htmlspecialchars($patient['nom_complet']); ?></title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #eceff1;
            margin: 0;
            padding: 0;
            color: #2c3e50;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* --- En-tête Fixe (Sticky) --- */
        .top-bar {
            position: sticky;
            top: 0;
            background: #00695c;
            color: white;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
        }

        .top-title {
            font-weight: 600;
            font-size: clamp(0.88rem, 2.5vw, 1.05rem);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 8px 14px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: clamp(0.8rem, 2vw, 0.88rem);
            white-space: nowrap;
            transition: background 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
        }

        .btn-back:hover { background: rgba(255, 255, 255, 0.25); }

        /* --- Conteneur Principal --- */
        .main-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 16px 12px 30px 12px;
        }

        /* --- Fiche Métabolique Express --- */
        .patient-card {
            background: white;
            padding: clamp(14px, 3vw, 20px);
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.05);
            border-left: 5px solid #00695c;
            margin-bottom: 20px;
        }

        .patient-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .patient-name {
            font-size: clamp(1.1rem, 3vw, 1.4rem);
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }

        .badge-expiry {
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .patient-details {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 10px;
            font-size: 0.88rem;
            color: #64748b;
        }

        /* --- Zone de Chat / Fil de Discussion --- */
        .chat-section {
            background: white;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.05);
            padding: clamp(14px, 3vw, 22px);
            margin-bottom: 20px;
        }

        .chat-title {
            font-size: 1rem;
            font-weight: 700;
            color: #00695c;
            margin-top: 0;
            margin-bottom: 16px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 10px;
        }

        .chat-feed {
            display: flex;
            flex-direction: column;
            gap: 14px;
            max-height: 420px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .chat-bubble {
            max-width: 85%;
            padding: 12px 16px;
            border-radius: 14px;
            font-size: clamp(0.85rem, 2.2vw, 0.95rem);
            position: relative;
            line-height: 1.45;
        }

        .chat-bubble.medecin {
            align-self: flex-end;
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            color: #1b5e20;
            border-bottom-right-radius: 2px;
        }

        .chat-bubble.patient {
            align-self: flex-start;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #334155;
            border-bottom-left-radius: 2px;
        }

        .bubble-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            font-size: 0.72rem;
            margin-bottom: 6px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .bubble-type {
            padding: 2px 6px;
            border-radius: 4px;
            background: rgba(0,0,0,0.06);
        }

        .chat-empty {
            text-align: center;
            color: #94a3b8;
            padding: 30px 10px;
            font-size: 0.9rem;
        }

        /* --- Formulaire d'Envoi --- */
        .form-card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.05);
            padding: clamp(14px, 3vw, 22px);
        }

        .form-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            margin-top: 0;
            margin-bottom: 14px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.92rem;
            font-family: inherit;
            background-color: #f8fafc;
            transition: border-color 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #00695c;
            background-color: #fff;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .btn-submit {
            background: #00695c;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.95rem;
            width: 100%;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.1s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            box-shadow: 0 3px 10px rgba(0,105,92,0.25);
        }

        .btn-submit:hover { background: #004d40; }
        .btn-submit:active { transform: scale(0.99); }

        .alert-success {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 0.88rem;
            font-weight: 600;
        }

        /* --- Responsive Smartphones --- */
        @media (max-width: 640px) {
            .top-bar {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .top-title {
                justify-content: center;
            }

            .btn-back {
                width: 100%;
            }

            .chat-bubble {
                max-width: 92%;
            }
        }
    </style>
</head>
<body>

    <!-- En-tête Sticky -->
    <div class="top-bar">
        <div class="top-title">
            💬 Consultation Interactive Sukali
        </div>
        <a href="rapport_consultation.php?token=<?php echo urlencode($token); ?>" class="btn-back">
            📄 Voir le Rapport Médical (Read-Only)
        </a>
    </div>

    <div class="main-container">

        <!-- Carte Patient -->
        <div class="patient-card">
            <div class="patient-header">
                <h2 class="patient-name"><?php echo htmlspecialchars($patient['nom_complet']); ?></h2>
                <span class="badge-expiry">Session active : <?php echo $patient['duree_minutes']; ?> min</span>
            </div>
            <div class="patient-details">
                <span><strong>Sexe :</strong> <?php echo htmlspecialchars($patient['sexe']); ?></span>
                <span>•</span>
                <span><strong>Type de diabète :</strong> <?php echo htmlspecialchars(str_replace('_', ' ', $patient['type_diabete'])); ?></span>
            </div>
        </div>

        <?php if ($notification): ?>
            <div class="alert-success">
                ✅ <?php echo htmlspecialchars($notification); ?>
            </div>
        <?php endif; ?>

        <!-- Fil des échanges de messages -->
        <div class="chat-section">
            <h3 class="chat-title">📋 Historique des Recommandations & Échanges</h3>
            <div class="chat-feed">
                <?php if (empty($messages)): ?>
                    <div class="chat-empty">
                        Aucun message échangé pour le moment. Utilisez le formulaire ci-dessous pour transmettre vos avis, conseils ou ordonnances.
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): 
                        $isMedecin = $msg['role_expediteur'] === 'MEDECIN';
                        $bubbleClass = $isMedecin ? 'medecin' : 'patient';
                        $iconType = $msg['type_message'] === 'ORDONNANCE' ? '💊 Ordonnance' : ($msg['type_message'] === 'CONSEIL' ? '💡 Conseil' : '🩺 Avis Médical');
                    ?>
                        <div class="chat-bubble <?php echo $bubbleClass; ?>">
                            <div class="bubble-meta">
                                <span><?php echo htmlspecialchars($msg['nom_expediteur']); ?></span>
                                <span class="bubble-type"><?php echo $iconType; ?></span>
                            </div>
                            <div>
                                <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                            </div>
                            <div style="text-align: right; font-size: 0.68rem; opacity: 0.7; margin-top: 4px;">
                                <?php echo date('d/m/Y H:i', strtotime($msg['date_envoi'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulaire d'envoi d'avis médical -->
        <div class="form-card">
            <h3 class="form-title">✍️ Transmettre un Avis ou une Recommandation</h3>
            <form method="POST" action="consultation.php?token=<?php echo urlencode($token); ?>">
                <input type="hidden" name="action_message" value="1">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="form-group">
                    <label class="form-label">Nom du Praticien / Service :</label>
                    <input type="text" name="nom_expediteur" class="form-control" value="Dr. Référent Sukali" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Type d'Intervention :</label>
                    <select name="type_message" class="form-select">
                        <option value="AVIS">🩺 Avis & Diagnostic Médical</option>
                        <option value="CONSEIL">💡 Conseil Hygiéno-Diététique</option>
                        <option value="ORDONNANCE">💊 Ajustement de Traitement / Posologie</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Message & Prescriptions :</label>
                    <textarea name="message" class="form-control" placeholder="Rédigez vos observations, conseils ou recommandations pour le patient..." required></textarea>
                </div>

                <button type="submit" class="btn-submit">
                    📤 Envoyer au Patient
                </button>
            </form>
        </div>

    </div>

</body>
</html>