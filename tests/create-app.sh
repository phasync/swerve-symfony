#!/bin/sh
# Create the Symfony test application in tests/Fixtures/app, for Symfony version $1 (6.4, 7.4):
# the framework's own skeleton, with this package installed from the checkout and the test
# routes of tests/Fixtures/routes added. Idempotent. SWERVE_PATH, when set, installs phasync/swerve
# from that directory instead of the one next to this checkout (../swerve).
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
# phasync/swerve-symfony from this checkout, by the fixture's own autoload rule, not a path
# repository: this checkout contains the fixture, and a path repository's symlink (or its copy)
# would make the fixture's vendor contain the fixture, recursively
php -r '$c = json_decode(file_get_contents("composer.json")); $c->autoload->{"psr-4"}->{"Swerve\\Symfony\\"} = "../../../src/"; $c->autoload->files = ["../../../src/functions.php"]; file_put_contents("composer.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
composer config repositories.swerve "{\"type\": \"path\", \"url\": \"${SWERVE_PATH:-../../../../swerve}\", \"options\": {\"symlink\": true, \"versions\": {\"phasync/swerve\": \"0.1.0-beta6\"}}}"
composer require --no-interaction --no-progress --no-scripts \
    "symfony/twig-bundle:$version.*" "symfony/security-bundle:$version.*" \
    "symfony/security-csrf:$version.*" "symfony/mime:$version.*" "phasync/swerve:^0.1.0-beta6"
cp -r ../routes/. .
printf 'APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=swerve-symfony-tests\n' > .env.local
rm -rf var
bin/console cache:warmup
# The sessions table, in WAL mode so that the workers read while one writes
php -r 'require "vendor/autoload.php"; (new PDO("sqlite:var/sessions.db"))->exec("PRAGMA journal_mode=WAL"); (new Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler("sqlite:var/sessions.db"))->createTable();'
# No vendor copy of this package exists (see above): declare its adapter by hand, as swerve reads
# it from vendor/composer/installed.json (phasync/swerve-psr15's own test fixtures do the same)
php -r '
$file = "vendor/composer/installed.json";
$data = json_decode(file_get_contents($file), true);
$key  = isset($data["packages"]) ? "packages" : null;
$list = $key ? $data[$key] : $data;
foreach ($list as $p) {
    if ("phasync/swerve-symfony" === $p["name"]) { exit; }
}
$list[] = ["name" => "phasync/swerve-symfony", "version" => "dev-checkout", "type" => "library", "extra" => ["swerve" => ["adapter" => "symfony", "entry" => "Swerve\\Symfony\\entry"]]];
if ($key) { $data[$key] = $list; } else { $data = $list; }
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
'
