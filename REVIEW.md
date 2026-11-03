# LARA Attendance — Code Review

Reviewed: `api.php`, `config.php`, `app.html`, `assets/*.js`, `fixed.sql`, docs.
**Verified:** PHP syntax, live schema migration, records API, and activity-title persistence across reloads.
The full test checklist at the bottom should still be run before using this for a real meeting.

## Security

| # | Problem | Severity | Status |
|---|---|---|---|
| 1 | Admin password hardcoded as `LARA@2026` in the code, and the docs said `LARA2026` (wrong) | High | Fixed: default only works from localhost; docs corrected |
| 2 | No limit on admin login guesses | High | Fixed: 5 failures → 15 min lockout per IP |
| 3 | Admin session never expired (the stored start time was never checked) | Medium | Fixed: 30 min idle timeout |
| 4 | API accepted any POST body type, so a cross-site form could post to it (CSRF) | Medium | Fixed: POST requires `application/json` |
| 5 | Session cookie not marked `Secure` on HTTPS | Medium | Fixed |
| 6 | Setup files (`fixed.sql`, `*.md`) and raw page downloadable | Low | Fixed with `.htaccess` |
| 7 | No security headers (clickjacking, sniffing, CSP) | Low | Fixed in `index.php` / `api.php` |
| 8 | Signature check only verified a PNG header; any PNG up to 1 MB of any size was stored | Low | Fixed: dimensions validated |
| 9 | PHP warnings could print into API JSON | Low | Fixed: `display_errors` off |
| 10 | MySQL runs as `root` with blank password | High for production | Docs only: create a limited user |
| 11 | Anyone can attend as anyone: identity is just a typed name, and a first-time registration needs no verification | Design limit | Not fixable in code alone; see recommendations |

XSS: output is escaped everywhere I checked (`esc()` / `folderEscape()`), and signatures are only
rendered if they start with `data:image/png;base64,`. No SQL injection: all queries use prepared statements.

## Bugs

| # | Problem | Status |
|---|---|---|
| 1 | **Folder → Print printed the dashboard, not the folder sheet.** The print CSS needs `body.printing-folder`, which no script ever set. | Fixed |
| 2 | Clock called the server **every second** from every open browser, and every call started a PHP session (session lock) | Fixed: sync every 30 s, tick locally |
| 3 | `db()` ran ~10 schema-check queries plus a full-table `GROUP BY` write **on every API request** | Fixed: one cheap check; folder list now computed live |
| 4 | `attendance_folders.record_count` was only ever incremented, so it drifted from reality | Fixed: table no longer used |
| 5 | Past records used the member's current signature | Fixed: attendance stores a signature snapshot and legacy rows are backfilled |
| 6 | "Total Present" ignored the search/position/date filters | Fixed |
| 7 | Duplicate key used raw text, so "Dela Cruz" vs "dela  cruz" vs "Dela-Cruz" could be recorded twice | Fixed: uses normalized identity |
| 8 | Typos/mixed language: "No Attendace for this day!.", Filipino empty-state in one place | Fixed |
| 9 | Position option `" Committee "` had stray spaces | Fixed |
| 10 | `README`/`INSTALLATION` named a nonexistent database script; URL used a folder name that doesn't match the zip | Fixed |
| 11 | `Index.html` vs `index.php` differ only by case, which breaks on Linux hosting | Fixed: renamed `app.html` |

## Things I did not change (need your decision)

1. **No time-out feature.** The `time_out` column exists and the API returns it, but nothing ever sets or shows it.
2. **Lost Member ID** locks a member out (blank ID + existing name = "profile already exists"). Needs an admin screen to look up or reset IDs.
3. **Position is part of the duplicate rule** (as documented), so one person can be recorded twice in a day under two positions. Remove position from the key if that isn't wanted.
4. **Excel export is HTML saved as `.xls`.** Excel shows a "format doesn't match" warning, and signatures aren't included.
5. **A blank-looking signature can pass the server** (only the browser enforces drawn strokes).
6. Records are never paginated; the dashboard will get slow after thousands of rows.
7. Existing databases created by the old version keep an unused `attendance_folders` table (harmless; can be dropped).

## Test checklist (run once on XAMPP)

1. Import `fixed.sql`, open the site; the clock should tick and match Manila time.
2. Register a new member, save signature, submit; note the Member ID.
3. Submit again the same day: expect "already recorded".
4. Retry with the name typed as `DELA-CRUZ` / extra spaces: still a duplicate.
5. Log in as admin; check signatures show, search/filters work, Total Present changes with filters.
6. Open Folder → a date → Print: the preview should show only the logo header and that day's table.
7. From a different device on the network, try admin login before changing the password: should be refused.
8. Enter a wrong password 5 times: lockout message.
9. Leave admin idle >30 min: next action returns to login.
