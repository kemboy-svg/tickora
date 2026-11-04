<?php

/*
 * Writes the test server's settings into .env. Run on every start: the
 * public address comes from the Codespace, and email settings from its
 * secrets, which can change between starts.
 */

$file = __DIR__.'/../.env';
$env = file_get_contents($file);

$set = function (string $key, string $value) use (&$env): void {
    if (preg_match('/[\s#"\'$]/', $value)) {
        $value = '"'.addcslashes($value, '"\\$').'"';
    }
    $line = $key.'='.$value;
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
    $env = preg_match($pattern, $env)
        ? preg_replace_callback($pattern, fn () => $line, $env)
        : rtrim($env)."\n".$line."\n";
};

$codespace = getenv('CODESPACE_NAME');
$domain = getenv('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN');
$url = $codespace && $domain ? "https://{$codespace}-8000.{$domain}" : 'http://localhost:8000';

$set('APP_NAME', 'Tickora');
$set('APP_ENV', 'staging');
$set('APP_DEBUG', 'false');
$set('APP_URL', $url);
$set('LOG_LEVEL', 'info');

$set('DB_CONNECTION', 'sqlite');
$set('DB_DATABASE', realpath(__DIR__.'/..').'/database/database.sqlite');
$set('SESSION_DRIVER', 'database');
$set('CACHE_STORE', 'database');
$set('QUEUE_CONNECTION', 'sync');

// GitHub's port forwarding is an HTTPS proxy in front of the server.
$set('TRUSTED_PROXIES', '*');

$set('TICKORA_PAYMENT_PROVIDER', 'fake');
if (! preg_match('/^FAKE_PAYMENTS_WEBHOOK_SECRET=.+$/m', $env)) {
    $set('FAKE_PAYMENTS_WEBHOOK_SECRET', bin2hex(random_bytes(24)));
}
$set('TICKORA_REQUIRE_TWO_FACTOR', 'false');

if (getenv('MAIL_HOST')) {
    $set('MAIL_MAILER', 'smtp');
    $set('MAIL_HOST', (string) getenv('MAIL_HOST'));
    $set('MAIL_PORT', getenv('MAIL_PORT') ?: '587');
    $set('MAIL_USERNAME', (string) getenv('MAIL_USERNAME'));
    $set('MAIL_PASSWORD', (string) getenv('MAIL_PASSWORD'));
    $set('MAIL_SCHEME', 'null');
    if (getenv('MAIL_FROM_ADDRESS')) {
        $set('MAIL_FROM_ADDRESS', (string) getenv('MAIL_FROM_ADDRESS'));
    }
} else {
    $set('MAIL_MAILER', 'log');
}

file_put_contents($file, $env);
echo $url, PHP_EOL;
