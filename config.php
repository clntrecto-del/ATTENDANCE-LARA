<?php
// LARA Attendance configuration.
//
// ADMIN PASSWORD: set the LARA_ADMIN_PASSWORD environment variable, or replace the
// fallback string below. The built-in default only works when you open the site from
// the same computer (localhost); the server refuses it for any other visitor.
const LARA_DEFAULT_ADMIN_PASSWORD = 'LARA@2026';
define('ADMIN_PASSWORD', getenv('LARA_ADMIN_PASSWORD') ?: LARA_DEFAULT_ADMIN_PASSWORD);

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function backfillAttendanceSignatures(PDO $pdo): void {
    $backfill = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $backfill->execute(['signature_snapshot_backfill_v1']);
    if ($backfill->fetchColumn() === 'complete') return;

    $pdo->exec("UPDATE attendance a
        INNER JOIN members m ON m.id = a.member_id
        SET a.signature_data = m.signature_data
        WHERE a.signature_data IS NULL");
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, 'complete')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute(['signature_snapshot_backfill_v1']);
}

// Runs a single cheap check per request. The full create/upgrade only runs when the
// tables or columns are missing (e.g. the database was created without fixed.sql).
function ensureSchema(PDO $pdo): void {
    $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('members','attendance')")->fetchColumn();
    $columns = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
        AND COLUMN_NAME IN ('member_id','status','time_out','activity_title','signature_data')")->fetchColumn();
    $settingsTable = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_settings'")->fetchColumn();
    if ($tables === 2 && $columns === 5 && $settingsTable === 1) {
        backfillAttendanceSignatures($pdo);
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS members (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Upgrade path for databases created by older versions.
    if (!columnExists($pdo, 'attendance', 'status')) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Present' AFTER attendance_time");
    }
    if (!columnExists($pdo, 'attendance', 'time_out')) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN time_out TIME NULL DEFAULT NULL AFTER status");
    }
    if (!columnExists($pdo, 'attendance', 'member_id')) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN member_id BIGINT UNSIGNED NULL AFTER id");
        $pdo->exec("ALTER TABLE attendance ADD KEY idx_attendance_member_id (member_id)");
        $pdo->exec("ALTER TABLE attendance ADD CONSTRAINT fk_attendance_member
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL");
    }
    if (!columnExists($pdo, 'attendance', 'activity_title')) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN activity_title VARCHAR(120) NOT NULL DEFAULT 'General Attendance' AFTER time_out");
    }
    if (!columnExists($pdo, 'attendance', 'signature_data')) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN signature_data LONGTEXT NULL AFTER activity_title");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(64) NOT NULL,
        setting_value VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('activity_title', '')");
    backfillAttendanceSignatures($pdo);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'lara_attendance';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        $pdo->exec("SET time_zone = '+08:00'");
        ensureSchema($pdo);
    }
    return $pdo;
}
