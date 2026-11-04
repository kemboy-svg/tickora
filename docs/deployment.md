# Hosting Tickora for testers (staging)

This sets up a **staging** server: a private copy of Tickora for testers, with
sandbox payments and test email. Production uses the same steps with the
differences listed at the end.

## Quick test link: GitHub Codespaces

For a short round of testing without a server: on GitHub, open the
repository, then **Code → Codespaces → Create codespace on main**. The first
start takes a few minutes. It sets up the site with the December demo
events and sandbox payments, and prints the public test link (also used in
the app) and the admin password. Run `bash .devcontainer/start.sh` in its
terminal to show them again.

- The link only works while the Codespace runs. It stops after 30 idle
  minutes by default (raise it in GitHub Settings → Codespaces); start it
  again from github.com/codespaces and the same link returns.
- Without email settings, confirmation emails go to the log. Confirm a
  tester by hand with `php artisan tickora:verify-email someone@example.com`,
  or add Codespaces secrets `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
  `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS` before creating it.
- It uses SQLite; for real events, use a proper staging server (below).

## What the server needs

|            |                                                                                                                                                   |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHP        | 8.3 or newer, with `pdo_pgsql`, `intl`, `gd`, `exif`, `mbstring`, `bcmath`, `fileinfo`                                                            |
| Database   | PostgreSQL 15+ (recommended) or MySQL 8 / MariaDB 10.6+. Not SQLite: the protections against two buyers getting the same ticket rely on row locks |
| Web server | Nginx (or any server that can run PHP-FPM) with HTTPS                                                                                             |
| Node       | 22, only to build the front end                                                                                                                   |
| Email      | A transactional email service with your domain verified (SPF and DKIM), e.g. Postmark, Resend, Mailgun, Brevo or Amazon SES                       |

A managed Laravel host (Laravel Cloud, or a VPS set up with Forge or Ploi)
handles PHP, Nginx, HTTPS, cron and deploys for you; the steps below are what
it should end up doing.

## 1. First deploy

```bash
git clone https://github.com/kemboy-svg/tickora.git
cd tickora
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate      # once, ever: see "APP_KEY" below
```

Fill in `.env` (next section), then:

```bash
php artisan migrate --force
php artisan storage:link
php artisan db:seed --force   # optional demo data, see "Demo data"
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Point the web server's document root at `public/`.

## 2. Environment for staging

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging.your-domain.com
APP_KEY=base64:…            # generated above; never change it afterwards

DB_CONNECTION=pgsql          # or mysql (then DB_PORT=3306)
DB_HOST=…
DB_PORT=5432
DB_DATABASE=tickora
DB_USERNAME=…
DB_PASSWORD=…

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Real email, or testers never receive their confirmation link.
MAIL_MAILER=smtp
MAIL_HOST=…
MAIL_PORT=587
MAIL_USERNAME=…
MAIL_PASSWORD=…
MAIL_FROM_ADDRESS="tickets@your-domain.com"   # on the domain you verified
MAIL_FROM_NAME="Tickora"

# Behind a proxy or load balancer that handles HTTPS (most hosts are):
TRUSTED_PROXIES=*

# Sandbox payments for testers (staging only; impossible in production).
TICKORA_PAYMENT_PROVIDER=fake
FAKE_PAYMENTS_WEBHOOK_SECRET=…   # any long random string

# Admins must use two-factor sign-in, as in production.
TICKORA_REQUIRE_TWO_FACTOR=true
```

**APP_KEY.** Payment provider keys and ticket QR codes are encrypted with it.
Changing it later means re-entering payment keys in the admin area, and
tickets already issued can no longer show their QR code. Back it up with the
database.

**TRUSTED_PROXIES.** Without it, behind an HTTPS proxy every email
confirmation link is rejected as "invalid signature". Use `*` when the app is
only reachable through the host's proxy, otherwise the proxy's IP addresses,
comma-separated.

## 3. Scheduler (required)

Releases expired ticket holds every minute. Add one cron entry:

```cron
* * * * * cd /path/to/tickora && php artisan schedule:run >> /dev/null 2>&1
```

A queue worker is not needed yet: emails are sent while the request runs.

## 4. Payments on staging

- **Sandbox (default):** testers pay on Tickora's own test payment screen.
  Nothing else to set up.
- **Stripe or Paystack test mode:** sign in as an admin, open
  **Payment settings**, paste the _test_ keys, switch the provider on, and
  add the webhook URL shown there in the provider's dashboard (test mode).
  Stripe test card: `4242 4242 4242 4242`, any future date, any CVC.

## 5. Demo data

`php artisan db:seed --force` creates demo organisers, a December programme
of events in Mauritius, and demo sales. **Every seeded account uses the
password `password`**, including `admin@tickora.test`. On a server testers
can reach:

- change the admin's password (Settings → Security) and set up two-factor
  sign-in straight away, or
- skip the seed and create real accounts instead; only categories are then
  needed: `php artisan db:seed --class=CategorySeeder --force`.

To make a real account a platform admin:

```bash
php artisan tinker --execute="App\Models\User::where('email','you@example.com')->first()->forceFill(['platform_role'=>'admin'])->save();"
```

## 6. Smoke test after each deploy

1. Sign up with a real address; the confirmation email arrives (check spam);
   the link confirms the account.
2. As an organiser: create an event with a photo and a ticket type, submit it.
3. As an admin: approve it in Event review.
4. As a customer: buy two tickets, pay on the sandbox screen, see the QR
   codes on the order page and receive the confirmation email.
5. Leave a checkout without paying for 10+ minutes; the tickets return to
   sale (the scheduler is running).
6. Admin overview and organiser overview show the sale.

## 7. The Android app

Testers install the APK from the repository's latest release
(`tickora-android.apk`) and type the server's address on first launch,
e.g. `staging.your-domain.com`. Nothing extra to set up on the server: the
app uses `/api/v1` (run `php artisan migrate` so the token table exists).

- `APP_URL` must be the public HTTPS address: payment providers return app
  buyers to a signed page on it, and event photos are linked from it.
- Smoke test: in the app, sign up, confirm the email, buy a ticket, pay on
  the sandbox screen, tap **Open Tickora**, and the ticket's QR code shows
  under **My tickets** (also with the phone offline).

## 8. Updating

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

## 9. Backups and logs

- Back up the database nightly (`pg_dump`) and keep `.env` (with `APP_KEY`)
  somewhere safe and separate.
- Uploaded event photos live in `storage/app/public`: back them up too, or
  use an S3-compatible disk (`TICKORA_MEDIA_DISK`).
- Errors are in `storage/logs/laravel.log`. An error tracker (e.g. Sentry,
  Flare) is worth adding before inviting many testers.

## Production differences

- `APP_ENV=production`: the sandbox provider no longer exists, two-factor is
  always required for admins, and passwords must be strong.
- Live Stripe/Paystack keys and live-mode webhooks (see the README).
- Never run `db:seed` with demo data.
