# FruitScan

A complete PHP + MariaDB web application for exploring fruit quality detection. Includes a responsive public website, camera/upload detector, accounts, private scan history, and an administrator workspace. The frontend uses native JavaScript modules and local SVG illustrations. **No frontend build or runtime npm dependencies are required.**

Project for CSC120.

## Run locally

Requirements: PHP 8.2+ with PDO MySQL, PDO SQLite (migration/isolated tests), fileinfo, mbstring, and cURL (for a connected model). The existing XAMPP installation provides these extensions.

**XAMPP:** start Apache and MySQL, then visit **http://localhost/FruitScanWeb/**. This installation uses the MariaDB database **fruitscan**, visible in **http://localhost/phpmyadmin/**. The private connection settings are in `server/database.json`. Apache must honor the included `.htaccess` files. The PHP Apache module settings and `.user.ini` set the image upload limit to 10 MB.

**Standalone development server, from this project folder:**

```powershell
.\scripts\start.ps1
```

Open **http://localhost:8765/**. If PowerShell script execution is restricted, run PHP directly:

```powershell
C:\xamppni\php\php.exe -d upload_max_filesize=10M -d post_max_size=12M -d display_errors=0 -S localhost:8765 router.php
```

Use the supplied router with PHP's development server. It blocks access to private directories. Camera capture requires localhost or HTTPS and browser permission.

## First account and administrator

Visitors can scan without registering. Create an account at `#/register` to save future scans. Guest results are not retained.

There are **no default passwords or public administrator registration endpoints**. Create your administrator locally:

```powershell
.\scripts\create-admin.ps1 -Email 'you@example.com' -Name 'Your Name'
```

The script prompts for a password without displaying it. Sign in at `#/admin/login`. Re-running it for an existing email promotes that account, replaces its password, and revokes existing sessions.

On other systems, set `FRUITSCAN_ADMIN_PASSWORD` in the process environment, then run:

```sh
php scripts/admin.php you@example.com "Your Name"
```

## Demo mode and a real model

This local installation now has a trained classifier for apple, banana, grape, guava, jujube, orange, pomegranate, and strawberry. See [measured evaluation results](ml/MODEL_CARD.md) and [training/service instructions](ml/README.md). Start the background model service when using XAMPP:

```powershell
.\scripts\start-model-background.ps1
```

The standalone `scripts/start.ps1` also starts the configured model service. Training artifacts and the private service token remain in ignored local files; a fresh clone needs model training or its own configured endpoint. The model cannot reliably reject non-fruit or unsupported-fruit images.

When no model endpoint is configured, FruitScan uses clearly labeled demo mode: the user chooses a sample outcome, fruit type is unclassified, and confidence is unavailable. Existing demo records retain their labels. If a configured model is offline, the detector reports an error instead of producing a demo fallback.

The real adapter is in `server/PredictionService.php`. To connect your trained classifier:

1. Copy `server/model.example.json` to `server/model.json` and replace the example labels/version with those of your actual model. For condition-only classification, use an empty `fruit_classes` array.
2. Set `FRUITSCAN_MODEL_URL` in PHP's server environment to the trusted prediction endpoint. Optionally set `FRUITSCAN_MODEL_TOKEN` for bearer authentication. Restart PHP/Apache after changing the environment.
3. To use a manifest outside the project, set `FRUITSCAN_MODEL_MANIFEST` to its absolute path.
4. Add descriptions/images in **Admin → Fruit classes**. In real mode, names must match the manifest exactly. Metadata changes do not train the model.

The adapter sends a multipart POST containing an `image` file. The endpoint must return HTTP 200 with:

```json
{
  "success": true,
  "prediction": "fresh",
  "confidence": 0.96,
  "fruit_class": "apple",
  "probabilities": { "fresh": 0.96, "rotten": 0.04 }
}
```

These numbers illustrate the API contract, not FruitScan accuracy. `prediction` must be `fresh` or `rotten`; confidence is optional/null or a number from 0 to 1. Probabilities are optional; if provided, both labels must be present and sum to approximately 1. `fruit_class` must be a declared model label or `Unclassified fruit`. Invalid responses and network errors produce an unavailable state, never a fabricated fallback prediction. Confidence below 0.65 displays a caution; this UI threshold is not a measured accuracy guarantee.

Secrets are read from server environment variables, never sent to the browser. `.env` files are not automatically loaded. Publish the external model provider's data policy before accepting public uploads.

## Password resets

Links expire in 30 minutes, are single use, and are stored as hashes in the database. Password changes/resets revoke older sessions. Deactivation also revokes sessions.

Without email delivery, reset requests are written to the private `storage/reset-outbox.log`. An administrator can retrieve and privately share the requested link after verifying the user's identity. Administrators can also issue one-time links through User Management.

For email, configure PHP's mail transport plus `FRUITSCAN_MAIL_FROM` and `FRUITSCAN_PUBLIC_URL` (e.g. `https://fruit.example.com/FruitScanWeb`). The local outbox is a development aid, not production email delivery. Reset links and outbox entries are secrets.

## Data and access

- Live database: MariaDB `fruitscan`, configured in private `server/database.json`; uploaded images: random filenames in `storage/`. The old SQLite file is retained only as a migration backup.
- Guest images are temporary and not saved in history.
- Signed-in scans remain until deleted by their owner or an administrator.
- Administrators can inspect records, manage non-admin users, configure display metadata/access, view analytics, and read feedback.
- Deleting a scan removes its image. Deleting an account deletes its scans and reset tokens.
- HttpOnly, SameSite session cookies and CSRF tokens protect sessions and mutations. HTTPS enables the Secure cookie flag.
- Passwords use PHP password hashing. Uploads are checked by MIME, size, dimensions, and pixel count. Requests are rate limited.
- Feedback is stored in the admin inbox; the contact form does not send email.

Use HTTPS in production. Give PHP write access to `storage/` and its temporary/session directories. Keep private folders inaccessible at the web-server level. For Nginx, deny `storage`, `server`, `scripts`, `tests`, `test-results`, `node_modules`, and dotfiles explicitly because Nginx ignores `.htaccess`. Do not expose PHP's development server publicly. Backups should include the database and images. `FRUITSCAN_STORAGE_DIR` can move runtime data outside the document root.

## Tests

Node.js is only needed for tests. Install tooling with `npm install`.

```powershell
npm test
npm test -- --browser
npm test -- --a11y
```

Tests start temporary PHP servers and isolated databases under ignored `test-results/`. They never seed or delete the normal application's data. PHP needs write access to its configured upload/session temporary paths.

- API: auth, roles, CSRF, file validation, demo scans, private image access, history/filter/sort/pagination, deletion, profiles, reset tokens, session revocation, admin controls, feedback, and real-model contract validation.
- Browser: public/user/admin pages at desktop/tablet/mobile sizes, upload preview, result labels, synthetic camera capture/retake, permission denial, authentication, profiles, history, classes, settings, and runtime errors.
- Screenshots and layout reports are retained in each test run directory.

The browser runner uses installed Chrome or Edge. Override `BROWSER_BINARY` or `PHP_BINARY` for other installations. Physical camera hardware and an actual trained model require deployment-specific validation.

## Structure

```text
index.php                     HTML entry and metadata
api.php                       HTTP endpoints and authorization
router.php                    Local server private-path protection
app/components/               Shared UI and navigation
app/pages/                    Public, detector, account, records, admin
app/services/                 API client and session state
server/bootstrap.php          Database schema and server helpers
server/PredictionService.php  Demo/real prediction boundary
styles/app.css                Design tokens and responsive components
assets/                       Local illustration and brand mark
scripts/                      Launch and administrator setup
tests/                        Isolated API and browser tests
storage/                      Private runtime data (ignored)
```

FruitScan is for education and experimentation. Predictions do not guarantee freshness or food safety.

## MariaDB migration (2026-10-12)

The local installation was migrated from SQLite to MariaDB database `fruitscan` on `127.0.0.1:3306`. All seven tables were copied and every value verified, preserving account IDs, password hashes, session versions, timestamps, scan metadata, and image paths. Uploaded files remain in `storage/`.

The original `storage/fruitscan.sqlite` and timestamped `storage/fruitscan-before-mysql-*.sqlite` backups are retained. The app now reads/writes MariaDB; SQLite no longer receives live changes. Do not switch back to an old SQLite backup after new writes without reconciling those changes.

Local connection: the existing XAMPP `root` account is used because MariaDB reported Aria checksum corruption in `mysql.db` and `mysql.tables_priv`, preventing database grants. Those system tables were not repaired or modified by this migration. An unused `fruitscan_app` account was created before the grant failed; it has no database privileges. Server permission-table repair and a restricted application account remain separate maintenance work before public deployment. Connection credentials are excluded from version control and denied over HTTP.

Reproducible migration: `php scripts/migrate-mysql.php` backs up SQLite, copies into a new database, verifies every value, and activates the config only after success. It refuses to overwrite an existing target. The `--resume-local-root` recovery option verifies existing target contents against SQLite before activation and uses the existing local administrator connection; it never replaces conflicting target records.

Model activation synchronizes fruit categories through PHP's configured database connection. Isolated tests default to SQLite; run the same suite against temporary MariaDB databases with:

```powershell
$env:FRUITSCAN_TEST_MYSQL='1'
npm.cmd test
npm.cmd test -- --trained-model
Remove-Item Env:FRUITSCAN_TEST_MYSQL
```

Temporary test databases have random `fruitscan_test_` names and are removed after each test application closes. Tests never use the live `fruitscan` database.
