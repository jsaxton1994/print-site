CREATE DATABASE IF NOT EXISTS print_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE print_portal;
CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, role ENUM('User','Admin') NOT NULL DEFAULT 'User',
 status ENUM('Active','Suspended') NOT NULL DEFAULT 'Active', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL,
 file_size INT UNSIGNED NOT NULL, status ENUM('Pending','Processing','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
 copies TINYINT UNSIGNED NOT NULL DEFAULT 1, paper_size VARCHAR(40) NOT NULL DEFAULT 'A4', colour_mode VARCHAR(40) NOT NULL DEFAULT 'Colour',
 notes VARCHAR(500) NULL, admin_note VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 completed_at DATETIME NULL, FOREIGN KEY(user_id) REFERENCES users(id), INDEX(status,created_at), INDEX(user_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS email_templates (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, template_key VARCHAR(80) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
 subject VARCHAR(255) NOT NULL, body TEXT NOT NULL, is_default TINYINT(1) NOT NULL DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS email_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, recipient VARCHAR(190) NOT NULL, template_key VARCHAR(80) NOT NULL,
 subject VARCHAR(255) NOT NULL, sent_at DATETIME NOT NULL, success TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT INTO email_templates(template_key,name,subject,body,is_default) VALUES
('account_registration','Account Registration','Your Print Portal account is ready','Hello {{email}},\n\nYour account has been created. Sign in at {{login_url}}.\n\nYour temporary password: {{password}}\nPlease change it after signing in.',1),
('account_suspended','Account Suspended','Your account has been suspended','Hello {{email}},\n\nYour Print Portal account has been suspended. Contact an administrator for assistance.',1),
('account_unsuspended','Account Unsuspended','Your account is active again','Hello {{email}},\n\nYour Print Portal account has been reactivated. You can sign in now.',1),
('job_received','Job Received','We received your print job #{{job_id}}','Hello {{email}},\n\nWe received {{filename}} as print job #{{job_id}}. It is now pending review.',1),
('job_processing','Job Processing','Your print job #{{job_id}} is processing','Hello {{email}},\n\nYour document {{filename}} was approved and sent to the printer.',1),
('job_cancellation','Job Cancellation','Your print job #{{job_id}} was cancelled','Hello {{email}},\n\nYour print job for {{filename}} was cancelled. {{note}}',1),
('job_completed','Job Completed','Your print job #{{job_id}} is complete','Hello {{email}},\n\nYour document {{filename}} is complete and ready for collection.',1)
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO settings(setting_key,setting_value) VALUES
('printer_email',''),('paper_size','A4'),('colour_options','Colour'),('mail_from',''),('mail_from_name','Print Portal'),('mail_host',''),('mail_port','25'),('mail_username',''),('mail_password',''),('mail_encryption','none')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
