<?php
require_once __DIR__ . '/config.php';

ini_set('display_errors', '0');          // never leak PHP warnings into the JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Asia/Manila');

const ADMIN_IDLE_SECONDS = 1800;         // admin session expires after 30 idle minutes
const LOGIN_MAX_FAILURES = 5;            // failed logins allowed ...
const LOGIN_LOCK_SECONDS = 900;          // ... before a 15 minute lockout (per IP)

// Sessions are only started for administrator actions, so the public clock/attendance
// requests are not serialized behind the session lock.
function startSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Only accept real JSON requests. A cross-site <form> cannot send application/json
// without a CORS preflight, which closes the simplest CSRF route.
function readJson(): array {
    $type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (strpos($type, 'application/json') !== 0) {
        jsonResponse(['success' => false, 'message' => 'Unsupported request type.'], 415);
    }
    $data = json_decode((string)file_get_contents('php://input', false, null, 0, 1500000), true);
    return is_array($data) ? $data : [];
}

function clientIsLocal(): bool {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function throttleFile(): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lara_login_' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json';
}

function loginLockRemaining(): int {
    $data = @json_decode((string)@file_get_contents(throttleFile()), true);
    if (!is_array($data) || ($data['count'] ?? 0) < LOGIN_MAX_FAILURES) return 0;
    return max(0, (int)($data['last'] ?? 0) + LOGIN_LOCK_SECONDS - time());
}

function recordLoginFailure(): void {
    $file = throttleFile();
    $data = @json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || time() - (int)($data['last'] ?? 0) > LOGIN_LOCK_SECONDS) $data = ['count' => 0];
    $data['count'] = (int)$data['count'] + 1;
    $data['last'] = time();
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function clearLoginFailures(): void {
    @unlink(throttleFile());
}

function cleanText(mixed $value, int $max = 255): string {
    if (!is_string($value) && !is_numeric($value)) return '';
    $value = trim(preg_replace('/\s+/u', ' ', (string)$value));
    return mb_substr($value, 0, $max, 'UTF-8');
}

function normalizeIdentityPart(string $value): string {
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value));
}

function memberIdentityKey(string $surname, string $firstName, string $middleName): string {
    $parts = array_map('normalizeIdentityPart', [$surname, $firstName, $middleName]);
    return hash('sha256', implode("\0", $parts));
}

function hasCompleteMiddleName(string $middleName): bool {
    $words = preg_split('/[^\p{L}]+/u', $middleName, -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) return false;
    foreach ($words as $word) {
        if (mb_strlen($word, 'UTF-8') > 1) return true;
    }
    return false;
}

function isValidSignatureData(string $signature): bool {
    if (strlen($signature) > 1000000 || !preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/]+={0,2})$/D', $signature, $matches)) {
        return false;
    }
    $image = base64_decode($matches[1], true);
    if ($image === false || substr($image, 0, 8) !== "\x89PNG\r\n\x1a\n") return false;
    $info = @getimagesizefromstring($image);
    return $info !== false && $info[2] === IMAGETYPE_PNG
        && $info[0] >= 20 && $info[1] >= 20 && $info[0] <= 4096 && $info[1] <= 2048;
}

function isAdmin(): bool {
    startSession();
    if (empty($_SESSION['lara_admin']) || $_SESSION['lara_admin'] !== true) return false;
    if (time() - (int)($_SESSION['lara_admin_last'] ?? 0) > ADMIN_IDLE_SECONDS) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['lara_admin_last'] = time();
    return true;
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'clock') {
        jsonResponse([
            'success' => true,
            'epoch' => time(),
            'date' => date('Y-m-d'),
            'time' => date('h:i:s A')
        ]);
    }

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = readJson();
        $password = (string)($body['password'] ?? '');

        $wait = loginLockRemaining();
        if ($wait > 0) {
            jsonResponse(['success' => false, 'message' => 'Too many failed attempts. Try again in ' . (int)ceil($wait / 60) . ' minute(s).'], 429);
        }
        if (hash_equals(LARA_DEFAULT_ADMIN_PASSWORD, ADMIN_PASSWORD) && !clientIsLocal()) {
            jsonResponse(['success' => false, 'message' => 'The administrator password has not been changed. Set LARA_ADMIN_PASSWORD or edit config.php on the server.'], 503);
        }
        if ($password === '' || !hash_equals(ADMIN_PASSWORD, $password)) {
            recordLoginFailure();
            jsonResponse(['success' => false, 'message' => 'Incorrect administrator password.'], 401);
        }
        clearLoginFailures();
        startSession();
        session_regenerate_id(true);
        $_SESSION['lara_admin'] = true;
        $_SESSION['lara_admin_last'] = time();
        jsonResponse(['success' => true, 'message' => 'Login successful.']);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        jsonResponse(['success' => true]);
    }

    if ($action === 'session') {
        jsonResponse(['success' => true, 'authenticated' => isAdmin()]);
    }

    if ($action === 'activity-title' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = db()->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'activity_title' LIMIT 1");
        $stmt->execute();
        jsonResponse(['success' => true, 'activityTitle' => (string)($stmt->fetchColumn() ?: '')]);
    }

    if ($action === 'activity-title' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isAdmin()) jsonResponse(['success' => false, 'message' => 'Administrator login is required to save the activity title.'], 401);
        $body = readJson();
        $activityTitle = cleanText($body['activityTitle'] ?? '', 120);
        if ($activityTitle !== '' && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $activityTitle)) {
            jsonResponse(['success' => false, 'message' => 'Enter an activity title, not an email address.'], 422);
        }
        $stmt = db()->prepare("INSERT INTO system_settings (setting_key, setting_value)
            VALUES ('activity_title', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$activityTitle]);
        jsonResponse(['success' => true, 'activityTitle' => $activityTitle]);
    }

    if ($action === 'member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = readJson();
        $memberCode = strtoupper(cleanText($body['memberCode'] ?? '', 32));
        $surname = cleanText($body['surname'] ?? '', 60);
        $firstName = cleanText($body['firstName'] ?? '', 60);
        $middleName = cleanText($body['middleName'] ?? '', 80);

        if ($memberCode === '' || $surname === '' || $firstName === '') {
            jsonResponse(['success' => false, 'message' => 'Enter your Member ID, surname, and first name to retrieve your signature.'], 422);
        }

        $stmt = db()->prepare('SELECT member_code AS memberCode, surname, first_name AS firstName,
            middle_name AS middleName, signature_data AS signature
            FROM members WHERE member_code = ? LIMIT 1');
        $stmt->execute([$memberCode]);
        $member = $stmt->fetch();
        if (!$member) {
            jsonResponse(['success' => false, 'message' => 'Member ID was not found. Check the ID or leave it blank to register.'], 404);
        }
        if (memberIdentityKey($surname, $firstName, $middleName) !== memberIdentityKey(
            $member['surname'],
            $member['firstName'],
            $member['middleName']
        )) {
            jsonResponse(['success' => false, 'message' => 'The name does not match this Member ID. Check the details and try again.'], 422);
        }

        jsonResponse(['success' => true, 'member' => $member]);
    }

    if ($action === 'save-member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = readJson();
        $surname = cleanText($body['surname'] ?? '', 60);
        $firstName = cleanText($body['firstName'] ?? '', 60);
        $middleName = cleanText($body['middleName'] ?? '', 80);
        $noMiddleName = ($body['noMiddleName'] ?? false) === true;
        $memberCode = strtoupper(cleanText($body['memberCode'] ?? '', 32));
        $signature = is_string($body['signature'] ?? null) ? $body['signature'] : '';
        if ($noMiddleName) $middleName = '';

        if ($surname === '' || $firstName === '') {
            jsonResponse(['success' => false, 'message' => 'Enter your surname and first name before saving your signature.'], 422);
        }
        if (!$noMiddleName && !hasCompleteMiddleName($middleName)) {
            jsonResponse(['success' => false, 'message' => 'Enter your complete middle name, not initials only, or select No Middle Name.'], 422);
        }
        if (!$noMiddleName && preg_match('/(?:^|\s)\p{L}\.?(?:\s|$)/u', $middleName)) {
            jsonResponse(['success' => false, 'message' => 'Enter your complete middle name, not initials only, or select No Middle Name.'], 422);
        }
        if (!isValidSignatureData($signature)) {
            jsonResponse(['success' => false, 'message' => 'Please draw a complete signature before saving.'], 422);
        }

        $identityKey = memberIdentityKey($surname, $firstName, $middleName);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($memberCode !== '') {
                $memberStmt = $pdo->prepare('SELECT id, surname, first_name AS firstName, middle_name AS middleName
                    FROM members WHERE member_code = ? LIMIT 1 FOR UPDATE');
                $memberStmt->execute([$memberCode]);
                $member = $memberStmt->fetch();
                if (!$member) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'Member ID was not found. Leave it blank only for a first-time registration.'], 422);
                }
                if ($identityKey !== memberIdentityKey($member['surname'], $member['firstName'], $member['middleName'])) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'The name does not match this Member ID. Check the details and try again.'], 422);
                }
                $memberId = (int)$member['id'];
                $memberCode = strtoupper($memberCode);
                $stmt = $pdo->prepare('UPDATE members SET signature_data = ? WHERE id = ?');
                $stmt->execute([$signature, $memberId]);
            } else {
                $existingMember = $pdo->prepare('SELECT member_code FROM members WHERE identity_key = ? LIMIT 1');
                $existingMember->execute([$identityKey]);
                if ($existingMember->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'A member profile already exists for this name. Enter the Member ID to update its signature.'], 409);
                }

                $memberCode = 'LARA-' . strtoupper(bin2hex(random_bytes(12)));
                $stmt = $pdo->prepare('INSERT INTO members
                    (member_code, surname, first_name, middle_name, identity_key, signature_data)
                    VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$memberCode, $surname, $firstName, $middleName, $identityKey, $signature]);
                $memberId = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && $error->errorInfo && $error->errorInfo[1] == 1062) {
                jsonResponse(['success' => false, 'message' => 'A member profile already exists. Enter its Member ID to update the signature.'], 409);
            }
            throw $error;
        }

        jsonResponse(['success' => true, 'memberCode' => $memberCode, 'message' => 'Signature saved to the member profile.']);
    }

    if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = readJson();
        $surname = cleanText($body['surname'] ?? '', 60);
        $firstName = cleanText($body['firstName'] ?? '', 60);
        $middleName = cleanText($body['middleName'] ?? '', 80);
        $noMiddleName = ($body['noMiddleName'] ?? false) === true;
        $memberCode = strtoupper(cleanText($body['memberCode'] ?? '', 32));
        $signature = is_string($body['signature'] ?? null) ? $body['signature'] : '';
        $position = cleanText($body['position'] ?? '', 30);
        $status = cleanText($body['status'] ?? 'Present', 20);
        $activityTitle = cleanText($body['activityTitle'] ?? '', 120);
        if ($activityTitle === '') $activityTitle = 'General Attendance';
        $positions = ['Punong Barangay','Councilor','SK Chairman','SK Councilor','Officers','Committee','Members'];

        if ($noMiddleName) $middleName = '';
        if ($surname === '' || $firstName === '') {
            jsonResponse(['success' => false, 'message' => 'Please complete your surname and first name.'], 422);
        }
        if (!$noMiddleName && !hasCompleteMiddleName($middleName)) {
            jsonResponse(['success' => false, 'message' => 'Enter your complete middle name, not initials only, or select No Middle Name.'], 422);
        }
        if (!$noMiddleName && preg_match('/(?:^|\s)\p{L}\.?(?:\s|$)/u', $middleName)) {
            jsonResponse(['success' => false, 'message' => 'Enter your complete middle name, not initials only, or select No Middle Name.'], 422);
        }
        if (!isValidSignatureData($signature)) {
            jsonResponse(['success' => false, 'message' => 'Please save your signature before submitting attendance.'], 422);
        }
        if (!in_array($position, $positions, true)) {
            jsonResponse(['success' => false, 'message' => 'Please select a valid position.'], 422);
        }
        if ($status !== 'Present') {
            jsonResponse(['success' => false, 'message' => 'Invalid attendance status.'], 422);
        }

        $nowDate = date('Y-m-d');
        $nowTime = date('H:i:s');
        $submittedAt = date('Y-m-d H:i:s');
        $identityKey = memberIdentityKey($surname, $firstName, $middleName);
        // Same person (ignoring case, spacing and punctuation) + position + date = duplicate.
        $duplicateKey = $identityKey . '|' . mb_strtolower($position, 'UTF-8') . '|' . $nowDate;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT id FROM attendance WHERE duplicate_key = ? LIMIT 1');
            $check->execute([$duplicateKey]);
            if ($check->fetch()) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'duplicate' => true, 'message' => 'Attendance has already been recorded for this member today.'], 409);
            }

            if ($memberCode !== '') {
                $memberStmt = $pdo->prepare('SELECT id, surname, first_name AS firstName, middle_name AS middleName
                    FROM members WHERE member_code = ? LIMIT 1 FOR UPDATE');
                $memberStmt->execute([$memberCode]);
                $member = $memberStmt->fetch();
                if (!$member) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'Member ID was not found. Leave it blank only for a first-time registration.'], 422);
                }
                if ($identityKey !== memberIdentityKey($member['surname'], $member['firstName'], $member['middleName'])) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'The name does not match this Member ID. Check the details and try again.'], 422);
                }
                $memberId = (int)$member['id'];
                $memberUpdate = $pdo->prepare('UPDATE members SET signature_data = ? WHERE id = ?');
                $memberUpdate->execute([$signature, $memberId]);
            } else {
                $existingMember = $pdo->prepare('SELECT member_code FROM members WHERE identity_key = ? LIMIT 1');
                $existingMember->execute([$identityKey]);
                if ($existingMember->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => 'A member profile already exists for this name. Enter the Member ID to continue and update its signature.'], 409);
                }

                $newMember = $pdo->prepare('INSERT INTO members
                    (member_code, surname, first_name, middle_name, identity_key, signature_data)
                    VALUES (?, ?, ?, ?, ?, ?)');
                $memberCode = 'LARA-' . strtoupper(bin2hex(random_bytes(12)));
                $newMember->execute([$memberCode, $surname, $firstName, $middleName, $identityKey, $signature]);
                $memberId = (int)$pdo->lastInsertId();
            }

            $stmt = $pdo->prepare('INSERT INTO attendance
                (member_id, surname, first_name, middle_name, position_name, attendance_date, attendance_time, status, activity_title, signature_data, submitted_at, duplicate_key)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$memberId, $surname, $firstName, $middleName, $position, $nowDate, $nowTime, $status, $activityTitle, $signature, $submittedAt, $duplicateKey]);

            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && $error->errorInfo && $error->errorInfo[1] == 1062) {
                jsonResponse(['success' => false, 'duplicate' => true, 'message' => 'A member profile or attendance record already exists. Enter the existing Member ID if needed.'], 409);
            }
            throw $error;
        }

        jsonResponse([
            'success' => true,
            'message' => 'Attendance successfully recorded. Thank you!',
            'record' => ['memberCode' => $memberCode, 'surname' => $surname, 'firstName' => $firstName, 'middleName' => $middleName, 'position' => $position, 'status' => $status, 'activityTitle' => $activityTitle, 'date' => $nowDate, 'time' => date('h:i:s A')]
        ]);
    }

    if ($action === 'records') {
        if (!isAdmin()) jsonResponse(['success' => false, 'message' => 'Your administrator session has expired. Please log in again.'], 401);

        $pdo = db();
        $records = $pdo->query("SELECT a.surname, a.first_name AS firstName, a.middle_name AS middleName,
            a.position_name AS position, DATE_FORMAT(a.attendance_date,'%Y-%m-%d') AS date,
            DATE_FORMAT(a.attendance_time,'%h:%i:%s %p') AS time,
            DATE_FORMAT(a.time_out,'%h:%i:%s %p') AS timeOut, a.status, a.activity_title AS activityTitle,
            COALESCE(a.signature_data, m.signature_data) AS signature, m.member_code AS memberCode
            FROM attendance a LEFT JOIN members m ON m.id = a.member_id
            ORDER BY a.surname ASC, a.first_name ASC, a.id ASC")->fetchAll();

        $folders = $pdo->query("SELECT DATE_FORMAT(attendance_date,'%Y-%m-%d') AS date,
            DATE_FORMAT(attendance_date,'%M %e, %Y Attendance') AS label, COUNT(*) AS total
            FROM attendance GROUP BY attendance_date ORDER BY attendance_date DESC")->fetchAll();

        jsonResponse(['success' => true, 'records' => $records, 'folders' => $folders]);
    }

    jsonResponse(['success' => false, 'message' => 'Invalid API action.'], 400);
} catch (Throwable $error) {
    error_log('LARA API error: ' . $error->getMessage());
    jsonResponse(['success' => false, 'message' => 'Database server error. Please try again later.'], 500);
}
