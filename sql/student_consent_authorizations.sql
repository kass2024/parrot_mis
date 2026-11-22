CREATE TABLE IF NOT EXISTS `student_consent_authorizations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `contract_token` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `language` enum('en','fr') NOT NULL DEFAULT 'en',
  `student_application_id` int unsigned DEFAULT NULL,
  `issued_by` int unsigned NOT NULL,
  `form_data` json NOT NULL,
  `signature_png` mediumblob DEFAULT NULL,
  `signing_date` date DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contract_token` (`contract_token`),
  KEY `issuer_language` (`issued_by`, `language`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
