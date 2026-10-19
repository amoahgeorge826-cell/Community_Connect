# CommunityConnect guide

## Start the portal

Install PHP dependencies once from `C:\laragon\www\secure-login\api`:

```powershell
composer install
```

### Laragon

1. Start **Apache** in Laragon.
2. Make sure this project is located at `C:\laragon\www\secure-login`.
3. Open [http://localhost/secure-login/](http://localhost/secure-login/) to reach CommunityConnect or sign in. Use [http://localhost/secure-login/register.php](http://localhost/secure-login/register.php) to create an account.

### PHP development server

Open PowerShell in the project directory and run:

```powershell
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe -S 127.0.0.1:8000 -t C:\laragon\www\secure-login C:\laragon\www\secure-login\router.php
```

Keep that PowerShell window open, then visit [http://127.0.0.1:8000/](http://127.0.0.1:8000/). The development server is intended for local testing, not public hosting.

## Create an account

1. Enter a username using 3–32 letters, numbers, dots, underscores, or hyphens.
2. Enter a valid email address.
3. Choose a unique password with at least 12 characters. The strength indicator is guidance; it does not send your password anywhere.
4. Submit the form. Sign in with the username and password you chose.

Administrator access is never selectable on the public registration form. On an empty, new database, the first account is assigned the administrator role and later accounts are standard users. Existing database roles are preserved.

## Sign in

1. Enter the account username and password.
2. If two-factor authentication is enabled, the local development demo generates and fills a six-digit code on the verification page. With demo mode disabled, the portal emails the code and a one-time sign-in link to the email address registered to your account.
3. After the code is verified, a success message appears for five seconds before the portal signs you in. The portal then reads your current role from the database and opens CommunityConnect with the appropriate member or administrator capabilities. The OTP verifies access to your account; it does not grant or change roles.

Codes expire after 10 minutes, can only be used once, and allow up to five attempts. A new code can be sent once per minute. Check your spam folder if an email does not arrive.

## Workspace and roles

Every signed-in member can report community issues, see the shared issue feed, follow responses, and add useful updates. Report categories cover roads and sidewalks, streetlights, waste and sanitation, water and drainage, parks, public safety, and other local concerns. Reports include a category, urgency, and a public status timeline. Add a title, description, or location when useful; photo, video, or voice-recording reports can be submitted with no typed details. Attach up to four JPEG, PNG, GIF, WebP, MP4, WebM, QuickTime, MP3, M4A, AAC, OGG, or WAV files. Images are limited to 10 MB each, audio to 15 MB each, video to 25 MB each, and all attachments together to 40 MB. The browser can record voice reports when microphone access is available.

Choose **Use my GPS** to request the device's current location. Coordinates are saved only after the browser grants location permission and the member chooses that button; a map preview and map link are then shown. GPS requires HTTPS on public deployments (localhost is allowed for development). Reports without media need a street address, landmark, or GPS location. Reports with attachments can be submitted without a location.

The newest community-uploaded video plays muted in the community-page background when available. The video is served only to signed-in members, and the playback control can pause it. Report photos, videos, and voice recordings are likewise available only to signed-in members.

- **Administrator:** can triage community reports, post official responses while changing their status, manage user accounts, and create database backups.
- **Standard user:** can submit reports and comment on the shared community feed. Administrator controls are restricted on the server.

Reports and their updates are visible to signed-in community members. Deleting an account keeps its community reports visible, but removes its sign-in access. At least one administrator account must remain; administrators cannot delete themselves or remove their own administrator role.

Role checks are performed on the server. Hiding a link in the interface is not the only access control. Report text and GPS coordinates are stored in the app's SQLite database. Media is stored outside the web root in `C:\laragon\communityconnect-media` by default; set `APP_COMMUNITY_MEDIA_DIR` to use another private writable directory. Configure PHP with `upload_max_filesize=25M`, `post_max_size=42M`, and `max_file_uploads` of at least 4 so the application attachment limits can be reached. Email/SMS alerts, service-provider integrations, and anonymous reporting are not provided.

## Administrator user management

1. Open **Manage users** from the navigation while signed in as an administrator.
2. Create accounts with a unique username and email, a strong initial password, and either community-member or administrator access.
3. Edit a member's username, email, role, or password from their account card. Leave the password blank to keep the current password.
4. Delete accounts that should no longer have access. Their community reports remain visible under the original reporting name. An administrator cannot delete their own account or leave the system without an administrator.

Deliver initial or replacement passwords to account holders through a private channel. The portal does not currently force a password change at the next sign-in.

The administrator dashboard shows account details and recent community reports and updates. Password hashes, salts, one-time verification tokens, and the application pepper are not displayed; the pepper is held in server configuration rather than the database.

## Set up two-factor authentication

1. Sign in and open **Security** in the top navigation or choose the security card on your workspace.
2. In the local development demo, select **Generate a demo verification code**. The generated code is filled and submitted automatically.
3. With demo mode disabled, select **Email me a code and verification link**, then enter the code from your account email to enable email-based two-factor authentication.
4. At future sign-ins, the local demo generates and submits a code automatically. With demo mode disabled, enter the code from your email or open the one-time link in the same browser. Codes expire after 10 minutes, can only be used once, and are limited to five attempts.

Keep your account email address current and protect access to that inbox. This sample portal does not currently offer recovery codes or self-service 2FA reset.

## Administrator database backup

1. Sign in using an administrator account.
2. Open **Create database backup** from the administrator workspace.
3. A consistent SQLite snapshot is saved in `C:\laragon\secure-login-backups`, outside the public web directory.
4. Make a protected off-device copy according to your organization's backup and retention policy.

If backup creation fails, check that the PHP process can create and write to that directory, then inspect the PHP error log. Backup files are not downloadable through the portal.

Administrators can change the backup and community media directories from **Dashboard → Backup and storage settings**. The dashboard lists recent database backup files and up to 50 saved configuration restore points. A restore point contains only the two directory settings; restoring it does not restore or change database records and does not move existing media files. Keep the media directory pointed at the folder containing the existing attachments. Dashboard-saved directory settings are stored outside the web directory in `C:\laragon\secure-login-config` and take precedence over `APP_BACKUP_DIR` and `APP_COMMUNITY_MEDIA_DIR` environment values.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Browser cannot connect | Start Apache in Laragon, or restart the PHP development server in PowerShell. |
| Database unavailable | Confirm `database.sqlite` exists and is writable by the PHP process; check that PDO SQLite is enabled. |
| Sign-in rejected | Use the account username, check Caps Lock, and verify the password. |
| 2FA code rejected | Check that the emailed code has not expired or already been used, then sign in again to request another code. |
| 2FA email missing | Check spam, confirm `APP_MAIL_FROM`, and verify PHP's configured mail transport can deliver through its mail relay. |
| Backup cannot be created | Confirm the server can create the private backup folder and inspect PHP logs. |
| Page shows an error | Check the PHP error log. The browser intentionally does not display database or server exception details. |

## Deployment notes

This is a local demonstration application, not a production identity provider. Before exposing it to a network, set `APP_ENV=production` and configure a strong, unique `APP_PEPPER` of at least 32 characters in the PHP process environment. Preserve the same pepper for the lifetime of the account database: changing it prevents existing passwords from verifying. You may set `APP_BACKUP_DIR` to an absolute, private directory path. Also serve only over HTTPS, add rate limiting and account recovery procedures, set operational backup retention, and arrange regular security and dependency reviews. Do not expose the SQLite file or backup directory through the web server.

Email two-factor authentication sends a random six-digit code and a one-time verification link through PHPMailer over SMTP. Install dependencies from this directory with `composer install`. In development, email delivery is replaced by a local demo: the generated code is filled into the verification field and auto-submitted. The code is visible to the browser session, so this is not real two-factor security and must never be used with real accounts or deployed. To test actual email OTP locally, set `APP_2FA_DEMO_MODE=0`; the default SMTP server is Laragon Mailpit at `127.0.0.1:1025`, its inbox is available at `http://localhost:8025`, and the sender defaults to `noreply@communityconnect.local`. Development verification links use the current local host and script path. In production, `APP_2FA_DEMO_MODE` cannot be enabled; configure `APP_BASE_URL` to the public HTTPS application URL, plus `APP_MAIL_FROM`, `APP_SMTP_HOST`, `APP_SMTP_PORT`, and `APP_SMTP_ENCRYPTION` (`tls` for STARTTLS or `ssl` for implicit TLS). If your relay requires login, also configure `APP_SMTP_USERNAME` and `APP_SMTP_PASSWORD`; keep credentials in the server environment, not source control. Codes and link tokens are stored as keyed hashes in SQLite and expire after 10 minutes.

At sign-in, users can enter the six-digit code or open the email link in the same browser where they started signing in. The link verifies the pending sign-in and redirects to the workspace without requiring JavaScript or manual code entry. Email links are single-use; sign in again and request a fresh email if a link has expired or already been used. The code field uses the browser's `one-time-code` hint and submits automatically if a browser or password manager autofills the code; the application cannot read the user's email inbox itself. After six digits are filled, the Verify button shows a two-second countdown before automatic submission.
