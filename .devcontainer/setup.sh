#!/usr/bin/env bash
# One-time setup of the Tickora test server (runs when the Codespace is
# created): PHP extensions, packages, database with demo data, front end.
set -euo pipefail
cd "$(dirname "$0")/.."

SUDO=""
[ "$(id -u)" != "0" ] && SUDO="sudo"
export COMPOSER_ALLOW_SUPERUSER=1

echo "==> PHP extensions"
missing=""
for ext in intl gd exif bcmath pcntl; do
    php -m | grep -qix "$ext" || missing="$missing $ext"
done
if [ -n "$missing" ]; then
    $SUDO apt-get update -qq
    $SUDO apt-get install -y -qq libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev sqlite3 >/dev/null
    $SUDO docker-php-ext-configure gd --with-freetype --with-jpeg >/dev/null
    # shellcheck disable=SC2086
    $SUDO docker-php-ext-install -j"$(nproc)" $missing >/dev/null
fi
php -m | grep -qix pdo_sqlite

echo "==> PHP packages"
composer install --no-interaction --prefer-dist --no-progress

echo "==> Settings and database"
[ -f .env ] || cp .env.example .env
php .devcontainer/env.php
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
touch database/database.sqlite
php artisan config:clear
php artisan migrate --force

if [ "$(php artisan tinker --execute='echo App\Models\User::count();' | tail -1)" = "0" ]; then
    echo "==> Demo data (December events in Mauritius)"
    php artisan db:seed --force

    # The demo admin gets a random password: the test link is public.
    password="$(php -r 'echo bin2hex(random_bytes(6));')"
    php artisan tinker --execute="App\Models\User::where('email','admin@tickora.test')->first()?->forceFill(['password' => '${password}'])->save();" >/dev/null
    echo "$password" > .devcontainer/.admin-password
fi
php artisan storage:link --force

echo "==> Front end"
npm install --no-audit --no-fund --loglevel=error
npm run build

echo "==> Setup complete"
