#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Fresh, private development UI installation only. Never use production data.
# No root, Docker, Wings, systemd or public web listener is required.
revision=16d35167a3858710bfe24c707b9986ed63085fb0
repo=https://github.com/onionnot66-cpu/OMCP-PANEL-PRO.git
dest="$HOME/omcp-ui-test"
if [[ "$EUID" -eq 0 ]]; then echo 'Use a non-root Linux account.' >&2; exit 1; fi
if [[ "$(uname -s)" != Linux ]]; then echo 'Linux required.' >&2; exit 1; fi
if [[ -e "$dest" || -L "$dest" ]]; then echo "Refusing to overwrite $dest." >&2; exit 1; fi
for cmd in git php composer node yarn; do
    command -v "$cmd" >/dev/null || { echo "Missing dependency: $cmd" >&2; exit 1; }
done
php -r 'if (PHP_VERSION_ID < 80200) exit(1); foreach (["pdo_mysql","mbstring","posix","zip","openssl","curl","xml","dom","fileinfo","ctype","tokenizer","bcmath"] as $e) {if (!extension_loaded($e)) {fwrite(STDERR,"Missing PHP extension: $e\n"); exit(1);}}'
node -e 'if (Number(process.versions.node.split(".")[0]) < 22) process.exit(1)'
[[ "$(yarn --version)" == 1.* ]] || { echo 'Yarn Classic (1.x) required.' >&2; exit 1; }
echo 'OMCP private UI test — use an existing EMPTY local MariaDB/MySQL database.'
echo 'Create a dedicated database beginning with omcp_test_ and a restricted database user beforehand.'
read -r -p 'Database name: ' db </dev/tty
read -r -p 'Database username: ' dbuser </dev/tty
read -r -s -p 'Database password: ' dbpass </dev/tty
echo
[[ "$db" =~ ^omcp_test_[a-zA-Z0-9_]{1,40}$ && "$dbuser" =~ ^[a-zA-Z0-9_]{1,32}$ && "$dbpass" =~ ^[a-zA-Z0-9_-]{16,128}$ ]] || {
    echo 'Use a dedicated test database, valid username, and a random 16+ character password containing letters, digits, underscores or hyphens.' >&2
    exit 1
}
# Fail before changing anything if credentials are incorrect or database is not empty.
OMCP_DB="$db" OMCP_DB_USER="$dbuser" OMCP_DB_PASS="$dbpass" php -r '
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=".getenv("OMCP_DB"),getenv("OMCP_DB_USER"),getenv("OMCP_DB_PASS"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query("SHOW TABLES")->fetch()) throw new RuntimeException("Database must be empty.");
} catch (Throwable $e) { fwrite(STDERR,"Database unavailable or not empty.\n"); exit(1); }
'
mkdir -m 700 "$dest"
git -C "$dest" init -q
git -C "$dest" remote add origin "$repo"
git -C "$dest" fetch --depth 1 origin "$revision"
git -C "$dest" checkout --detach FETCH_HEAD
cd "$dest"
install -d -m 700 storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
salt="$(php -r 'echo bin2hex(random_bytes(24));')"
cat > .env <<ENV
APP_NAME=OMCP
APP_ENV=local
APP_DEBUG=false
APP_KEY=
APP_THEME=pterodactyl
APP_URL=http://127.0.0.1:8080
APP_ENVIRONMENT_ONLY=true
APP_TIMEZONE=UTC
APP_LOCALE=en
HASHIDS_SALT=$salt
LOG_CHANNEL=daily
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$db
DB_USERNAME=$dbuser
DB_PASSWORD=$dbpass
CACHE_DRIVER=file
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
RECAPTCHA_ENABLED=false
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME=OMCP
PTERODACTYL_TELEMETRY_ENABLED=false
ENV
unset dbpass
chmod 600 .env
# Prevent unrelated inherited deployment settings from overriding the test file.
unset DB_URL DATABASE_URL DB_SOCKET DB_DATABASE DB_USERNAME DB_PASSWORD DB_HOST DB_PORT DB_CONNECTION DB_PREFIX APP_KEY APP_URL APP_ENV APP_DEBUG APP_STORAGE_PATH APP_BASE_PATH CACHE_STORE CACHE_DRIVER SESSION_DRIVER QUEUE_CONNECTION MAIL_MAILER RECAPTCHA_ENABLED HASHIDS_SALT APP_CONFIG_CACHE
composer install --no-dev --prefer-dist --no-interaction --no-scripts
composer check-platform-reqs --no-dev
php artisan package:discover --ansi
php artisan key:generate --force
php artisan migrate --seed --force
yarn install --frozen-lockfile --non-interactive
NODE_OPTIONS=--max-old-space-size=3072 yarn build:production
test -s public/assets/manifest.json
php artisan route:list --path=api/omcp/v1
echo 'Create a private test administrator. Enter a new password at the hidden prompt.'
php artisan p:user:make --admin=1 </dev/tty
echo 'UI test installation complete. Local listener only; use private SSH port forwarding if remote.'
echo 'To restart: cd ~/omcp-ui-test && php artisan serve --host=127.0.0.1 --port=8080'
echo 'Not a production deployment. No Minecraft server creation without the legacy daemon yet.'
exec php artisan serve --host=127.0.0.1 --port=8080
