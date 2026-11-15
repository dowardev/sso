#!/usr/bin/env bash
# Verifica que el ciclo de migraciones sea reversible en ambos motores.
#
#   back/verify-migration-rollback.sh [mysql|sqlite]
#
# MySQL usa una base aislada (sso_rollback_check) y no toca la base real.
# SQLite usa un archivo temporal.
set -euo pipefail

cd "$(dirname "$0")"

DRIVER="${1:-mysql}"
MYSQL_DB="${MYSQL_ROLLBACK_DB:-sso_rollback_check}"
SQLITE_FILE="${SQLITE_ROLLBACK_FILE:-/tmp/sso_rollback_check.sqlite}"

run_artisan() {
    if [ "$DRIVER" = "sqlite" ]; then
        DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_FILE" php artisan "$@"
    else
        DB_DATABASE="$MYSQL_DB" php artisan "$@"
    fi
}

cleanup() {
    if [ "$DRIVER" = "sqlite" ]; then
        rm -f "$SQLITE_FILE"
    else
        mysql -u root -ptoor -e "DROP DATABASE IF EXISTS \`$MYSQL_DB\`;" 2>/dev/null || true
    fi
}
trap cleanup EXIT

cleanup

echo "== $DRIVER: migrate =="
run_artisan migrate --force >/dev/null
echo "   ok"

for attempt in 1 2; do
    echo "== $DRIVER: migrate:refresh #$attempt =="
    run_artisan migrate:refresh --force >/dev/null
    echo "   ok"
done

echo "== $DRIVER: migrate:reset (rollback completo) =="
run_artisan migrate:reset --force >/dev/null
echo "   ok"

echo "== $DRIVER: migrate (re-aplica tras reset) =="
run_artisan migrate --force >/dev/null
echo "   ok"

echo "PASS: ciclo de migraciones reversible en $DRIVER"
