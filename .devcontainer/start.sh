#!/usr/bin/env bash
# Starts the Tickora test server (runs every time the Codespace starts).
set -euo pipefail
cd "$(dirname "$0")/.."

url="$(php .devcontainer/env.php)"
php artisan config:clear >/dev/null

pkill -f "artisan serve" 2>/dev/null || true
pkill -f "artisan schedule:work" 2>/dev/null || true
mkdir -p storage/logs

PHP_CLI_SERVER_WORKERS=4 setsid nohup php artisan serve --host=0.0.0.0 --port=8000 \
    > storage/logs/serve.log 2>&1 < /dev/null &
# Releases unpaid ticket holds every minute.
setsid nohup php artisan schedule:work > storage/logs/scheduler.log 2>&1 < /dev/null &

for _ in $(seq 1 30); do
    curl -fsS http://localhost:8000/up >/dev/null 2>&1 && break
    sleep 1
done
curl -fsS http://localhost:8000/up >/dev/null

# The app can't sign in to GitHub, so the address must be public.
if [ -n "${CODESPACE_NAME:-}" ]; then
    gh codespace ports visibility 8000:public -c "$CODESPACE_NAME" >/dev/null 2>&1 \
        || echo "!! Make port 8000 public: Ports tab > right-click 8000 > Port visibility > Public"
fi

cat <<INFO

  Tickora is running.

  Test link (type this into the app, or open it in a browser):
    $url

  Admin:  admin@tickora.test / $(cat .devcontainer/.admin-password 2>/dev/null || echo "see .devcontainer/.admin-password")
  Demo accounts (organisers, customers) use the password: password
  Payments use the sandbox screen; no real money.

INFO
