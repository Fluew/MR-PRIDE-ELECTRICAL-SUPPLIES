# Deploying MR Pride to Railway

Railway builds this repository with its own builder (Railpack). No Docker is needed. The repo
already contains the Railway settings:

| File | Service | What it does |
|---|---|---|
| `railway.json` | **web** (required) | Serves the app. Runs `php artisan migrate --force` before each deploy and checks `/up` before switching traffic. |
| `railway.worker.json` | **worker** (recommended) | Runs the queue (`php artisan queue:work`): imports, evaluation recalculation and queued emails. |
| `railway.scheduler.json` | **scheduler** (recommended) | Runs `php artisan schedule:work`: daily database backup (02:00), expired invitations, audit-log pruning. |
| `railpack.json` | all | Installs Tesseract and Poppler (OCR for material lists and inventory imports) and `mysqldump` (backups). |

The deployed branch is **`pre-testing`**. Set it in each service: **Settings → Source → Branch**.

## 1. Create the project

1. Push the `pre-testing` branch to GitHub.
2. In Railway: **New Project → Deploy from GitHub repo**, pick the repository, and set the branch to `pre-testing`. This becomes the **web** service.
3. In the project, **+ New → Database → MySQL**. Railway names it `MySQL`.

## 2. Set the variables

Use **Shared Variables** (project settings) so the web, worker and scheduler services get the
same values, or paste the block into each service's **Variables → Raw Editor**. Then:

- Replace `APP_KEY` with your own key (`php artisan key:generate --show`). Keep the same key
  forever: changing it logs everyone out and breaks every trusted-device credential.
- Set `APP_URL` to the public address (step 3).
- Set the Resend values (see **Email**).

```env
APP_NAME="MR Pride"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:REPLACE_ME
APP_URL=https://mr-pride.com

DB_CONNECTION=mysql
DB_URL=${{MySQL.MYSQL_URL}}

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

LOG_CHANNEL=stderr
LOG_LEVEL=info

INERTIA_SSR_ENABLED=false

RESEND_API_KEY=re_REPLACE_ME
RESEND_FROM_NAME="MR Pride"
RESEND_FROM_EMAIL="no-reply@mr-pride.com"

MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@mr-pride.com"
MAIL_FROM_NAME="MR Pride"
```

`${{MySQL.MYSQL_URL}}` is a Railway reference: Railway fills in the database address and
password itself. Don't paste the real values.

## 3. Public address

Web service → **Settings → Networking**: generate a Railway domain or add the custom domain
(`mr-pride.com`; add `www.mr-pride.com` too if you want it to work). Put the address in `APP_URL`,
starting with `https://`.

## 4. Keep uploaded files: attach a volume

Railway's disk resets on every deploy. Uploaded product files, quotation attachments, delivery
proofs, imports and backups live in `storage/app`, so attach a volume to the **web** service:
**right-click the service → Attach Volume**, mount path **`/app/storage/app`**.

The worker also reads uploaded files (imports). A Railway volume attaches to one service only, so
if imports fail in the worker, set `QUEUE_CONNECTION=sync` on the web service and skip the worker.

## 5. Add the worker and scheduler

For each one: **+ New → GitHub Repo**, pick the same repository and branch `pre-testing`, then:

- **Settings → Config-as-code → Railway config file**: `/railway.worker.json` (or `/railway.scheduler.json`).
- **Variables**: the shared variables.
- No public domain is needed.

## 6. First administrator and their device

Registration is closed on this version, and every login must come from a trusted device.

**The administrator account.** The migration `enhance_security_rbac_and_sessions` makes user
**#1** the administrator. When upgrading a database from the older `main` deploy, the account you
registered there becomes the administrator. On an empty database, create the first account
before that migration runs.

**Trust the administrator's device.** In the web service's shell (**⋯ → Shell**, or `railway ssh`):

```sh
php artisan device:enroll-code --user=admin@your-email.com --minutes=15
```

It prints a code and a one-time link. Open the link **on the laptop you want to trust**, then
enter the code, the admin email and password. That browser is now trusted; log in normally.
More staff devices are added from inside the app (Users → device invitation, sent by email).

Don't use `device:trust-admin`: it saves its code unhashed, so the link it prints is rejected.

**Change the recovery key right away.** The migration gives the admin the fixed recovery key
`RECOVER-MRPRIDE-ADMIN-INITIAL-KEY-2026`, which is in the source code. With it and the admin
password, anyone can trust a new device at `/device/recover`. After the first login, regenerate
it on the **Users** page (emergency recovery key) and store the new one safely.

Don't run `php artisan db:seed` in production. It creates a demo admin
(`admin@example.com` / `admin123`) and sample quotations.

## Email

Device invitations, verification codes and customer emails are sent through **Resend**
(`RESEND_*`). Without a valid `RESEND_API_KEY`, invitations fail and staff can't trust new devices.
Resend's test sender `onboarding@resend.dev` only delivers to your own Resend account's email;
to email anyone else, verify the `mr-pride.com` domain in Resend and use an address on it.

Password-reset emails use Laravel's mailer (`MAIL_*`). `MAIL_MAILER=log` only writes them to the
logs. Railway blocks outgoing SMTP on some plans; use an HTTP API mailer if SMTP times out.

## Good to know

- **Logs** appear in the service's **Deployments → Logs** (`LOG_CHANNEL=stderr`).
- **HTTPS**: Railway handles TLS. The app trusts Railway's proxy headers (`bootstrap/app.php`), so
  links, redirects and cookies use https.
- **OCR check**: `php artisan tinker --execute="dump(app(App\Services\MaterialList\OcrEngine::class)->tesseractAvailable())"`.
- **Run an artisan command** in a service's shell (**⋯ → Shell** in the dashboard), or with the
  Railway CLI: `railway ssh` then `php artisan <command>`.
