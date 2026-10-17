-- Migration additive pour le schéma Sukali actuellement installé dans sukali_db.
-- À exécuter une seule fois après sauvegarde ; les données métier ne sont pas supprimées.
ALTER TABLE patient MODIFY COLUMN date_naissance DATE NULL;

ALTER TABLE comptes_patients
  ADD COLUMN pin_lookup CHAR(64) NULL,
  ADD COLUMN pin_hash VARCHAR(255) NULL,
  ADD UNIQUE KEY uq_comptes_pin_lookup (pin_lookup);

CREATE TABLE IF NOT EXISTS api_sessions (
  session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (session_id),
  UNIQUE KEY uq_api_sessions_token (token_hash),
  KEY ix_api_sessions_patient (patient_id),
  CONSTRAINT fk_api_sessions_patient FOREIGN KEY (patient_id)
    REFERENCES patient(patient_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diabtrack_records (
  patient_id BIGINT UNSIGNED NOT NULL,
  record_type ENUM('profile','readings','meals','insulinEntries','hba1c','reminders','vitals','notifications','emergencyEvents','contacts','messages','facilities','shareSessions') NOT NULL,
  record_id VARCHAR(80) NOT NULL,
  record_json JSON NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (patient_id, record_type, record_id),
  CONSTRAINT fk_diabtrack_records_patient FOREIGN KEY (patient_id)
    REFERENCES patient(patient_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
