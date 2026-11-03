CREATE DATABASE IF NOT EXISTS lara_attendance
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE lara_attendance;

CREATE TABLE IF NOT EXISTS members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_code VARCHAR(32) NOT NULL,
  surname VARCHAR(60) NOT NULL,
  first_name VARCHAR(60) NOT NULL,
  middle_name VARCHAR(80) NOT NULL DEFAULT '',
  identity_key CHAR(64) NOT NULL,
  signature_data LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_member_code (member_code),
  UNIQUE KEY uq_members_identity_key (identity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id BIGINT UNSIGNED NULL,
  surname VARCHAR(60) NOT NULL,
  first_name VARCHAR(60) NOT NULL,
  middle_name VARCHAR(80) NOT NULL,
  position_name VARCHAR(30) NOT NULL,
  attendance_date DATE NOT NULL,
  attendance_time TIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Present',
  time_out TIME NULL DEFAULT NULL,
  activity_title VARCHAR(120) NOT NULL DEFAULT 'General Attendance',
  signature_data LONGTEXT NULL,
  submitted_at DATETIME NOT NULL,
  duplicate_key VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_member_attendance_day (duplicate_key),
  KEY idx_attendance_member_id (member_id),
  KEY idx_surname (surname),
  KEY idx_position (position_name),
  KEY idx_attendance_date (attendance_date),
  CONSTRAINT fk_attendance_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(64) NOT NULL,
  setting_value VARCHAR(255) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('activity_title', '');
