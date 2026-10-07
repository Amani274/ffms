#!/bin/sh
# Container entrypoint: configure, (optionally) start local MariaDB, migrate, seed if empty, run Apache.
set -eu

: "${PORT:=10000}"
: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_NAME:=ffms}"
: "${DB_USER:=ffms}"
: "${SEED_ON_START:=1}"
APP=/var/www/html/ffms

# Local database unless DB_HOST points somewhere else (external MySQL/MariaDB)
if [ -z "${LOCAL_DB:-}" ]; then
  case "$DB_HOST" in 127.0.0.1|localhost) LOCAL_DB=1 ;; *) LOCAL_DB=0 ;; esac
fi
if [ "$LOCAL_DB" = "1" ] && [ -z "${DB_PASS:-}" ]; then
  DB_PASS=$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)
fi

# Apache listens on Render's $PORT
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Hand the configuration to PHP via .env (Apache does not reliably pass container env vars on)
cat > "$APP/.env" <<EOF
APP_ENV=production
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=${DB_PASS:-}
DB_SSL=${DB_SSL:-0}
CURRENCY=${CURRENCY:-ZMW}
WEATHER_LIVE=${WEATHER_LIVE:-1}
SESSION_IDLE_MIN=${SESSION_IDLE_MIN:-240}
DEMO_PASSWORD=${DEMO_PASSWORD:-Farm@Demo2026}
EOF
chown www-data:www-data "$APP/.env"
chmod 640 "$APP/.env"

if [ "$LOCAL_DB" = "1" ]; then
  mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
  mysqld_safe --user=mysql >/var/log/mysqld-safe.log 2>&1 &
  i=0
  until mysqladmin ping --silent 2>/dev/null; do
    i=$((i + 1))
    [ "$i" -gt 60 ] && { echo "MariaDB did not start"; cat /var/log/mysqld-safe.log; exit 1; }
    sleep 1
  done
  mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
fi

cd "$APP"
php bin/migrate.php
if [ "$SEED_ON_START" = "1" ]; then
  php bin/seed.php || echo "Seed skipped (database already has data)."
fi

exec apache2-foreground
