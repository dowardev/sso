#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACK_DIR="$SCRIPT_DIR/back"
FRONT_DIR="$SCRIPT_DIR/front"
BACKUP_KEEP=5
CURRENT_STEP=0

DEV_MODE=false
NON_INTERACTIVE=false
NO_BACKUP=false
HAS_NODE=false
HOSTING_ENV=""
SKIP_FRONT_BUILD=false
HEALTH_OK=true
HEALTH_RESULTS=()

color() { printf '\033[3%sm%s\033[0m' "$1" "$2"; }
log_ok()   { echo "  $(color 2 "[OK]")   $1"; }
log_info() { echo "  $(color 6 "[..]")  $1"; }
log_warn() { echo "  $(color 3 "[!]")   $1"; }
log_error(){ echo "  $(color 1 "[ER]")  $1"; }

confirm() {
    local prompt="$1" default="${2:-N}"
    if [ "$NON_INTERACTIVE" = true ]; then
        [[ "$default" =~ ^[Yy]$ ]]
        return $?
    fi
    local yn
    if [[ "$default" =~ ^[Yy]$ ]]; then
        printf "%s [Y/n]: " "$prompt" >&2
        read -r yn || true
        yn="${yn:-Y}"
    else
        printf "%s [y/N]: " "$prompt" >&2
        read -r yn || true
        yn="${yn:-N}"
    fi
    [[ "$yn" =~ ^[Yy]$ ]]
}

ask() {
    local prompt="$1" default="$2"
    if [ "$NON_INTERACTIVE" = true ]; then
        echo "$default"
        return 0
    fi
    local val
    printf "%s [%s]: " "$prompt" "$default" >&2
    read -r val || true
    echo "${val:-$default}"
}

ask_secret() {
    local prompt="$1" default="$2"
    if [ "$NON_INTERACTIVE" = true ]; then
        echo "$default"
        return 0
    fi
    local val
    printf "%s: " "$prompt" >&2
    read -s -r val || true
    echo >&2
    echo "${val:-$default}"
}

get_env_default() {
    local key="$1" file="$2"
    grep -E "^${key}=" "$file" | cut -d= -f2- || true
}

# Remove .env quoting from a captured value: strip wrapping double quotes
# and unescape inner \" and \\ so re-running sed_set doesn't double-escape.
unquote_env_val() {
    local val="$1"
    if [[ "$val" == \"*\" ]] && [ "${#val}" -ge 2 ]; then
        val="${val:1:${#val}-2}"
        val="$(printf '%s' "$val" | sed 's/\\\\/\\/g; s/\\"/"/g')"
    fi
    printf '%s' "$val"
}

require_cmd() {
    local bin="$1" hint="${2:-}"
    if ! command -v "$bin" >/dev/null 2>&1; then
        log_error "Required command not found: $bin"
        [ -n "$hint" ] && log_error "Hint: $hint"
        exit 2
    fi
}

backup_file() {
    local path="$1"
    [ "$NO_BACKUP" = true ] && return 0
    [ ! -f "$path" ] && return 0
    local ts
    ts="$(date +%Y%m%d_%H%M%S)"
    cp "$path" "${path}.bak.${ts}"
    local backups=()
    for f in "${path}.bak".*; do
        [ -f "$f" ] && backups+=("$f")
    done
    local count=${#backups[@]}
    if [ "$count" -gt "$BACKUP_KEEP" ]; then
        local remove_count=$((count - BACKUP_KEEP))
        for f in $(printf '%s\n' "${backups[@]}" | sort | head -n "$remove_count"); do
            rm -f "$f"
        done
    fi
}

sed_set() {
    local file="$1" key="$2" val="$3"
    local escaped_val="$val"
    local needs_quoting=false
    if [[ "$val" =~ [[:space:]#\"\'] ]] || [ -z "$val" ]; then
        needs_quoting=true
        escaped_val="${escaped_val//\"/\\\"}"
    fi
    escaped_val="${escaped_val//\\/\\\\}"
    escaped_val="${escaped_val//&/\\&}"
    escaped_val="${escaped_val//|/\\|}"
    if [ "$needs_quoting" = true ]; then
        escaped_val="\"${escaped_val}\""
    fi
    if grep -qE "^\s*${key}=" "$file" 2>/dev/null; then
        sed -i "s|^[[:space:]]*${key}=.*|${key}=${escaped_val}|" "$file"
    else
        echo "${key}=${escaped_val}" >> "$file"
    fi
}

normalize_url() {
    local value="$1"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    if [ -z "$value" ]; then
        return 1
    fi
    if [[ ! "$value" =~ ^https?:// ]]; then
        value="http://$value"
    fi
    echo "$value"
}

detect_hosting() {
    if [ -d "/usr/local/cpanel" ] || [ -n "${CPANEL:-}" ]; then
        echo "shared"
    elif command -v sudo >/dev/null 2>&1 || command -v apt >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
        echo "vps"
    else
        echo "shared"
    fi
}

usage() {
    cat <<EOF
Usage: $0 [OPTIONS]

SSO Server setup script for Laravel backend and Ionic/Vue frontend.

Options:
  --dev               Development mode (.env.development.example)
  --non-interactive   Non-interactive mode (requires SSO_* env vars)
  --no-backup         Skip backups of existing files
  --skip-front-build  Skip the frontend build step
  --help              Show this help message and exit

Exit codes:
  0   Success
  2   Missing prerequisite
  3   Missing required environment variable
  4   No TTY available (use --non-interactive)
  5   Database migration or seeding failed
  6   Write permission error
EOF
    exit 0
}

while [ $# -gt 0 ]; do
    case "$1" in
        --dev) DEV_MODE=true; shift ;;
        --non-interactive) NON_INTERACTIVE=true; shift ;;
        --no-backup) NO_BACKUP=true; shift ;;
        --skip-front-build) SKIP_FRONT_BUILD=true; shift ;;
        --help) usage ;;
        *) log_error "Unknown option: $1"; usage; exit 2 ;;
    esac
done

error_handler() {
    local exit_code=$?
    log_error "Error on line $1 (exit code: $exit_code)"
    exit "$exit_code"
}
trap 'error_handler $LINENO' ERR

banner() {
    echo ""
    echo "  +------------------------------------------+"
    echo "  |   SSO Server \u2014 Setup Script              |"
    echo "  +------------------------------------------+"
    echo ""
}

check_prereqs() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Checking prerequisites..."

    require_cmd php "Install PHP 8.2+ (e.g., apt install php8.2-cli)"
    require_cmd composer "Install Composer (https://getcomposer.org)"
    require_cmd openssl "Install OpenSSL (e.g., apt install openssl)"

    local php_ver
    php_ver="$(php -v | head -1 | sed -E 's/^PHP[[:space:]]+//; s/[[:space:]].*//')"
    if ! echo "$php_ver" | grep -qE '^[0-9]+\.[0-9]+'; then
        log_warn "Could not determine PHP version, assuming compatible"
    else
        local min_ver="8.2.0"
        if ! printf '%s\n' "$php_ver" "$min_ver" | sort -V | head -1 | grep -qF "$min_ver"; then
            log_error "PHP 8.2+ required, found $php_ver"
            exit 2
        fi
        log_ok "PHP $php_ver"
    fi

    if command -v node >/dev/null 2>&1 && command -v pnpm >/dev/null 2>&1; then
        HAS_NODE=true
        log_ok "Node.js + pnpm found"
    else
        HAS_NODE=false
        log_warn "Node.js/pnpm not found \u2014 frontend build will be skipped"
    fi

    HOSTING_ENV="$(detect_hosting)"
    log_info "Hosting environment detected: $HOSTING_ENV"

    log_ok "Prerequisites met"
}

configure_backend() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Configuring backend..."

    cd "$BACK_DIR"

    local example_file=""
    if [ "$DEV_MODE" = true ]; then
        example_file=".env.development.example"
    else
        example_file=".env.production.example"
    fi

    if [ ! -f "$example_file" ]; then
        log_warn "Backend example file not found: $example_file, trying .env.example"
        if [ -f ".env.example" ]; then
            example_file=".env.example"
        else
            log_error "Backend: no example file found (tried $example_file and .env.example)"
            log_error "Restore it from the repository (it is tracked in git) or re-run the deploy to upload the templates."
            exit 6
        fi
    fi

    # Preserve existing values so a re-run after a mid-way failure doesn't
    # force the user to re-enter everything: previous values become defaults.
    local prev_app_name prev_app_url prev_frontend_url
    local prev_db_host prev_db_port prev_db_database prev_db_username prev_db_password
    local prev_mail_host prev_mail_username prev_mail_password prev_mail_from_address
    if [ -f ".env" ]; then
        prev_app_name="$(unquote_env_val "$(get_env_default APP_NAME ".env")")"
        prev_app_url="$(unquote_env_val "$(get_env_default APP_URL ".env")")"
        prev_frontend_url="$(unquote_env_val "$(get_env_default FRONTEND_URL ".env")")"
        prev_db_host="$(unquote_env_val "$(get_env_default DB_HOST ".env")")"
        prev_db_port="$(unquote_env_val "$(get_env_default DB_PORT ".env")")"
        prev_db_database="$(unquote_env_val "$(get_env_default DB_DATABASE ".env")")"
        prev_db_username="$(unquote_env_val "$(get_env_default DB_USERNAME ".env")")"
        prev_db_password="$(unquote_env_val "$(get_env_default DB_PASSWORD ".env")")"
        prev_mail_host="$(unquote_env_val "$(get_env_default MAIL_HOST ".env")")"
        prev_mail_username="$(unquote_env_val "$(get_env_default MAIL_USERNAME ".env")")"
        prev_mail_password="$(unquote_env_val "$(get_env_default MAIL_PASSWORD ".env")")"
        prev_mail_from_address="$(unquote_env_val "$(get_env_default MAIL_FROM_ADDRESS ".env")")"
    fi

    backup_file ".env"
    if [ ! -f ".env" ]; then
        cp "$example_file" ".env"
    fi

    local app_name app_url frontend_url
    local db_host db_port db_database db_username db_password db_connection
    local mail_mailer mail_host mail_port mail_username mail_password mail_from_address
    local jwt_issuer sanctum_stateful_domains

    if [ "$NON_INTERACTIVE" = true ]; then
        db_connection="${SSO_DB_CONNECTION:-}"
        local missing=false
        for var in SSO_DB_HOST SSO_DB_PORT SSO_DB_DATABASE SSO_DB_USERNAME; do
            if [ -z "${!var:-}" ]; then
                log_error "Missing required env var: $var"
                missing=true
            fi
        done
        if [ "$missing" = true ]; then
            exit 3
        fi

        app_url="$(normalize_url "${SSO_APP_URL:-${prev_app_url:-$(get_env_default APP_URL "$example_file")}}")"
        frontend_url="$(normalize_url "${SSO_FRONTEND_URL:-${prev_frontend_url:-$(get_env_default FRONTEND_URL "$example_file")}}")"
        app_name="${SSO_APP_NAME:-${prev_app_name:-$(get_env_default APP_NAME "$example_file" | tr -d '"')}}"
        db_connection="${db_connection:-$(get_env_default DB_CONNECTION "$example_file")}"
        db_host="${SSO_DB_HOST:-$prev_db_host}"
        db_port="${SSO_DB_PORT:-$prev_db_port}"
        db_database="${SSO_DB_DATABASE:-$prev_db_database}"
        db_username="${SSO_DB_USERNAME:-$prev_db_username}"
        db_password="${SSO_DB_PASSWORD:-$prev_db_password}"
        mail_mailer="${SSO_MAIL_MAILER:-$(get_env_default MAIL_MAILER "$example_file")}"
        mail_host="${SSO_MAIL_HOST:-$prev_mail_host}"
        mail_port="${SSO_MAIL_PORT:-$(get_env_default MAIL_PORT "$example_file")}"
        mail_username="${SSO_MAIL_USERNAME:-$prev_mail_username}"
        mail_password="${SSO_MAIL_PASSWORD:-$prev_mail_password}"
        mail_from_address="${SSO_MAIL_FROM_ADDRESS:-$prev_mail_from_address}"
    else
        local default_app_name default_app_url default_frontend_url
        default_app_name="${prev_app_name:-$(grep -E "^APP_NAME=" "$example_file" | cut -d= -f2- | tr -d '"' || echo "SSO Server")}"
        default_app_url="${prev_app_url:-$(grep -E "^APP_URL=" "$example_file" | cut -d= -f2- || echo "http://localhost:8001")}"
        default_frontend_url="${prev_frontend_url:-$(grep -E "^FRONTEND_URL=" "$example_file" | cut -d= -f2- || echo "http://localhost:5173")}"

        app_name="$(ask "App name" "$default_app_name")"
        # Persist immediately so a cancelled/failed run keeps answered values.
        sed_set ".env" "APP_NAME" "$app_name"
        while :; do
            app_url="$(ask "App URL" "$default_app_url")"
            app_url="$(normalize_url "$app_url")" && break
            log_error "URL cannot be empty. Enter something like http://localhost:8001"
        done
        sed_set ".env" "APP_URL" "$app_url"
        while :; do
            frontend_url="$(ask "Frontend URL" "$default_frontend_url")"
            frontend_url="$(normalize_url "$frontend_url")" && break
            log_error "URL cannot be empty. Enter something like http://localhost:5173"
        done
        sed_set ".env" "FRONTEND_URL" "$frontend_url"

        db_connection="$(get_env_default DB_CONNECTION "$example_file")"
        db_host="$(ask "DB host" "${prev_db_host:-${DB_HOST:-127.0.0.1}}")"
        sed_set ".env" "DB_HOST" "$db_host"
        db_port="$(ask "DB port" "${prev_db_port:-${DB_PORT:-3306}}")"
        sed_set ".env" "DB_PORT" "$db_port"
        db_database="$(ask "DB database" "${prev_db_database:-${DB_DATABASE:-sso}}")"
        sed_set ".env" "DB_DATABASE" "$db_database"
        db_username="$(ask "DB username" "${prev_db_username:-${DB_USERNAME:-root}}")"
        sed_set ".env" "DB_USERNAME" "$db_username"
        db_password="$(ask_secret "DB password" "${prev_db_password:-${DB_PASSWORD:-}}")"
        sed_set ".env" "DB_PASSWORD" "$db_password"

        mail_mailer="$(get_env_default MAIL_MAILER "$example_file")"
        mail_port="$(get_env_default MAIL_PORT "$example_file")"
        local default_mail_host default_mail_username default_mail_password
        default_mail_host="${prev_mail_host:-${MAIL_HOST:-$(get_env_default MAIL_HOST "$example_file")}}"
        default_mail_username="${prev_mail_username:-${MAIL_USERNAME:-$(get_env_default MAIL_USERNAME "$example_file")}}"
        default_mail_password="${prev_mail_password:-${MAIL_PASSWORD:-$(get_env_default MAIL_PASSWORD "$example_file")}}"

        if [ "$mail_mailer" = "smtp" ]; then
            mail_host="$(ask "Mail host" "$default_mail_host")"
            sed_set ".env" "MAIL_HOST" "$mail_host"
            mail_username="$(ask "Mail username" "$default_mail_username")"
            sed_set ".env" "MAIL_USERNAME" "$mail_username"
            mail_password="$(ask_secret "Mail password" "$default_mail_password")"
            sed_set ".env" "MAIL_PASSWORD" "$mail_password"
        else
            mail_host="$default_mail_host"
            mail_username="$default_mail_username"
            mail_password="$default_mail_password"
        fi

        # Persist immediately so a cancelled/failed run keeps answered values.
        sed_set ".env" "MAIL_MAILER" "$mail_mailer"
        sed_set ".env" "MAIL_HOST" "$mail_host"
        sed_set ".env" "MAIL_PORT" "$mail_port"
        sed_set ".env" "MAIL_USERNAME" "$mail_username"
        sed_set ".env" "MAIL_PASSWORD" "$mail_password"
    fi

    jwt_issuer="$(echo "$app_url" | sed -E 's|/+$||')"
    sanctum_stateful_domains="$(echo "$frontend_url" | sed -E 's|^https?://||; s|/.*$||')"

    local app_url_host
    app_url_host="$(echo "$app_url" | sed -E 's|^https?://([^/]+).*|\1|')"
    mail_from_address="${mail_from_address:-${prev_mail_from_address:-noreply@${app_url_host}}}"

    if [ "$DEV_MODE" = true ]; then
        mail_mailer="log"
    fi

    sed_set ".env" "APP_NAME" "$app_name"
    sed_set ".env" "APP_URL" "$app_url"
    sed_set ".env" "FRONTEND_URL" "$frontend_url"
    sed_set ".env" "SANCTUM_STATEFUL_DOMAINS" "$sanctum_stateful_domains"
    sed_set ".env" "DB_CONNECTION" "$db_connection"
    sed_set ".env" "DB_HOST" "$db_host"
    sed_set ".env" "DB_PORT" "$db_port"
    sed_set ".env" "DB_DATABASE" "$db_database"
    sed_set ".env" "DB_USERNAME" "$db_username"
    sed_set ".env" "DB_PASSWORD" "$db_password"

    local db_check
    db_check="$(php -r '
        $env = file_get_contents(".env");
        $lines = explode("\n", $env);
        $vars = [];
        foreach ($lines as $line) {
            if (preg_match("/^([A-Z_]+)=\"?([^\"]*)\"?/", $line, $m)) {
                $vars[$m[1]] = $m[2];
            }
        }
        $driver = $vars["DB_CONNECTION"] ?? "mysql";
        if ($driver !== "mysql") {
            echo "FAIL:Driver " . $driver . " no soportado. Solo mysql.";
            return;
        }
        $dsn = "mysql:host={$vars["DB_HOST"]};port={$vars["DB_PORT"]};dbname={$vars["DB_DATABASE"]}";
        try {
            new PDO($dsn, $vars["DB_USERNAME"] ?? "", $vars["DB_PASSWORD"] ?? "");
            echo "OK";
        } catch (\PDOException $e) {
            echo "FAIL:" . $e->getMessage();
        }
    ' 2>/dev/null || echo "FAIL:Could not run PHP check")"
    local db_check_msg="${db_check#FAIL:}"
    case "$db_check" in
        "OK"*)
            log_ok "DB connection verified"
            ;;
        "FAIL:")
            log_error "Could not verify DB connection"
            exit 4
            ;;
        *"Access denied"*)
            log_error "DB access denied — wrong DB_USERNAME or DB_PASSWORD"
            exit 4
            ;;
        *"Connection refused"*|*"not reachable"*)
            log_error "DB host $db_host:$db_port not reachable — is MySQL running?"
            exit 4
            ;;
        *"Unknown database"*|*"Unknown MySQL server"*)
            log_error "DB database '$db_database' does not exist"
            exit 4
            ;;
        *"Driver"*)
            log_error "$db_check_msg"
            log_error "This script only supports mysql. Set DB_CONNECTION=mysql in .env"
            exit 4
            ;;
        *)
            log_error "DB connection failed: $db_check_msg"
            exit 4
            ;;
    esac

    sed_set ".env" "MAIL_MAILER" "$mail_mailer"
    sed_set ".env" "MAIL_HOST" "$mail_host"
    sed_set ".env" "MAIL_PORT" "$mail_port"
    sed_set ".env" "MAIL_USERNAME" "$mail_username"
    sed_set ".env" "MAIL_PASSWORD" "$mail_password"
    sed_set ".env" "MAIL_FROM_ADDRESS" "$mail_from_address"
    sed_set ".env" "JWT_ISSUER" "$jwt_issuer"

    log_ok "Backend configured"
    cd "$SCRIPT_DIR"
}

generate_app_key() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Generating app key..."

    cd "$BACK_DIR"
    if grep -qE "^APP_KEY=.+" ".env" 2>/dev/null; then
        log_ok "APP_KEY already set, skipping"
    else
        php artisan key:generate --force 2>&1 || true
        if grep -qE "^APP_KEY=base64:" ".env" 2>/dev/null; then
            log_ok "APP_KEY generated"
        else
            log_error "APP_KEY generation failed (no key in .env)"
            exit 5
        fi
    fi
    cd "$SCRIPT_DIR"
}

generate_jwt_keys() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Generating JWT keys..."

    cd "$BACK_DIR"
    mkdir -p keys

    if [ -f "keys/private.pem" ] || [ -f "keys/public.pem" ]; then
        if confirm "JWT keys already exist. Regenerate?" "N"; then
            local ts
            ts="$(date +%Y%m%d_%H%M%S)"
            mkdir -p "keys/backup/$ts"
            cp keys/private.pem "keys/backup/$ts/" 2>/dev/null || true
            cp keys/public.pem "keys/backup/$ts/" 2>/dev/null || true
            log_warn "Existing keys backed up to keys/backup/$ts/"
            log_warn "Refresh tokens issued with the old keys will be invalidated!"
            php artisan jwt:generate-keys --force
        else
            log_info "Keeping existing JWT keys"
        fi
    else
        php artisan jwt:generate-keys --force
    fi

    chmod 600 keys/private.pem 2>/dev/null || true
    chmod 644 keys/public.pem 2>/dev/null || true
    log_ok "JWT keys ready"
    cd "$SCRIPT_DIR"
}

migrate_db() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Running database migrations..."

    cd "$BACK_DIR"
    php artisan migrate --force || { log_error "Migration failed"; exit 5; }
    log_ok "Database migrated"
    cd "$SCRIPT_DIR"
}

seed_db() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Seeding database..."

    cd "$BACK_DIR"

    local user_count=0
    user_count="$(php artisan tinker --execute="echo App\\Models\\User::count();" 2>/dev/null || echo "0")"
    user_count="$(echo "$user_count" | tail -1 | tr -cd '0-9')"
    user_count="${user_count:-0}"

    if [ "$user_count" -gt 0 ]; then
        log_info "Found $user_count existing user(s)"
        if confirm "Seed database (add roles and settings)?" "N"; then
            php artisan db:seed --class=RoleSeeder --force
            php artisan db:seed --class=SettingSeeder --force
            log_ok "Database seeded (roles + settings)"
        else
            log_info "Seeding skipped"
        fi
    else
        log_info "No users found"
        if [ "$NON_INTERACTIVE" = true ]; then
            php artisan db:seed --class=RoleSeeder --force
            php artisan db:seed --class=SettingSeeder --force
            log_ok "Database seeded (roles + settings)"
        elif confirm "Seed database (add roles and settings)?" "Y"; then
            php artisan db:seed --class=RoleSeeder --force
            php artisan db:seed --class=SettingSeeder --force
            log_ok "Database seeded (roles + settings)"
        else
            log_info "Seeding skipped"
        fi
    fi

    # shellcheck source=/dev/null
    source ".env" 2>/dev/null || true
    cd "$SCRIPT_DIR"
}

configure_frontend() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Configuring frontend..."

    cd "$FRONT_DIR"

    local example_file target_file
    if [ "$DEV_MODE" = true ]; then
        example_file=".env.development.example"
        target_file=".env.development"
    else
        example_file=".env.production.example"
        target_file=".env.production"
    fi

    if [ ! -f "$example_file" ]; then
        log_warn "Frontend example file not found: $example_file, creating from generic example"
        if [ -f ".env.example" ]; then
            example_file=".env.example"
        else
            log_error "No frontend .env example found"
            cd "$SCRIPT_DIR"
            return
        fi
    fi

    # Verify the frontend env file is configured. The Vite build bakes these
    # values into the bundle at compile time, so deploying without the file
    # configured (or with example placeholders) breaks the app in production.
    if [ ! -f "$target_file" ]; then
        log_warn "Frontend env file not found: $target_file"
        log_warn "You must configure it: copy $example_file to $target_file and set the real URLs (VITE_API_URL, etc.) before building."
        if [ "$DEV_MODE" = true ]; then
            log_info "Development mode: creating $target_file from the example template. You can adjust the values afterwards."
        else
            log_error "Production mode requires a configured $target_file. Create it from $example_file with the real production URLs, then re-run the script."
            cd "$SCRIPT_DIR"
            return 1
        fi
    fi

    # Preserve the existing VITE_API_URL so a re-run doesn't lose it.
    local prev_api_url=""
    if [ -f "$target_file" ]; then
        prev_api_url="$(grep -E "^VITE_API_URL=" "$target_file" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "")"
    fi

    backup_file "$target_file"
    if [ ! -f "$target_file" ]; then
        cp "$example_file" "$target_file"
    fi

    local backend_url default_api_url
    backend_url="$(grep -E "^APP_URL=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "http://localhost:8001")"
    default_api_url="${prev_api_url:-${backend_url}/api}"

    local api_url
    api_url="$(ask "VITE_API_URL" "$default_api_url")"
    # Persist immediately so a cancelled/failed run keeps the answered value.
    sed_set "$target_file" "VITE_API_URL" "$api_url"

    log_ok "Frontend configured"
    cd "$SCRIPT_DIR"
}

build_frontend() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Building frontend..."

    if [ "$HOSTING_ENV" = "shared" ]; then
        SKIP_FRONT_BUILD=true
        cat <<EOF

  !!! Frontend build skipped: shared hosting detected.

  Build the frontend on a development machine and upload the dist/ directory:

      cd front
      pnpm install
      pnpm run build
EOF
        log_info "Frontend build skipped (shared hosting)"
        return
    fi

    if [ "$HAS_NODE" = false ]; then
        SKIP_FRONT_BUILD=true
        cat <<EOF

  !!! Frontend build skipped: Node.js/pnpm not available.

  To build the frontend manually on a development machine:

      cd front
      pnpm install
      pnpm run build

  Then upload the dist/ directory to your server.
EOF
        log_info "Frontend build skipped (no Node.js)"
        return
    fi

    cd "$FRONT_DIR"
    if [ ! -d "node_modules" ]; then
        log_info "Installing frontend dependencies..."
        pnpm install || { log_warn "pnpm install failed"; SKIP_FRONT_BUILD=true; cd "$SCRIPT_DIR"; return; }
    fi

    log_info "Building frontend..."
    if pnpm run build; then
        log_ok "Frontend built successfully"
    else
        SKIP_FRONT_BUILD=true
        log_warn "Frontend build failed"
    fi
    cd "$SCRIPT_DIR"
}

healthcheck() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    log_info "Step $CURRENT_STEP: Running health checks..."

    cd "$BACK_DIR"

    local all_ok=true
    HEALTH_RESULTS=()

    local check_result=""

    check_result="$(php -r '
        $env = file_get_contents(".env");
        $lines = explode("\n", $env);
        $vars = [];
        foreach ($lines as $line) {
            if (preg_match("/^([A-Z_]+)=\"?([^\"]*)\"?/", $line, $m)) {
                $vars[$m[1]] = $m[2];
            }
        }
        $driver = $vars["DB_CONNECTION"] ?? "mysql";
        if ($driver !== "mysql") {
            echo "FAIL:Driver " . $driver . " no soportado. Solo mysql.";
            return;
        }
        $dsn = "mysql:host={$vars["DB_HOST"]};port={$vars["DB_PORT"]};dbname={$vars["DB_DATABASE"]}";
        try {
            new PDO($dsn, $vars["DB_USERNAME"] ?? "", $vars["DB_PASSWORD"] ?? "");
            echo "OK";
        } catch (\PDOException $e) {
            echo "FAIL: " . $e->getMessage();
        }
    ' 2>/dev/null || echo "FAIL: Exception")"
    if echo "$check_result" | grep -q "^OK"; then
        HEALTH_RESULTS+=("PASS|DB connection")
    else
        HEALTH_RESULTS+=("FAIL|DB connection: $check_result")
        all_ok=false
    fi

    if [ -f "keys/private.pem" ]; then
        if openssl rsa -check -noout -in keys/private.pem 2>/dev/null; then
            HEALTH_RESULTS+=("PASS|PEM private key")
        else
            HEALTH_RESULTS+=("FAIL|PEM private key is invalid")
            all_ok=false
        fi
    else
        HEALTH_RESULTS+=("FAIL|PEM private key not found")
        all_ok=false
    fi

    local app_url_val port
    app_url_val="$(grep -E "^APP_URL=" ".env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "http://localhost:8001")"
    port="$(echo "$app_url_val" | sed -E 's|^https?://[^:]+:?([0-9]+).*|\1|')"
    if [ "$port" = "$app_url_val" ] || [ -z "$port" ]; then
        if echo "$app_url_val" | grep -q "^https:"; then port=443; else port=80; fi
    fi

    if curl -sf "http://localhost:${port}/api/.well-known/jwks" >/dev/null 2>&1; then
        HEALTH_RESULTS+=("PASS|JWKS endpoint")
    else
        HEALTH_RESULTS+=("WARN|JWKS endpoint unreachable (server may not be running)")
    fi

    if { php artisan migrate:status --no-ansi 2>&1 || true; } | grep -q "Pending"; then
        HEALTH_RESULTS+=("FAIL|Pending migrations detected")
        all_ok=false
    else
        HEALTH_RESULTS+=("PASS|All migrations applied")
    fi

    if { php artisan route:list --no-ansi 2>&1 || true; } | grep -q "\.well-known/jwks"; then
        HEALTH_RESULTS+=("PASS|JWKS route registered")
    else
        HEALTH_RESULTS+=("FAIL|JWKS route not registered")
        all_ok=false
    fi

    if php artisan config:clear 2>&1; then
        HEALTH_RESULTS+=("PASS|Config cache cleared")
    else
        HEALTH_RESULTS+=("WARN|Config cache could not be cleared")
    fi

    HEALTH_OK=$all_ok
    cd "$SCRIPT_DIR"
}

print_summary() {
    echo ""
    echo "  +------------------------------------------+"
    echo "  |   SSO Server \u2014 Setup Complete             |"
    echo "  +------------------------------------------+"
    echo ""

    echo "  Configuration"
    echo "    APP_URL:        $(grep -E "^APP_URL=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "N/A")"
    echo "    FRONTEND_URL:   $(grep -E "^FRONTEND_URL=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || echo "N/A")"
    echo "    DB:             $(grep -E "^DB_CONNECTION=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- || echo "N/A") @ $(grep -E "^DB_HOST=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- || echo "N/A"):$(grep -E "^DB_PORT=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- || echo "N/A")/$(grep -E "^DB_DATABASE=" "$BACK_DIR/.env" 2>/dev/null | cut -d= -f2- || echo "N/A")"
    echo "    Hosting:        $HOSTING_ENV"
    echo ""

    echo "  Health Checks"
    for result in "${HEALTH_RESULTS[@]}"; do
        local status="${result%%|*}"
        local label="${result#*|}"
        if [ "$status" = "PASS" ]; then
            printf "    \033[32m[PASS]\033[0m %s\n" "$label"
        elif [ "$status" = "WARN" ]; then
            printf "    \033[33m[WARN]\033[0m %s\n" "$label"
        else
            printf "    \033[31m[FAIL]\033[0m %s\n" "$label"
        fi
    done
    echo ""

    echo "  Next Steps"
    if [ "$HOSTING_ENV" = "shared" ]; then
        echo "    1. Schedule cron in your hosting panel (cPanel/Plesk):"
        echo "       * * * * * cd $BACK_DIR && php artisan schedule:run >> /dev/null 2>&1"
        echo "    2. Access the setup wizard in your browser"
        echo "    3. Configure SSL/TLS for your domain"
    else
        echo "    1. Set up cron:"
        echo "       * * * * * cd $BACK_DIR && php artisan schedule:run >> /dev/null 2>&1"
        echo "    2. Run the setup wizard:"
        if [ "$DEV_MODE" = false ]; then
            echo "    3. Configure SSL/TLS for your domain"
        fi
        echo "    $([ "$DEV_MODE" = false ] && echo 4 || echo 3). Verify health: php artisan route:list | grep jwks"
    fi
    echo ""
}

main() {
    if [ ! -t 0 ] && [ "$NON_INTERACTIVE" != true ]; then
        log_error "No TTY available. Use --non-interactive for unattended setup."
        exit 4
    fi

    banner

    if [ "$NON_INTERACTIVE" != true ]; then
        if ! confirm "This script will configure the SSO Server. Continue?" "Y"; then
            log_info "Setup cancelled by user"
            exit 0
        fi
    fi

    if [ ! -d "$BACK_DIR" ]; then
        log_error "Backend directory not found: $BACK_DIR"
        exit 6
    fi
    if [ ! -d "$FRONT_DIR" ]; then
        log_error "Frontend directory not found: $FRONT_DIR"
        exit 6
    fi

    check_prereqs

    cd "$BACK_DIR"
    if [ ! -d "vendor" ]; then
        log_info "Installing Composer dependencies..."
        composer install --no-interaction --prefer-dist
    else
        log_info "Composer dependencies already installed"
    fi
    cd "$SCRIPT_DIR"

    configure_backend
    generate_app_key
    generate_jwt_keys
    migrate_db
    seed_db

    # The frontend env (.env.production) is only needed where the frontend is
    # actually built: Vite bakes the VITE_* values into the bundle at compile
    # time. On shared hosting (or without Node.js/pnpm, or with
    # --skip-front-build) the dist/ directory is built on a dev machine and
    # uploaded, so there is no build here that would read .env.production —
    # requiring it would block setup with a file that is never used on the
    # server. configure_frontend is therefore skipped in those environments.
    if [ "$HOSTING_ENV" = "shared" ] || [ "$HAS_NODE" = false ] || [ "$HAS_PNPM" = false ] || [ "$SKIP_FRONT_BUILD" = true ]; then
        log_info "Frontend env config skipped: frontend is built on a dev machine and dist/ is uploaded (shared hosting / no Node.js / --skip-front-build)"
    else
        configure_frontend
    fi

    if [ "$SKIP_FRONT_BUILD" != true ]; then
        build_frontend
    fi

    healthcheck
    print_summary

    log_ok "SSO Server setup complete"
}

main "$@"
