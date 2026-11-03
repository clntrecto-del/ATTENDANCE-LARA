# LARA Attendance System — XAMPP / MySQL Installation

## 1. Place the files

Copy the project folder to `C:\xampp\htdocs\lara`. Use a name **without spaces**
(a space becomes `%20` in the URL and is easy to mistype).

## 2. Start XAMPP

Open the XAMPP Control Panel and start **Apache** and **MySQL**.

## 3. Create the database

1. Open `http://localhost/phpmyadmin`.
2. Click **Import**, choose `fixed.sql` from the project folder, click **Import**.

This creates the `lara_attendance` database with the `members`, `attendance`, and
`system_settings` tables. Existing installations are upgraded automatically when the API
connects; attendance records gain activity-title and signature-snapshot fields without
deleting existing data.
The database itself must be created this way; `config.php` can create missing tables
but cannot create the database.

## 4. Database settings (only if different from XAMPP defaults)

Defaults in `config.php`: host `127.0.0.1`, port `3306`, database `lara_attendance`,
user `root`, blank password. Override with the environment variables `DB_HOST`,
`DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.

## 5. Set the administrator password

Set the environment variable `LARA_ADMIN_PASSWORD`, **or** edit `config.php` and replace the
fallback password string. The built-in default (`LARA@2026`) is accepted only when the
browser is on the same machine as the server; every other visitor gets an error telling
them the password has not been changed.

Admin logins are rate-limited (5 wrong attempts → 15 minute lockout per IP address) and the
admin session expires after 30 idle minutes.

## 6. Open the site

`http://localhost/lara/`

## 7. Members

1. Click **CLICK TO ATTEND**.
2. Enter surname, first name, complete middle name (or tick **No Middle Name**), and position.
3. Sign on the pad and click **Save Signature**.
4. First-time members receive a **Member ID** — write it down. Returning members enter it so
   their saved signature loads and their name is matched.
5. Confirm the submission. Date and time come from the server (Asia/Manila).
6. The same person (case, spacing and punctuation ignored) cannot be recorded twice for the
   same position on the same date.

**Lost Member ID?** A member who forgets their ID cannot register again with the same name.
An administrator must look the member up in the database (`members` table, `member_code`
column) and give the ID back.

## 8. Administrator

1. Click **Administrator Login** and enter the password.
2. Search, filter by position/date, open the daily **Folder**, print, or export to Excel.
3. "Total Present" reflects the current filters.
4. The activity title is saved in the database and shared across devices. Each attendance
   record also keeps the activity title and signature that were submitted with it.

## 9. Production deployment checklist

- Use HTTPS (session cookies are marked Secure automatically on HTTPS).
- Create a dedicated MySQL user limited to the `lara_attendance` database instead of `root`.
- Set a strong `LARA_ADMIN_PASSWORD`. If the site runs behind a reverse proxy on the same
  machine, the server sees every visitor as localhost, so **always** set the password.
- Keep `.htaccess` in place (Apache). On Nginx, deny `*.sql`, `*.md` and `app.html`.
- Back up the full database regularly; it holds the member signatures.
- The login lockout uses the server's temp folder; make sure PHP can write there.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Database server error" | MySQL not running, database not imported, or wrong credentials. Check Apache `error.log`. |
| Login says password not changed | You are not on localhost. Set `LARA_ADMIN_PASSWORD`. |
| "Too many failed attempts" | Wait 15 minutes, or delete `lara_login_*.json` from the server temp folder. |
| Clock shows `--:--:--` | `api.php` unreachable; open `…/api.php?action=clock` to see the error. |
