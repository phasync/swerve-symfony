#!/bin/sh
# Create the Symfony test application in tests/Fixtures/app, for Symfony version $1 (6.4, 7.4):
# the framework's own skeleton, with this package installed from the checkout and the test
# routes of tests/Fixtures/routes added. Idempotent. SWERVE_PATH, when set, installs swerve from
# that directory instead of Packagist.
set -eu
version=$1
cd "$(dirname "$0")/Fixtures"
if [ "$(cat app/.symfony-version 2>/dev/null)" != "$version" ]; then
    rm -rf app
    composer create-project --no-interaction --no-progress "symfony/skeleton:$version.*" app
    echo "$version" > app/.symfony-version
fi
cd app
composer config minimum-stability dev
composer config prefer-stable true
# The adapter from the checkout, by its autoload rule: a path repository's symlink would make the
# tests directory contain itself
php -r '$c = json_decode(file_get_contents("composer.json")); $c->autoload->{"psr-4"}->{"Swerve\\Symfony\\"} = "../../../src/"; file_put_contents("composer.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
if [ -n "${SWERVE_PATH:-}" ]; then
    composer config repositories.swerve "{\"type\": \"path\", \"url\": \"$SWERVE_PATH\", \"options\": {\"symlink\": false, \"versions\": {\"phasync/swerve\": \"0.1.0-alpha12\"}}}"
fi
composer require --no-interaction --no-progress --no-scripts \
    "symfony/twig-bundle:$version.*" "symfony/security-bundle:$version.*" \
    "symfony/security-csrf:$version.*" "symfony/mime:$version.*" "phasync/swerve:^0.1.0-alpha15"
cp -r ../routes/. .
printf 'APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=swerve-symfony-tests\n' > .env.local
rm -rf var
bin/console cache:warmup
# The sessions table, in WAL mode so that the workers read while one writes
php -r 'require "vendor/autoload.php"; (new PDO("sqlite:var/sessions.db"))->exec("PRAGMA journal_mode=WAL"); (new Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler("sqlite:var/sessions.db"))->createTable();'
