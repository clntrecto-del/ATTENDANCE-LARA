# LARA Online Attendance Monitoring System

A responsive PHP + MySQL attendance system for the Lazareto Advocate Rainbow Association.

## Files

| File | Purpose |
|---|---|
| `index.php` | Entry page; serves `app.html` with security headers |
| `app.html` | The website interface |
| `api.php` | Attendance, clock, administrator login/logout, and records API |
| `config.php` | Database and administrator settings |
| `fixed.sql` | Creates the `lara_attendance` database and tables |
| `.htaccess` | Blocks direct download of `.sql`/`.md` files and `app.html` (Apache) |
| `assets/` | Logo, signature pad, and attendance-folder scripts |
| `INSTALLATION.md` | XAMPP setup and deployment |

## Quick start (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\lara` (use a folder name without spaces).
2. Start **Apache** and **MySQL** in XAMPP.
3. In phpMyAdmin, **Import** `fixed.sql`.
4. Open `http://localhost/lara/`.
5. Administrator password: set `LARA_ADMIN_PASSWORD` or edit `config.php`.
   The built-in default (`LARA@2026`) works **only on the same computer** (localhost).
   Anyone else is refused until you change it.

See `INSTALLATION.md` for details.
