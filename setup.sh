#!/bin/sh
set -eu

PROJECT_NAME="CSV Reporting"
PROJECT_PATH="csv-reporting"
PROJECT_REPOSITORY="https://github.com/florianthepro/csv-reporting.git"
PROJECT_BRANCH="main"
PROJECTS_ROOT_DIRECTORY="/srv/webapps"
PROJECT_URL_PATH="/csv-reporting"

DJANGO_VERSION="5.2.*"
GUNICORN_VERSION="23.*"
GUNICORN_WORKERS="3"
GUNICORN_TIMEOUT_SECONDS="30"
DJANGO_API_PREFIX="/api"
FRONTEND_DIRECTORY="frontend"
FRONTEND_FALLBACK_FILE="index.html"
CADDY_SITES_DIRECTORY="/etc/caddy/sites"

log(){ printf '\n[%s] %s\n' "$PROJECT_PATH" "$1"; }
fail(){ printf '\n[%s] Fehler: %s\n' "$PROJECT_PATH" "$1" >&2; exit 1; }
prompt(){ printf '%s [%s]: ' "$1" "$2" >/dev/tty; IFS= read -r value </dev/tty || value=""; [ -n "$value" ] && printf '%s' "$value" || printf '%s' "$2"; }

validate(){
  [ "$(id -u)" -eq 0 ] || fail "Mit sudo oder als root ausführen."
  . /etc/os-release
  [ "${ID:-}" = "debian" ] || fail "Unterstützt wird Debian."
  printf '%s' "$PROJECT_PATH" | grep -Eq '^[a-z][a-z0-9-]{1,48}$' || fail "PROJECT_PATH ist ungültig."
}

paths(){
  PROJECT_ROOT="$PROJECTS_ROOT_DIRECTORY/$PROJECT_PATH"
  APP_DIRECTORY="$PROJECT_ROOT/app"
  VENV_DIRECTORY="$PROJECT_ROOT/venv"
  LOCAL_CADDY_DIRECTORY="$PROJECT_ROOT/caddy"
  DATA_DIRECTORY="/var/lib/$PROJECT_PATH"
  STATE_DIRECTORY="$DATA_DIRECTORY/state"
  CONFIG_DIRECTORY="/etc/$PROJECT_PATH"
  ENVIRONMENT_FILE="$CONFIG_DIRECTORY/environment"
  SERVICE_FILE="/etc/systemd/system/$PROJECT_PATH.service"
  CADDY_SITE_FILE="$CADDY_SITES_DIRECTORY/$PROJECT_PATH.caddy"
  SOCKET_DIRECTORY="/run/$PROJECT_PATH"
  SOCKET_PATH="$SOCKET_DIRECTORY/$PROJECT_PATH.sock"
  SERVICE_USER="$PROJECT_PATH"
  SERVICE_GROUP="$PROJECT_PATH"
}

access(){
  printf '\n[1] HTTPS automatisch\n[2] HTTPS mit Zertifikatsdateien\n[3] HTTP\n' >/dev/tty
  TLS_MODE="${TLS_MODE:-$(prompt "Verbindung" "1")}"; DOMAIN="${DOMAIN:-$(prompt "Domain oder IP" "localhost")}"; SITE_ADDRESS="$DOMAIN"; TLS_LINE=""; DJANGO_HTTPS="true"
  case "$TLS_MODE" in
    1) : ;;
    2) CERTIFICATE_PATH="${CERTIFICATE_PATH:-$(prompt "Zertifikat" "/etc/ssl/certs/$PROJECT_PATH.pem")}"; PRIVATE_KEY_PATH="${PRIVATE_KEY_PATH:-$(prompt "Privater Schlüssel" "/etc/ssl/private/$PROJECT_PATH.key")}"; [ -r "$CERTIFICATE_PATH" ] && [ -r "$PRIVATE_KEY_PATH" ] || fail "Zertifikatsdateien nicht lesbar."; TLS_LINE="    tls $CERTIFICATE_PATH $PRIVATE_KEY_PATH" ;;
    3) SITE_ADDRESS="http://$DOMAIN"; DJANGO_HTTPS="false" ;;
    *) fail "Ungültige Auswahl." ;;
  esac
  CSV_REMOTE_URL="${CSV_REMOTE_URL:-$(prompt "CSV-Quell-URL" "https://hey.com/csv.csv")}" 
  CSV_EDITOR_ENABLED="${CSV_EDITOR_ENABLED:-$(prompt "Regel-Editor aktivieren: true/false" "false")}" 
}

install_system(){
  log "Systempakete installieren"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y --no-install-recommends ca-certificates caddy curl git openssl postgresql python3 python3-pip python3-venv
}

prepare(){
  log "Benutzer und Verzeichnisse vorbereiten"
  id "$SERVICE_USER" >/dev/null 2>&1 || useradd --system --user-group --home-dir "$PROJECT_ROOT" --create-home --shell /usr/sbin/nologin "$SERVICE_USER"
  install -d -o root -g "$SERVICE_GROUP" -m 0750 "$PROJECTS_ROOT_DIRECTORY" "$PROJECT_ROOT" "$APP_DIRECTORY" "$VENV_DIRECTORY" "$LOCAL_CADDY_DIRECTORY"
  install -d -o "$SERVICE_USER" -g "$SERVICE_GROUP" -m 0750 "$DATA_DIRECTORY" "$STATE_DIRECTORY"
  install -d -o root -g "$SERVICE_GROUP" -m 0750 "$CONFIG_DIRECTORY"
  install -d -o root -g root -m 0755 "$CADDY_SITES_DIRECTORY"
  usermod -a -G "$SERVICE_GROUP" caddy
}

repository(){
  log "Repository laden"
  tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT HUP INT TERM
  git clone --branch "$PROJECT_BRANCH" --depth 1 "$PROJECT_REPOSITORY" "$tmp/repository"
  [ -f "$tmp/repository/backend.py" ] || fail "backend.py fehlt."
  [ -f "$tmp/repository/index.svg" ] || fail "index.svg fehlt im Repository-Root."
  [ -f "$tmp/repository/edit.svg" ] || fail "edit.svg fehlt im Repository-Root."
  if [ -n "$(find "$APP_DIRECTORY" -mindepth 1 -maxdepth 1 -print -quit)" ]; then backup="$STATE_DIRECTORY/app-backup-$(date +%Y%m%d-%H%M%S)"; mkdir -p "$backup"; cp -a "$APP_DIRECTORY/." "$backup/"; log "Sicherung: $backup"; fi
  find "$APP_DIRECTORY" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  cp -a "$tmp/repository/." "$APP_DIRECTORY/"; rm -rf "$APP_DIRECTORY/.git"
  chown -R root:"$SERVICE_GROUP" "$APP_DIRECTORY"; chmod -R u=rwX,g=rX,o= "$APP_DIRECTORY"
  rm -rf "$tmp"; trap - EXIT HUP INT TERM
}

python_env(){
  log "Python-Umgebung vorbereiten"
  [ -x "$VENV_DIRECTORY/bin/python" ] || python3 -m venv "$VENV_DIRECTORY"
  "$VENV_DIRECTORY/bin/python" -m pip install --upgrade pip
  "$VENV_DIRECTORY/bin/python" "$APP_DIRECTORY/backend.py" install --django-version "$DJANGO_VERSION" --gunicorn-version "$GUNICORN_VERSION"
  chown -R root:"$SERVICE_GROUP" "$VENV_DIRECTORY"; chmod -R g+rX "$VENV_DIRECTORY"
}

database(){
  log "PostgreSQL vorbereiten"
  DB_NAME="$(printf '%s' "$PROJECT_PATH" | tr '-' '_')"; DB_USER="$DB_NAME"; DB_PASSWORD=""
  [ -r "$ENVIRONMENT_FILE" ] && DB_PASSWORD="$(sed -n 's/^DATABASE_PASSWORD=//p' "$ENVIRONMENT_FILE" | head -n1)"
  [ -n "$DB_PASSWORD" ] || DB_PASSWORD="$(openssl rand -hex 32)"
  runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1 || runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "CREATE ROLE \"$DB_USER\" LOGIN PASSWORD '$DB_PASSWORD';"
  runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "ALTER ROLE \"$DB_USER\" PASSWORD '$DB_PASSWORD';"
  runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1 || runuser -u postgres -- createdb --owner "$DB_USER" "$DB_NAME"
}

environment(){
  log "Produktionswerte schreiben"
  SECRET_KEY=""; [ -r "$ENVIRONMENT_FILE" ] && SECRET_KEY="$(sed -n 's/^DJANGO_SECRET_KEY=//p' "$ENVIRONMENT_FILE" | head -n1)"; [ -n "$SECRET_KEY" ] || SECRET_KEY="$(openssl rand -hex 64)"
  cat >"$ENVIRONMENT_FILE" <<EOF
DJANGO_SECRET_KEY=$SECRET_KEY
DJANGO_DEBUG=false
DJANGO_HTTPS=$DJANGO_HTTPS
DJANGO_ALLOWED_HOSTS=$DOMAIN
DJANGO_API_PREFIX=$DJANGO_API_PREFIX
DATABASE_NAME=$DB_NAME
DATABASE_USER=$DB_USER
DATABASE_PASSWORD=$DB_PASSWORD
DATABASE_HOST=127.0.0.1
DATABASE_PORT=5432
STATE_DIRECTORY=$STATE_DIRECTORY
CSV_REMOTE_URL=$CSV_REMOTE_URL
CSV_EDITOR_ENABLED=$CSV_EDITOR_ENABLED
CSV_MAX_DOWNLOAD_BYTES=10485760
EOF
  chown root:"$SERVICE_GROUP" "$ENVIRONMENT_FILE"; chmod 0640 "$ENVIRONMENT_FILE"
}

backend_tasks(){
  log "Django prüfen und migrieren"
  runuser -u "$SERVICE_USER" -- sh -c "set -a; . '$ENVIRONMENT_FILE'; set +a; cd '$APP_DIRECTORY'; '$VENV_DIRECTORY/bin/python' backend.py check"
  runuser -u "$SERVICE_USER" -- sh -c "set -a; . '$ENVIRONMENT_FILE'; set +a; cd '$APP_DIRECTORY'; '$VENV_DIRECTORY/bin/python' backend.py migrate"
}

service(){
  log "systemd-Dienst schreiben"
  cat >"$SERVICE_FILE" <<EOF
[Unit]
Description=$PROJECT_NAME
After=network.target postgresql.service
Wants=postgresql.service
[Service]
User=$SERVICE_USER
Group=$SERVICE_GROUP
WorkingDirectory=$APP_DIRECTORY
EnvironmentFile=$ENVIRONMENT_FILE
RuntimeDirectory=$PROJECT_PATH
RuntimeDirectoryMode=0750
ExecStart=$VENV_DIRECTORY/bin/gunicorn --bind unix:$SOCKET_PATH --umask 007 --workers $GUNICORN_WORKERS --timeout $GUNICORN_TIMEOUT_SECONDS backend:application
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$DATA_DIRECTORY $SOCKET_DIRECTORY
[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload; systemctl enable "$PROJECT_PATH.service"
}

caddy(){
  log "Caddy-Site schreiben"
  cat >"$CADDY_SITE_FILE" <<EOF
$SITE_ADDRESS {
$TLS_LINE
    encode zstd gzip
    handle_path ${PROJECT_URL_PATH%/}/* {
        handle $DJANGO_API_PREFIX/* {
            reverse_proxy unix//$SOCKET_PATH
        }
        handle /index.svg {
            root * $APP_DIRECTORY
            file_server
        }
        handle /edit.svg {
            root * $APP_DIRECTORY
            file_server
        }
        handle {
            root * $APP_DIRECTORY/$FRONTEND_DIRECTORY
            try_files {path} /$FRONTEND_FALLBACK_FILE
            file_server
        }
    }
    header {
        X-Content-Type-Options "nosniff"
        Referrer-Policy "strict-origin-when-cross-origin"
        -Server
    }
}
EOF
  cp "$CADDY_SITE_FILE" "$LOCAL_CADDY_DIRECTORY/Caddyfile"
  grep -Fq 'import sites/*.caddy' /etc/caddy/Caddyfile || printf '\nimport sites/*.caddy\n' >>/etc/caddy/Caddyfile
  caddy validate --config /etc/caddy/Caddyfile; systemctl enable caddy
}

start(){
  log "Dienste starten"; systemctl restart "$PROJECT_PATH.service"; systemctl reload caddy || systemctl restart caddy
  n=0; while [ "$n" -lt 20 ]; do runuser -u "$SERVICE_USER" -- curl --unix-socket "$SOCKET_PATH" -fsS "http://localhost$DJANGO_API_PREFIX/health" >/dev/null 2>&1 && { printf '\nInstallation abgeschlossen: %s\n' "$PROJECT_ROOT"; return; }; n=$((n+1)); sleep 1; done
  systemctl status "$PROJECT_PATH.service" --no-pager || true; fail "Health Check fehlgeschlagen."
}

main(){ validate; paths; access; install_system; prepare; repository; python_env; database; environment; backend_tasks; service; caddy; start; }
main "$@"
