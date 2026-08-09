#!/usr/bin/env bash
# Unified deploy script — handles fullstack, single (Laravel SSR), and docs-only projects
# Configuration via scripts/deploy.conf (PROJECT_TYPE determines flow)
#
# Usage:
#   ./scripts/deploy.sh                 # lint+tests + build + deploy from current commit
#   ./scripts/deploy.sh --backend       # only backend (fullstack only)
#   ./scripts/deploy.sh --frontend      # only frontend (fullstack only)
#   ./scripts/deploy.sh --all           # both backend+frontend (fullstack, explicit)
#   ./scripts/deploy.sh --tag v1.2.3    # checkout tag first, then lint+tests + build + deploy
#   ./scripts/deploy.sh --skip-checks   # skip lint+tests (faster, use with caution)
#   ./scripts/deploy.sh --dry-run       # print commands without executing scp/ssh
#   ./scripts/deploy.sh --verbose       # show all commands being executed (debug)
#   ./scripts/deploy.sh -h|--help       # show this help
#
# Secrets: .env at repo root (gitignored)
# Copy .env.example → .env and fill in your values.
#
# Project config: scripts/deploy.conf (commited)
# Copy scripts/deploy.conf.example → scripts/deploy.conf if needed.
#
# Required env vars (from .env):
#   SSH_HOST, SSH_PORT, SSH_USER
#   DEPLOY_PATH_BACKEND, DEPLOY_PATH_FRONTEND (fullstack)
#   DEPLOY_PATH (single, docs)
#   SSH_PRIVATE_KEY (content) OR SSH_PRIVATE_KEY_PATH (file) — optional
#   SSH_KNOWN_HOSTS — optional
#
# Required config vars (from scripts/deploy.conf):
#   PROJECT_TYPE="fullstack|single|docs"
#   Fullstack: BACKEND_DIR, FRONTEND_DIR, BACKEND_BUILD_CMD, BACKEND_ARTIFACT_EXCLUDES,
#              BACKEND_REMOTE_CMD, FRONTEND_BUILD_CMD, FRONTEND_ARTIFACT_SOURCE
#   Single:    BUILD_CMD, ARTIFACT_EXCLUDES, REMOTE_CMD
#   Docs:      BUILD_CMD, ARTIFACT_SOURCE, ARTIFACT_NAME,
#              VERSION_CHECK_PKG, VERSION_CHECK_FILE (optional)
#   All types: SETUP_SCRIPT (optional) — repo-root first-run setup script
#              uploaded to the server app root and made executable (never run)

set -euo pipefail

# ============================================================
# Colors & Logging
# ============================================================
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

VERBOSE=false
DRY_RUN=false
SKIP_CHECKS=false
DEPLOY_BACKEND=true
DEPLOY_FRONTEND=true
TARGET_REF=""

log_info()  { echo -e "${GREEN}[INFO]${NC} $*"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
log_err()   { echo -e "${RED}[ERR]${NC} $*"; }
log_debug() { [[ "$VERBOSE" == "true" ]] && echo -e "${CYAN}[DEBUG]${NC} $*" || true; }
log_step()  { echo -e "\n${BOLD}${BLUE}▶ $*${NC}"; }
log_phase() { echo -e "\n${BOLD}${CYAN}═══ $* ═══${NC}"; }

# ============================================================
# Args
# ============================================================
while [[ $# -gt 0 ]]; do
  case "$1" in
    --backend)       DEPLOY_FRONTEND=false; shift ;;
    --frontend)      DEPLOY_BACKEND=false; shift ;;
    --all)           DEPLOY_BACKEND=true; DEPLOY_FRONTEND=true; shift ;;
    --tag)           TARGET_REF="$2"; shift 2 ;;
    --skip-checks)   SKIP_CHECKS=true; shift ;;
    --dry-run)       DRY_RUN=true; shift ;;
    --verbose)       VERBOSE=true; shift ;;
    -h|--help)
      sed -n '2,30p' "$0"
      exit 0
      ;;
    *)
      log_err "Unknown argument: $1"
      echo "Run with --help for usage." >&2
      exit 1
      ;;
  esac
done

# ============================================================
# Paths & Load Config
# ============================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DEPLOY_DIR="$REPO_ROOT/scripts/deploy"
ENV_FILE="$DEPLOY_DIR/.env"
CONF_FILE="$DEPLOY_DIR/deploy.conf"

log_info "Repository root: $REPO_ROOT"
[[ "$DRY_RUN" == "true" ]] && log_warn "DRY-RUN MODE — no changes will be made"
[[ "$VERBOSE" == "true" ]] && log_info "Verbose output enabled"
[[ "$SKIP_CHECKS" == "true" ]] && log_warn "Checks skipped (--skip-checks)"

# Load .env (secrets) — scripts/deploy/
if [[ ! -f "$ENV_FILE" ]]; then
  log_err "$ENV_FILE not found."
  log_err "Copy scripts/deploy/.env.example → scripts/deploy/.env and fill in your server values."
  exit 1
fi
# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a
log_info "Loaded .env"

# Load deploy.conf (project config) — scripts/deploy/
if [[ -f "$REPO_ROOT/scripts/deploy.conf" ]]; then
  CONF_FILE="$REPO_ROOT/scripts/deploy.conf"
  log_info "Config: $CONF_FILE (override)"
fi
if [[ ! -f "$CONF_FILE" ]]; then
  log_err "Config file not found: $CONF_FILE"
  log_err "Copy scripts/deploy/deploy.conf.example → scripts/deploy/deploy.conf"
  exit 1
fi
# shellcheck disable=SC1090
source "$CONF_FILE"
log_info "Loaded config: $CONF_FILE (PROJECT_TYPE=${PROJECT_TYPE:-unset})"

# ============================================================
# Validate PROJECT_TYPE
# ============================================================
case "${PROJECT_TYPE:-}" in
  fullstack|single|docs) ;;
  "")
    log_err "PROJECT_TYPE not set in $CONF_FILE (must be: fullstack|single|docs)"
    exit 1
    ;;
  *)
    log_err "Invalid PROJECT_TYPE: $PROJECT_TYPE (must be: fullstack|single|docs)"
    exit 1
    ;;
esac

# ============================================================
# Validate Required Env Vars (by type)
# ============================================================
REQUIRED_ENV_COMMON=("SSH_HOST" "SSH_PORT" "SSH_USER")
for var in "${REQUIRED_ENV_COMMON[@]}"; do
  if [[ -z "${!var:-}" ]]; then
    log_err "Missing required env var: $var (in $ENV_FILE)"
    exit 1
  fi
done

case "$PROJECT_TYPE" in
  fullstack)
    for var in DEPLOY_PATH_BACKEND DEPLOY_PATH_FRONTEND; do
      if [[ -z "${!var:-}" ]]; then
        log_err "Missing required env var for fullstack: $var (in $ENV_FILE)"
        exit 1
      fi
    done
    ;;
  single|docs)
    if [[ -z "${DEPLOY_PATH:-}" ]]; then
      log_err "Missing required env var: DEPLOY_PATH (in $ENV_FILE)"
      exit 1
    fi
    ;;
esac

# SSH auth optional
if [[ -z "${SSH_PRIVATE_KEY:-}" && -z "${SSH_PRIVATE_KEY_PATH:-}" ]]; then
  log_warn "No SSH_PRIVATE_KEY or SSH_PRIVATE_KEY_PATH set — will use ssh-agent"
fi

# ============================================================
# Validate Required Config Vars (by type)
# ============================================================
validate_conf() {
  local missing=()
  for var in "$@"; do
    if [[ -z "${!var:-}" ]]; then
      missing+=("$var")
    fi
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    log_err "Missing required config vars in $CONF_FILE: ${missing[*]}"
    exit 1
  fi
}

case "$PROJECT_TYPE" in
  fullstack)
    validate_conf BACKEND_DIR FRONTEND_DIR BACKEND_BUILD_CMD BACKEND_ARTIFACT_EXCLUDES \
      BACKEND_REMOTE_CMD FRONTEND_BUILD_CMD FRONTEND_ARTIFACT_SOURCE
    ;;
  single)
    validate_conf BUILD_CMD ARTIFACT_EXCLUDES REMOTE_CMD
    ;;
docs)
      validate_conf BUILD_CMD ARTIFACT_SOURCE ARTIFACT_NAME TEST_CMD
      ;;
esac

# ============================================================
# SSH Setup — mirrors .gitlab-ci.yml .ssh_setup exactly
# ============================================================
setup_ssh() {
  local ssh_dir="$HOME/.ssh"

  if [[ "$DRY_RUN" == "true" ]]; then
    log_debug "SKIP setup_ssh (dry-run) — no ~/.ssh writes"
    SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes)
    SCP_OPTS=(-P "$SSH_PORT")
    return 0
  fi

  mkdir -p "$ssh_dir"
  chmod 700 "$ssh_dir"

  if [[ -n "${SSH_PRIVATE_KEY:-}" ]]; then
    if [[ -f "$ssh_dir/id_rsa" ]]; then
      log_warn "SSH auth: SSH_PRIVATE_KEY set but $ssh_dir/id_rsa already exists — keeping existing key"
      USE_KEY_FILE=true
      KEY_SOURCE="existing-file"
    else
      echo "$SSH_PRIVATE_KEY" > "$ssh_dir/id_rsa"
      chmod 600 "$ssh_dir/id_rsa"
      log_info "SSH auth: SSH_PRIVATE_KEY (GitLab CI style)"
      USE_KEY_FILE=true
      KEY_SOURCE="SSH_PRIVATE_KEY"
    fi
  elif [[ -n "${SSH_PRIVATE_KEY_PATH:-}" && -f "${SSH_PRIVATE_KEY_PATH:-}" ]]; then
    log_info "SSH auth: SSH_PRIVATE_KEY_PATH ($SSH_PRIVATE_KEY_PATH)"
    USE_KEY_FILE=true
    KEY_SOURCE="SSH_PRIVATE_KEY_PATH"
  else
    log_info "SSH auth: ssh-agent (no explicit key)"
    USE_KEY_FILE=false
    KEY_SOURCE="ssh-agent"
  fi

  if [[ -n "${SSH_KNOWN_HOSTS:-}" ]]; then
    if grep -qF -- "$SSH_KNOWN_HOSTS" "$ssh_dir/known_hosts" 2>/dev/null; then
      log_debug "SSH known_hosts: already present, skip append"
    else
      echo "$SSH_KNOWN_HOSTS" >> "$ssh_dir/known_hosts"
      log_info "SSH known_hosts: loaded from SSH_KNOWN_HOSTS"
    fi
  fi

  if [[ ! -f "$ssh_dir/config" ]]; then
    cat > "$ssh_dir/config" <<'SSH_CONFIG'
Host *
  HostKeyAlgorithms +ssh-rsa
  PubkeyAcceptedAlgorithms +ssh-rsa
  StrictHostKeyChecking accept-new
SSH_CONFIG
    chmod 600 "$ssh_dir/config"
    log_info "SSH config: created $ssh_dir/config"
  else
    log_warn "SSH config: $ssh_dir/config exists — NOT overwriting (add HostKeyAlgorithms/StrictHostKeyChecking lines manually if needed)"
  fi

  if [[ "$KEY_SOURCE" == "existing-file" ]]; then
    # Key file already present: do NOT start a fresh agent — a new agent is
    # empty and would overwrite SSH_AUTH_SOCK, orphaning any running agent
    # and losing its loaded keys. ssh falls back to the default identity
    # file ~/.ssh/id_rsa automatically.
    unset SSH_AUTH_SOCK SSH_AGENT_PID 2>/dev/null || true
    SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes)
    SCP_OPTS=(-P "$SSH_PORT")
  elif [[ "$USE_KEY_FILE" == "true" ]]; then
    eval "$(ssh-agent -s)" >/dev/null
    if [[ "$KEY_SOURCE" == "SSH_PRIVATE_KEY" ]]; then
      ssh-add "$ssh_dir/id_rsa" 2>/dev/null
    elif [[ "$KEY_SOURCE" == "SSH_PRIVATE_KEY_PATH" ]]; then
      ssh-add "$SSH_PRIVATE_KEY_PATH" 2>/dev/null
    fi
    export SSH_AUTH_SOCK SSH_AGENT_PID
    SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes)
    SCP_OPTS=(-P "$SSH_PORT")
  else
    SSH_OPTS=(-p "$SSH_PORT" -o BatchMode=yes)
    SCP_OPTS=(-P "$SSH_PORT")
    export SSH_AUTH_SOCK SSH_AGENT_PID
  fi

  log_debug "SSH_OPTS: ${SSH_OPTS[*]}"
  log_debug "SCP_OPTS: ${SCP_OPTS[*]}"
}

setup_ssh

# ============================================================
# Optional tag checkout
# ============================================================
cd "$REPO_ROOT"
ORIG_BRANCH=""
restore_original_branch() {
  if [[ -n "$ORIG_BRANCH" && "$DRY_RUN" != "true" ]]; then
    if git checkout "$ORIG_BRANCH" 2>/dev/null; then
      log_info "Restored branch: $ORIG_BRANCH"
    else
      log_warn "Branch restore failed — repo left detached (was on $ORIG_BRANCH)"
    fi
  fi
}
trap restore_original_branch EXIT

if [[ -n "$TARGET_REF" ]]; then
  log_phase "CHECKOUT: $TARGET_REF"
  if [[ "$DRY_RUN" == "true" ]]; then
    log_debug "git fetch --tags"
    log_debug "git checkout $TARGET_REF"
  else
    ORIG_BRANCH="$(git symbolic-ref --short HEAD 2>/dev/null || git rev-parse --short HEAD 2>/dev/null || true)"
    git fetch --tags
    git checkout "$TARGET_REF"
    log_info "Checked out $TARGET_REF"
  fi
fi

# ============================================================
# Helpers
# ============================================================
run_cmd() {
  local desc="$1"
  shift
  local cmd="$*"

  log_step "$desc"
  log_debug "Command: $cmd"

  if [[ "$DRY_RUN" == "true" ]]; then
    log_debug "[dry-run] $cmd"
    return 0
  fi

  if ( eval "$cmd" ); then
    log_info "✓ $desc completed"
    return 0
  else
    local exit_code=$?
    log_err "✗ $desc failed (exit $exit_code)"
    return $exit_code
  fi
}

run_remote() {
  local desc="$1"
  local remote_cmd="$2"

  log_step "$desc"
  log_debug "Remote command:\n$remote_cmd"

  if [[ "$DRY_RUN" == "true" ]]; then
    log_debug "[dry-run] ssh ${SSH_OPTS[*]} $SSH_USER@$SSH_HOST <<< \$REMOTE_CMD"
    return 0
  fi

  if ssh "${SSH_OPTS[@]}" "$SSH_USER@$SSH_HOST" "$remote_cmd"; then
    log_info "✓ $desc completed"
    return 0
  else
    local exit_code=$?
    log_err "✗ $desc failed (exit $exit_code)"
    return $exit_code
  fi
}

# Ensure pnpm is available without re-running the global corepack mutation every deploy.
ensure_pnpm() {
  if ! command -v pnpm >/dev/null 2>&1; then
    log_debug "pnpm not on PATH — running corepack enable once"
    corepack enable
  else
    log_debug "pnpm already on PATH — skip corepack enable"
  fi
}

# ============================================================
# Test Environment Validation
# ============================================================
validate_test_environment() {
  local project_root="${1:-$PWD}"
  local backend_dir="${2:-$project_root}"
  local missing=()
  local hints=()

  # Check 1: PHP sqlite driver (loaded)
  if ! php -m 2>/dev/null | grep -qi 'pdo_sqlite'; then
    missing+=("Extensión PHP pdo_sqlite NO CARGADA")
    
    # Check if package is installed but not enabled
    local sqlite_so=$(find /usr/lib/php/modules -name 'pdo_sqlite.so' 2>/dev/null | head -1)
    if [[ -n "$sqlite_so" ]]; then
      hints+=("  → El paquete está instalado ($sqlite_so) pero NO habilitado en php.ini")
      hints+=("  → Editá /etc/php/php.ini y descomentá:")
      hints+=("      extension=pdo_sqlite")
      hints+=("      extension=sqlite3")
      hints+=("  → Luego reiniciá PHP-FPM / servidor web o volvé a abrir la terminal")
    else
      hints+=("  → Instalá paquete del sistema:")
      hints+=("      sudo apt-get install php-sqlite3    # Debian/Ubuntu")
      hints+=("      sudo pacman -S php-sqlite             # Arch")
      hints+=("      sudo dnf install php-sqlite3          # Fedora/RHEL")
      hints+=("  → En Arch/Fedora: tras instalar, habilitá en /etc/php/php.ini:")
      hints+=("      extension=pdo_sqlite")
      hints+=("      extension=sqlite3")
    fi
  fi

  # Print a clean code block for copy-paste (no log prefixes)
  print_code_block() {
    local title="$1"
    shift
    echo
    log_info "═══ $title ═══"
    for line in "$@"; do
      echo "$line"
    done
    log_info "══════════════════════════════════"
    echo
  }

  # Check 2: APP_KEY in .env.testing or phpunit.xml
  local has_app_key=false
  local env_testing_exists=false
  if [[ -f "$backend_dir/.env.testing" ]]; then
    env_testing_exists=true
    grep -q "^APP_KEY=" "$backend_dir/.env.testing" && has_app_key=true
  fi
  if [[ -f "$backend_dir/phpunit.xml" ]] || [[ -f "$backend_dir/phpunit.xml.dist" ]]; then
    local phpunit_file="$backend_dir/phpunit.xml"
    [[ -f "$backend_dir/phpunit.xml.dist" ]] && phpunit_file="$backend_dir/phpunit.xml.dist"
    grep -q 'name="APP_KEY"' "$phpunit_file" && has_app_key=true
  fi
  if [[ "$has_app_key" == "false" ]]; then
    if [[ "$env_testing_exists" == "false" ]]; then
      missing+=("Archivo .env.testing NO EXISTE")
      # printf is universal (bash, zsh, fish, sh, dash, ksh)
      hints+=("codeblock|Crealo PRIMERO (key:generate NO crea el archivo)|printf 'APP_NAME=Laravel\\nAPP_ENV=testing\\nAPP_KEY=\\nAPP_DEBUG=false\\nAPP_URL=http://localhost\\n\\nDB_CONNECTION=sqlite\\nDB_DATABASE=:memory:\\n\\nCACHE_DRIVER=array\\nSESSION_DRIVER=array\\nQUEUE_DRIVER=sync\\nMAIL_MAILER=array\\n' > $backend_dir/.env.testing")
      hints+=("  → LUEGO generá la key:")
      hints+=("      cd $backend_dir && php artisan key:generate --env=testing")
    else
      missing+=(".env.testing existe pero SIN APP_KEY")
      hints+=("  → Generá: cd $backend_dir && php artisan key:generate --env=testing")
    fi
    # Also check phpunit.xml
    local phpunit_has_key=false
    if [[ -f "$backend_dir/phpunit.xml" ]] || [[ -f "$backend_dir/phpunit.xml.dist" ]]; then
      local phpunit_file="$backend_dir/phpunit.xml"
      [[ -f "$backend_dir/phpunit.xml.dist" ]] && phpunit_file="$backend_dir/phpunit.xml.dist"
      grep -q 'name="APP_KEY"' "$phpunit_file" && phpunit_has_key=true
    fi
    if [[ "$phpunit_has_key" == "false" ]]; then
      hints+=("  → O agregá a phpunit.xml: <env name=\"APP_KEY\" value=\"base64:...\"/>")
    fi
  fi

  if [[ ${#missing[@]} -gt 0 ]]; then
    log_warn "Entorno de test incompleto — se omitirán tests de backend"
    for m in "${missing[@]}"; do
      log_warn "  ✗ $m"
    done
    for h in "${hints[@]}"; do
      if [[ "$h" == codeblock* ]]; then
        # Parse codeblock: codeblock|title|line1|line2|...
        IFS='|' read -ra parts <<< "$h"
        print_code_block "${parts[1]}" "${parts[@]:2}"
      else
        log_info "$h"
      fi
    done
    return 1
  fi

  return 0
}

# ============================================================
run_checks() {
  if [[ "$SKIP_CHECKS" == "true" ]]; then
    log_phase "PRE-DEPLOY CHECKS SKIPPED (--skip-checks)"
    return 0
  fi

  log_phase "PRE-DEPLOY CHECKS"

  case "$PROJECT_TYPE" in
    fullstack)
      if [[ "$DEPLOY_BACKEND" == "true" ]]; then
        log_phase "BACKEND CHECKS"
        if [[ ! -f "$BACKEND_DIR/vendor/bin/pint" ]]; then
          run_cmd "Backend: install dev dependencies" \
            "cd $BACKEND_DIR && composer install --no-interaction"
        fi
        run_cmd "Backend: lint (pint)" \
          "cd $BACKEND_DIR && vendor/bin/pint --test"
        
        if validate_test_environment "$PWD" "$BACKEND_DIR"; then
          run_cmd "Backend: tests (PHPUnit, sqlite :memory:)" \
            "cd $BACKEND_DIR && php artisan test"
        else
          if [[ "$DRY_RUN" == "true" ]]; then
            log_warn "Dry-run: backend test env incomplete — checks would fail in a real run"
          else
            log_err "BACKEND TESTS NO EJECUTADOS — entorno de test incompleto"
            log_err "Instalá lo que falta arriba ↑ y re-ejecutá, o usá --skip-checks para omitir a propósito"
            return 1
          fi
        fi
      fi

      if [[ "$DEPLOY_FRONTEND" == "true" ]]; then
        log_phase "FRONTEND CHECKS"
        run_cmd "Frontend: lint" \
          "cd $FRONTEND_DIR && pnpm run lint"
        run_cmd "Frontend: unit tests (vitest --run)" \
          "cd $FRONTEND_DIR && pnpm exec vitest --run"
      fi
      ;;

    single)
      run_cmd "Backend: lint (pint)" \
        "composer install --prefer-dist --no-progress --no-scripts --no-interaction && vendor/bin/pint --test"
      run_cmd "Frontend: lint (oxlint + types)" \
        "ensure_pnpm && pnpm install --frozen-lockfile && pnpm run lint:oxlint && pnpm run types:check"
      
      if validate_test_environment "$PWD" "$PWD"; then
        run_cmd "Backend: tests (PHPUnit, sqlite :memory:)" \
          "composer install --prefer-dist --no-progress --no-interaction && php artisan test"
      else
        if [[ "$DRY_RUN" == "true" ]]; then
          log_warn "Dry-run: backend test env incomplete — checks would fail in a real run"
        else
          log_err "BACKEND TESTS NO EJECUTADOS — entorno de test incompleto"
          log_err "Instalá lo que falta arriba ↑ y re-ejecutá, o usá --skip-checks para omitir a propósito"
          return 1
        fi
      fi
      ;;

    docs)
      run_cmd "Lint (all workspaces)" "pnpm -r lint"
      run_cmd "Unit tests" "$TEST_CMD"
      ;;
  esac
}

# ============================================================
# Deploy Functions (by type)
# ============================================================
deploy_fullstack() {
  # Backend
  if [[ "$DEPLOY_BACKEND" == "true" ]]; then
    log_phase "BACKEND DEPLOY"

    TMP_ROOT="/tmp/backend-deploy-$$"
    BUILD_DIR="$TMP_ROOT/back"
    TARBALL="/tmp/backend.tar.gz"

    run_cmd "Backend: prepare build directory" \
      "mkdir -p '$BUILD_DIR' && cp -r $BACKEND_DIR/* $BACKEND_DIR/.[^.]* '$BUILD_DIR/'"

    # Local packages (composer path repositories like ../packages/*) must be
    # siblings of back/ so composer install can resolve them during the build.
    if [[ -d "$BACKEND_DIR/../packages" ]]; then
      run_cmd "Backend: stage local packages (path repositories)" \
        "cp -r $BACKEND_DIR/../packages '$TMP_ROOT/packages'"
    fi

    run_cmd "Backend: composer install --no-dev" \
      "cd '$BUILD_DIR' && composer install --no-dev --optimize-autoloader --no-interaction"

    run_cmd "Backend: cache config & routes" \
      "cd '$BUILD_DIR' && php artisan config:cache && php artisan route:cache"

    EXCLUDES=""
    for excl in $BACKEND_ARTIFACT_EXCLUDES; do
      EXCLUDES="$EXCLUDES --exclude='$excl'"
    done

    run_cmd "Backend: create tarball" \
      "eval tar -czf '$TARBALL' $EXCLUDES -C '$BUILD_DIR' ."

    run_cmd "Backend: cleanup build dir" \
      "rm -rf '$TMP_ROOT'"

    run_cmd "Backend: upload tarball to server" \
      "scp ${SCP_OPTS[*]} '$TARBALL' '$SSH_USER@$SSH_HOST:/tmp/backend.tar.gz'"

    REMOTE_CMD=$(cat <<EOF
set -e
mkdir -p "${DEPLOY_PATH_BACKEND}"
tar -xzf /tmp/backend.tar.gz -C "${DEPLOY_PATH_BACKEND}"
cd "${DEPLOY_PATH_BACKEND}"
$BACKEND_REMOTE_CMD
rm /tmp/backend.tar.gz
EOF
)
    run_remote "Backend: extract & deploy on server" "$REMOTE_CMD"

    if [[ "$DRY_RUN" != "true" ]]; then rm -f "$TARBALL"; fi
  fi

  # Frontend
  if [[ "$DEPLOY_FRONTEND" == "true" ]]; then
    log_phase "FRONTEND DEPLOY"

    run_cmd "Frontend: pnpm install --frozen-lockfile" \
      "cd $FRONTEND_DIR && ensure_pnpm && pnpm install --frozen-lockfile"

    run_cmd "Frontend: vite build" \
      "cd $FRONTEND_DIR && pnpm exec vite build"

    TARBALL="/tmp/frontend.tar.gz"

    run_cmd "Frontend: create tarball from dist/" \
      "[[ -d $FRONTEND_ARTIFACT_SOURCE ]] || { log_err \"$FRONTEND_ARTIFACT_SOURCE missing — build failed?\"; exit 1; } && tar -czf '$TARBALL' -C $FRONTEND_ARTIFACT_SOURCE ."

    run_cmd "Frontend: upload tarball to server" \
      "scp ${SCP_OPTS[*]} '$TARBALL' '$SSH_USER@$SSH_HOST:/tmp/frontend.tar.gz'"

    REMOTE_CMD=$(cat <<EOF
set -e
mkdir -p "${DEPLOY_PATH_FRONTEND}"
tar -xzf /tmp/frontend.tar.gz -C "${DEPLOY_PATH_FRONTEND}"
rm /tmp/frontend.tar.gz
EOF
)
    run_remote "Frontend: extract on server" "$REMOTE_CMD"

    if [[ "$DRY_RUN" != "true" ]]; then rm -f "$TARBALL"; fi
  fi
}

deploy_single() {
  log_phase "BUILD"
  run_cmd "Frontend: SSR build" "$BUILD_CMD"

  log_phase "DEPLOY"

  TARBALL="/tmp/deploy.tar.gz"
  EXCLUDES=""
  for excl in $ARTIFACT_EXCLUDES; do
    EXCLUDES="$EXCLUDES --exclude='$excl'"
  done

  run_cmd "Create deployment tarball" \
    "eval tar -czf '$TARBALL' $EXCLUDES ."

  run_cmd "Upload tarball to server" \
    "scp ${SCP_OPTS[*]} '$TARBALL' '$SSH_USER@$SSH_HOST:/tmp/deploy.tar.gz'"

  REMOTE_CMD_FULL=$(cat <<EOF
set -e
mkdir -p "${DEPLOY_PATH}"
tar -xzf /tmp/deploy.tar.gz -C "${DEPLOY_PATH}"
cd "${DEPLOY_PATH}"
$REMOTE_CMD
rm /tmp/deploy.tar.gz
EOF
)
  run_remote "Extract & deploy on server" "$REMOTE_CMD_FULL"

  if [[ "$DRY_RUN" != "true" ]]; then rm -f "$TARBALL"; fi
}

deploy_docs() {
  log_phase "INSTALL DEPENDENCIES"
  run_cmd "pnpm install --frozen-lockfile" "pnpm install --frozen-lockfile"

  log_phase "BUILD"
  run_cmd "Build all packages" "$BUILD_CMD"

  log_phase "PACKAGE ARTIFACT"
  run_cmd "Compress docs build" "cd '$ARTIFACT_SOURCE' && tar czf '../../$ARTIFACT_NAME' . && cd ../.."

  log_phase "UPLOAD"
  run_cmd "Upload tarball to server" "scp ${SCP_OPTS[*]} $ARTIFACT_NAME ${SSH_USER}@${SSH_HOST}:${DEPLOY_PATH}"

  log_phase "REMOTE EXTRACT"
  REMOTE_CMD="cd ${DEPLOY_PATH} && tar xzvf ${ARTIFACT_NAME} && rm ${ARTIFACT_NAME}"
  run_remote "Extract on server" "$REMOTE_CMD"

  log_phase "CLEANUP"
  [[ "$DRY_RUN" != "true" ]] && rm -f "$ARTIFACT_NAME"
  log_info "Local artifact cleaned up"
}

# ============================================================
# Setup Script (optional) — uploads a first-run setup script
# ============================================================
# If SETUP_SCRIPT is set in deploy.conf (path relative to repo root), the
# file is uploaded to the app root on the server (parent of the backend path
# for fullstack, DEPLOY_PATH otherwise), made executable, and a reminder is
# printed. It is NEVER executed automatically — setup scripts prompt for
# secrets (DB creds, JWT keys, etc.). Applies to all project types.
SETUP_REMOTE_DIR=""
SETUP_DEPLOYED=false

deploy_setup() {
  if [[ -z "${SETUP_SCRIPT:-}" ]]; then
    log_debug "No SETUP_SCRIPT configured (skipped)"
    return 0
  fi

  local local_path="$REPO_ROOT/$SETUP_SCRIPT"
  if [[ ! -f "$local_path" ]]; then
    log_warn "SETUP_SCRIPT configured ($SETUP_SCRIPT) but file not found at $local_path — skipped"
    return 0
  fi

  case "$PROJECT_TYPE" in
    fullstack) SETUP_REMOTE_DIR="$(dirname "$DEPLOY_PATH_BACKEND")" ;;
    single|docs) SETUP_REMOTE_DIR="$DEPLOY_PATH" ;;
  esac

  if [[ -z "$SETUP_REMOTE_DIR" || "$SETUP_REMOTE_DIR" == "/" ]]; then
    log_warn "Cannot derive a safe setup remote dir — setup upload skipped"
    return 0
  fi

  local base
  base="$(basename "$SETUP_SCRIPT")"

  log_phase "SETUP SCRIPT"
  log_info "Uploading $SETUP_SCRIPT → $SETUP_REMOTE_DIR/$base (not executed)"

  run_remote "Setup: ensure remote app root" "mkdir -p '$SETUP_REMOTE_DIR'"
  run_cmd "Setup: upload $base" \
    "scp ${SCP_OPTS[*]} '$local_path' '$SSH_USER@$SSH_HOST:$SETUP_REMOTE_DIR/$base'"
  run_remote "Setup: chmod +x $base" "chmod +x '$SETUP_REMOTE_DIR/$base'"

  SETUP_DEPLOYED=true
}

# ============================================================
# Version Check (optional, for all project types)
# ============================================================
# Supports up to two published packages per repo:
#   VERSION_CHECK_PKG/VERSION_CHECK_FILE  -> npm registry (package.json)
#   COMPOSER_CHECK_PKG/COMPOSER_CHECK_FILE -> Packagist (composer.json)
# Local composer version reads the "version" field of composer.json,
# falling back to the nearest git tag when absent. Never blocks deploy.
run_version_check() {
  if [[ -n "${VERSION_CHECK_PKG:-}" && -n "${VERSION_CHECK_FILE:-}" ]]; then
    run_version_check_npm
  fi

  if [[ -n "${COMPOSER_CHECK_PKG:-}" && -n "${COMPOSER_CHECK_FILE:-}" ]]; then
    run_version_check_composer
  fi
}

run_version_check_npm() {
  log_phase "VERSION CHECK (npm)"
  local pkg="$VERSION_CHECK_PKG"
  local file="$VERSION_CHECK_FILE"
  local local_v remote_v

  local_v="$(get_json_version "$file" || true)"
  remote_v="$(timeout 20 npm view "$pkg" version 2>/dev/null || true)"

  report_version_status "npm" "$pkg" "$file" "$local_v" "$remote_v"
}

run_version_check_composer() {
  log_phase "VERSION CHECK (composer)"
  local pkg="$COMPOSER_CHECK_PKG"
  local file="$COMPOSER_CHECK_FILE"
  local local_v remote_v

  local_v="$(get_json_version "$file" || true)"
  if [[ -z "$local_v" && -f "$file" ]]; then
    local_v="$(git -C "$(dirname "$file")" describe --tags --abbrev=0 2>/dev/null || true)"
  fi
  remote_v="$(get_packagist_version "$pkg" || true)"

  report_version_status "Packagist" "$pkg" "$file" "$local_v" "$remote_v"
}

get_json_version() {
  local pkg_file="$1"
  if [[ ! -f "$pkg_file" ]]; then return 1; fi
  python3 -c "import json; print(json.load(open('$pkg_file')).get('version', ''))" 2>/dev/null \
    || grep -oE '"version"\s*:\s*"[^"]+"' "$pkg_file" | head -1 | sed -E 's/.*"([^"]+)".*/\1/'
}

get_packagist_version() {
  local pkg="$1"
  local p2_url="https://repo.packagist.org/p2/${pkg}.json"
  timeout 10 curl -fsSL "$p2_url" 2>/dev/null \
    | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    versions = [p.get('version', '') for p in data.get('packages', {}).get('$pkg', [])]
    if not versions:
        sys.exit(1)
    print(max(versions, key=lambda v: [int(x) for x in v.lstrip('v').split('.') if x.isdigit()] or [0]))
except Exception:
    sys.exit(1)
" 2>/dev/null || true
}

report_version_status() {
  local registry="$1"
  local pkg="$2"
  local file="$3"
  local local_v="$4"
  local remote_v="$5"
  local local_norm remote_norm local_higher

  local_norm="$(echo "$local_v" | sed -E 's/^v//')"
  remote_norm="$(echo "$remote_v" | sed -E 's/^v//')"

  if [[ -z "$local_v" ]]; then
    PKG_CHECK_RESULT="unknown"
    log_warn "Could not read local package version ($file missing?)"
  elif [[ -z "$remote_v" ]]; then
    PKG_CHECK_RESULT="unpublished"
    log_warn "Package '$pkg' has never been published to $registry (or network unreachable)"
  elif [[ "$local_norm" == "$remote_norm" ]]; then
    PKG_CHECK_RESULT="match"
    log_info "Package version in sync with $registry: $local_v"
  else
    local_higher="$(printf '%s\n%s\n' "$local_norm" "$remote_norm" | sort -V | tail -1)"
    if [[ "$local_higher" == "$local_norm" ]]; then
      PKG_CHECK_RESULT="local-newer"
      log_warn "Local package ($local_v) > $registry ($remote_v) — package not yet published"
    else
      PKG_CHECK_RESULT="local-older"
      log_warn "Local package ($local_v) < $registry ($remote_v) — unusual, check manifest"
    fi
  fi

  log_phase "VERSION STATUS"
  echo ""
  case "$PKG_CHECK_RESULT" in
    match) log_info "Package version in sync with $registry: $local_v" ;;
    local-newer)
      echo -e "${YELLOW}============================================================${NC}"
      echo -e "${YELLOW}  REMINDER: package NOT yet published to $registry${NC}"
      echo -e "${YELLOW}  Local:  $local_v${NC}"
      echo -e "${YELLOW}  $registry: $remote_v${NC}"
      echo -e "${YELLOW}============================================================${NC}"
      ;;
    local-older)
      echo -e "${YELLOW}============================================================${NC}"
      echo -e "${YELLOW}  WARNING: local package version is OLDER than $registry${NC}"
      echo -e "${YELLOW}  Local:  $local_v${NC}"
      echo -e "${YELLOW}  $registry: $remote_v${NC}"
      echo -e "${YELLOW}============================================================${NC}"
      ;;
    unpublished)
      echo -e "${RED}============================================================${NC}"
      echo -e "${RED}  WARNING: package has NEVER been published${NC}"
      echo -e "${RED}  Local:  $local_v${NC}"
      echo -e "${RED}  $registry: (not found)${NC}"
      echo -e "${RED}============================================================${NC}"
      ;;
    unknown)
      echo -e "${YELLOW}============================================================${NC}"
      echo -e "${YELLOW}  Could not verify package version against $registry.${NC}"
      echo -e "${YELLOW}============================================================${NC}"
      ;;
  esac
}

# ============================================================
# Health Check Hook (common)
# ============================================================
run_health_check() {
  HOOK_DIR="$REPO_ROOT/scripts/hooks"
  if [[ -f "$HOOK_DIR/health-check.sh" && -x "$HOOK_DIR/health-check.sh" ]]; then
    log_phase "HEALTH CHECK"
    if [[ "$DRY_RUN" == "true" ]]; then
      log_debug "[dry-run] $HOOK_DIR/health-check.sh"
    else
      if "$HOOK_DIR/health-check.sh"; then
        log_info "✓ Health check passed"
      else
        log_err "✗ Health check failed"
        exit 1
      fi
    fi
  else
    log_debug "No health-check.sh hook found (skipped)"
  fi
}

# ============================================================
# Main Flow
# ============================================================
log_phase "DEPLOY TARGETS"
case "$PROJECT_TYPE" in
  fullstack)
    log_info "Backend:  $DEPLOY_PATH_BACKEND"
    log_info "Frontend: $DEPLOY_PATH_FRONTEND"
    ;;
  single)
    log_info "Target:   $DEPLOY_PATH"
    ;;
  docs)
    log_info "Target:   $DEPLOY_PATH (artifact: $ARTIFACT_NAME)"
    ;;
esac
echo ""

run_checks || exit 1

case "$PROJECT_TYPE" in
  fullstack) deploy_fullstack ;;
  single)    deploy_single ;;
  docs)      deploy_docs ;;
esac

deploy_setup

if [[ "$DRY_RUN" == "true" ]]; then
  log_debug "SKIP version check (dry-run)"
else
  run_version_check
fi
run_health_check

# ============================================================
# Done
# ============================================================
log_phase "DEPLOY COMPLETE"
case "$PROJECT_TYPE" in
  fullstack)
    log_info "Backend:  $DEPLOY_BACKEND"
    log_info "Frontend: $DEPLOY_FRONTEND"
    ;;
  single)
    log_info "Mode: single (Laravel SSR)"
    ;;
  docs)
    log_info "Mode: docs only"
    ;;
esac
[[ "$DRY_RUN" == "true" ]] && log_warn "DRY-RUN — no actual changes made"

if [[ "$SETUP_DEPLOYED" == "true" ]]; then
  log_phase "SETUP REMINDER"
  echo -e "${YELLOW}============================================================${NC}"
  echo -e "${YELLOW}  Configure the environment on the server (setup.sh is NOT run automatically):${NC}"
  echo -e "${YELLOW}  ssh -p $SSH_PORT $SSH_USER@$SSH_HOST \"cd $SETUP_REMOTE_DIR && ./$(basename "$SETUP_SCRIPT") --non-interactive\"${NC}"
  echo -e "${YELLOW}  (omit --non-interactive for an interactive run)${NC}"
  echo -e "${YELLOW}============================================================${NC}"
fi