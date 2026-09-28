#!/bin/sh
# The Symfony 7.4 skeleton of the tests (tests/Fixtures/app, prod, debug off) on PHP-FPM behind
# nginx, and on swerve without and with phasync-ext: 4 workers each, opcache on, wrk -t4 -c64
# -d10s, one benchmark at a time on the machine. Raw output goes to results/.
#
#   benchmarks/run.sh [path/to/phasync.so]
set -eu
here=$(cd "$(dirname "$0")" && pwd)
app=$here/../tests/Fixtures/app
ext=${1:-/home/frode/dev/phasync-ext/modules/phasync.so}
bench=/home/frode/dev/fpm-bench
[ "$(cat "$app/.symfony-version" 2>/dev/null)" = 7.4 ] || "$here/../tests/create-app.sh" 7.4
mkdir -p "$here/results"
# The routes: the JSON route; a session counter (read and written through PdoSessionHandler on
# SQLite, the same for both servers); a route that waits 10 ms in usleep(), as a query does
# (blocking the worker without phasync-ext)
routes="json:/json session:/counter wait:/usleep?ms=10"

up() { # wait until the server answers
    for _ in $(seq 100); do curl -sf -o /dev/null "http://$1/json" && return; sleep 0.1; done
    echo "no server at $1" >&2; exit 1
}
run() { # server name, address
    sid=$(curl -s -D - -o /dev/null "http://$2/counter" | sed -n 's/^[Ss]et-[Cc]ookie: PHPSESSID=\([^;]*\).*/\1/p')
    for route in $routes; do
        name=${route%%:*} path=${route#*:}
        curl -sf -o /dev/null -b "PHPSESSID=$sid" "http://$2$path" # warm up
        flock "$bench/bench.lock" wrk -t4 -c64 -d10s -H "Cookie: PHPSESSID=$sid" "http://$2$path" > "$here/results/$1-$name.txt"
        echo "$1 $name: $(grep Requests/sec "$here/results/$1-$name.txt")"
    done
}

"$bench/serve.sh" "$app/public" 18790 4 > /dev/null 2>&1 & pid=$!
up 127.0.0.1:18790; run fpm 127.0.0.1:18790; kill $pid; wait $pid 2>/dev/null || true

cd "$app"
php -d opcache.enable_cli=1 vendor/bin/swerve --workers=4 --http=127.0.0.1:18791 --no-access-log -q swerve.php & pid=$!
up 127.0.0.1:18791; run swerve 127.0.0.1:18791; kill $pid; wait $pid 2>/dev/null || true

php -d opcache.enable_cli=1 -d extension="$ext" vendor/bin/swerve --workers=4 --http=127.0.0.1:18792 --no-access-log -q swerve.php & pid=$!
up 127.0.0.1:18792; run swerve-ext 127.0.0.1:18792; kill $pid; wait $pid 2>/dev/null || true
