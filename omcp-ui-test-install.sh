#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# Fresh loopback-only UI installation, pinned to a successful panel smoke build.
revision=c3b252101c3d647c7a87b64cd245c46d5cd860f0
repo=https://github.com/onionnot66-cpu/OMCP-PANEL-PRO.git
dest="$HOME/omcp-ui-test"
mode="${1:-}"
if [[ "$#" -gt 1 || ( -n "$mode" && "$mode" != --check && "$mode" != --auto-db ) ]]; then
    echo 'Usage: bash omcp-ui-test-install.sh [--check|--auto-db]' >&2
    exit 2
fi

preflight() {
    local failed=0 cmd
    if [[ "$(uname -s)" != Linux ]]; then echo 'Missing: Linux environment'; failed=1; fi
    if [[ "$EUID" -eq 0 ]]; then echo 'Use a non-root Linux user for installation.'; failed=1; fi
    for cmd in git php composer node yarn; do
        if command -v "$cmd" >/dev/null; then printf 'Found: %s\n' "$cmd"; else echo "Missing: $cmd"; failed=1; fi
    done
    if command -v php >/dev/null; then
        php -r '$bad=PHP_VERSION_ID<80200; if($bad) echo "PHP 8.2+ required\n"; foreach (["pdo_mysql","mbstring","posix","zip","openssl","curl","xml","dom","fileinfo","ctype","tokenizer","bcmath"] as $e) {if (!extension_loaded($e)) {echo "Missing PHP extension: $e\n"; $bad=true;}} exit($bad?1:0);' || failed=1
    fi
    if command -v node >/dev/null; then node -e 'if(Number(process.versions.node.split(".")[0])<22){console.log("Node.js 22+ required");process.exit(1)}' || failed=1; fi
    if command -v yarn >/dev/null; then [[ "$(yarn --version)" == 1.* ]] || { echo 'Yarn Classic (1.x) required.'; failed=1; }; fi
    if [[ -e "$dest" || -L "$dest" ]]; then echo "Existing installation path: $dest (will not overwrite)"; failed=1; fi
    if [[ "$failed" -ne 0 ]]; then echo 'Preflight failed; resolve the listed items.'; return 1; fi
    echo 'Preflight passed. Database connectivity and network downloads are checked during installation.'
}

preflight
if [[ "$mode" == --check ]]; then exit 0; fi

if [[ "$mode" == --auto-db ]]; then
    command -v sudo >/dev/null || { echo 'Automatic DB creation requires sudo and an existing local database server.' >&2; exit 1; }
    mysql=/usr/bin/mariadb
    [[ -x "$mysql" ]] || mysql=/usr/bin/mysql
    [[ -x "$mysql" ]] || { echo 'Install/start MariaDB or MySQL first.' >&2; exit 1; }
    read -r -p 'Create a NEW local test database and restricted database user using sudo? [y/N] ' answer </dev/tty
    [[ "$answer" == y || "$answer" == Y ]] || exit 1
    suffix="$(php -r 'echo bin2hex(random_bytes(6));')"
    db="omcp_test_$suffix"
    dbuser="$db"
    dbpass="$(php -r 'echo bin2hex(random_bytes(24));')"
    sudo -v
    sudo -- "$mysql" --protocol=socket --batch <<SQL
CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$dbuser'@'127.0.0.1' IDENTIFIED BY '$dbpass';
GRANT ALL PRIVILEGES ON $db.* TO '$dbuser'@'127.0.0.1';
SQL
    echo "Created dedicated database: $db"
else
    echo 'OMCP private UI test — use an existing EMPTY local MariaDB/MySQL database.'
    echo 'Create a dedicated database beginning with omcp_test_ and a restricted database user beforehand.'
    read -r -p 'Database name: ' db </dev/tty
    read -r -p 'Database username: ' dbuser </dev/tty
    read -r -s -p 'Database password: ' dbpass </dev/tty
    echo
fi
[[ "$db" =~ ^omcp_test_[a-zA-Z0-9_]{1,40}$ && "$dbuser" =~ ^[a-zA-Z0-9_]{1,32}$ && "$dbpass" =~ ^[a-zA-Z0-9_-]{16,128}$ ]] || {
    echo 'Use a dedicated test database, valid username and random 16+ character safe password.' >&2
    exit 1
}
OMCP_DB="$db" OMCP_DB_USER="$dbuser" OMCP_DB_PASS="$dbpass" php -r '
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=".getenv("OMCP_DB"),getenv("OMCP_DB_USER"),getenv("OMCP_DB_PASS"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($pdo->query("SHOW TABLES")->fetch()) throw new RuntimeException("Database must be empty.");
} catch (Throwable $e) { fwrite(STDERR,"Database unavailable or not empty.\n"); exit(1); }
'
trap 'echo "Installation failed. Test directory/database may remain; no automatic deletion. Inspect the preceding error." >&2' ERR
mkdir -m 700 "$dest"
git -C "$dest" init -q
git -C "$dest" remote add origin "$repo"
git -C "$dest" fetch --depth 1 origin "$revision"
git -C "$dest" checkout --detach FETCH_HEAD
[[ "$(git -C "$dest" rev-parse HEAD)" == "$revision" ]]
cd "$dest"
install -d -m 700 storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
salt="$(php -r 'echo bin2hex(random_bytes(24));')"
appkey="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
cat > .env <<ENV
APP_NAME=OMCP
APP_ENV=local
APP_DEBUG=false
APP_KEY=$appkey
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
unset dbpass appkey salt
chmod 600 .env
# Isolate the new test deployment from inherited service configuration.
unset DB_URL DATABASE_URL DB_SOCKET DB_DATABASE DB_USERNAME DB_PASSWORD DB_HOST DB_PORT DB_CONNECTION DB_PREFIX APP_KEY APP_NAME APP_URL APP_ENV APP_DEBUG APP_ENVIRONMENT_ONLY APP_THEME APP_STORAGE_PATH APP_BASE_PATH CACHE_STORE CACHE_DRIVER SESSION_DRIVER SESSION_DOMAIN SESSION_SECURE_COOKIE QUEUE_CONNECTION MAIL_MAILER RECAPTCHA_ENABLED HASHIDS_SALT APP_CONFIG_CACHE APP_ROUTES_CACHE APP_EVENTS_CACHE APP_SERVICES_CACHE APP_PACKAGES_CACHE VIEW_COMPILED_PATH
composer install --no-dev --prefer-dist --no-interaction --no-scripts
composer check-platform-reqs --no-dev
php artisan package:discover --ansi
yarn install --frozen-lockfile --non-interactive
NODE_OPTIONS=--max-old-space-size=3072 yarn build:production
test -s public/assets/manifest.json
# Confirm the application actually targets the disposable database before migrating.
OMCP_EXPECTED_DB="$db" php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("database.default")!=="mysql" || config("database.connections.mysql.database")!==getenv("OMCP_EXPECTED_DB") || config("database.connections.mysql.host")!=="127.0.0.1") {
    fwrite(STDERR,"Unexpected effective database configuration; refusing migration.\n"); exit(1);
}
'
php artisan migrate --seed --force
php artisan route:list --path=api/omcp/v1
echo 'Create a private test administrator. Enter a new password at the hidden prompt.'
php artisan p:user:make --admin=1 </dev/tty
echo 'UI test installation complete. Local listener only; use private SSH port forwarding if remote.'
echo 'To restart: cd ~/omcp-ui-test && php artisan serve --host=127.0.0.1 --port=8080'
echo 'Private UI testing only. OMCP Connect requires a separate HTTPS test setup; not a production deployment.'
exec php artisan serve --host=127.0.0.1 --port=8080
