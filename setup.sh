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
DEFAULT_LAN_PORT="8080"
SETUP_LOG="/tmp/$PROJECT_PATH-setup.log"

ok(){ printf '[%s/10] %-22s OK\n' "$1" "$2"; }
info(){ printf '%-26s %s\n' "$1" "$2"; }
fail(){ printf '\nFehler: %s\nLog: %s\n' "$1" "$SETUP_LOG" >&2; [ -f "$SETUP_LOG" ] && tail -n 20 "$SETUP_LOG" >&2; exit 1; }
prompt(){ printf '%s [%s]: ' "$1" "$2" >/dev/tty; IFS= read -r v </dev/tty || v=""; [ -n "$v" ] && printf '%s' "$v" || printf '%s' "$2"; }
yn(){ v="$(prompt "$1" "$2")"; case "$v" in j|J|ja|Ja|JA|y|Y|yes|Yes|true) return 0;; *) return 1;; esac; }

validate(){
  [ "$(id -u)" -eq 0 ] || fail "Mit sudo oder als root ausführen."
  [ -r /etc/os-release ] || fail "/etc/os-release fehlt."
  . /etc/os-release
  [ "${ID:-}" = "debian" ] || fail "Unterstützt wird Debian."
  printf '%s' "$PROJECT_PATH" | grep -Eq '^[a-z][a-z0-9-]{1,48}$' || fail "PROJECT_PATH ist ungültig."
  : >"$SETUP_LOG"
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

port_busy(){ ss -H -ltn "sport = :$1" 2>/dev/null | grep -q .; }
port_owner(){ ss -H -ltnp "sport = :$1" 2>/dev/null | sed -n '1p' | sed 's/.*users:/users:/' || true; }
private_ip(){
  ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([^ ]*\).*/\1/p' | head -n1
}
free_port(){
  p="$1"; while port_busy "$p"; do p=$((p+1)); [ "$p" -le 65535 ] || fail "Kein freier Port gefunden."; done; printf '%s' "$p"
}

choose_access(){
  APACHE_ACTIVE="false"
  if systemctl is-active --quiet apache2 2>/dev/null || { port_busy 80 && port_owner 80 | grep -qi apache; }; then APACHE_ACTIVE="true"; fi
  if [ "$APACHE_ACTIVE" = "true" ] || port_busy 80 || port_busy 443; then
    printf '\nWebserver-Konflikt\n'
    info "Port 80" "$(port_owner 80)"
    info "Port 443" "$(port_owner 443)"
    printf '[1] Apache behalten, CSV Reporting auf LAN-IP und freiem Port\n[2] Apache stoppen, Caddy auf 80/443 verwenden\n[Q] Beenden\n'
    choice="$(prompt "Auswahl" "1")"
    case "$choice" in
      1) ACCESS_MODE="lan";;
      2) systemctl disable --now apache2 >>"$SETUP_LOG" 2>&1 || fail "Apache konnte nicht gestoppt werden."; ACCESS_MODE="public";;
      q|Q) exit 0;;
      *) fail "Ungültige Auswahl.";;
    esac
  else ACCESS_MODE="public"; fi

  TLS_LINE=""; DJANGO_HTTPS="false"; BIND_LINE=""
  if [ "$ACCESS_MODE" = "lan" ]; then
    LAN_IP="$(private_ip)"; [ -n "$LAN_IP" ] || fail "Keine lokale IPv4-Adresse erkannt."
    LAN_IP="$(prompt "LAN-IP" "$LAN_IP")"
    SUGGESTED_PORT="$(free_port "$DEFAULT_LAN_PORT")"
    LAN_PORT="$(prompt "Port" "$SUGGESTED_PORT")"
    printf '%s' "$LAN_PORT" | grep -Eq '^[0-9]+$' || fail "Port ist ungültig."
    [ "$LAN_PORT" -ge 1024 ] && [ "$LAN_PORT" -le 65535 ] || fail "Port muss zwischen 1024 und 65535 liegen."
    port_busy "$LAN_PORT" && fail "Port $LAN_PORT ist bereits belegt."
    SITE_ADDRESS="http://$LAN_IP:$LAN_PORT"
    BIND_LINE="    bind $LAN_IP"
    PUBLIC_URL="$SITE_ADDRESS${PROJECT_URL_PATH%/}/"
  else
    printf '\n[1] HTTPS automatisch\n[2] HTTPS mit Zertifikatsdateien\n[3] HTTP\n'
    TLS_MODE="$(prompt "Verbindung" "1")"; DOMAIN="$(prompt "Domain oder IP" "localhost")"; SITE_ADDRESS="$DOMAIN"; DJANGO_HTTPS="true"
    case "$TLS_MODE" in
      1) :;;
      2) CERTIFICATE_PATH="$(prompt "Zertifikat" "/etc/ssl/certs/$PROJECT_PATH.pem")"; PRIVATE_KEY_PATH="$(prompt "Privater Schlüssel" "/etc/ssl/private/$PROJECT_PATH.key")"; [ -r "$CERTIFICATE_PATH" ] && [ -r "$PRIVATE_KEY_PATH" ] || fail "Zertifikatsdateien nicht lesbar."; TLS_LINE="    tls $CERTIFICATE_PATH $PRIVATE_KEY_PATH";;
      3) SITE_ADDRESS="http://$DOMAIN"; DJANGO_HTTPS="false";;
      *) fail "Ungültige Auswahl.";;
    esac
    PUBLIC_URL="$SITE_ADDRESS${PROJECT_URL_PATH%/}/"
  fi
  CSV_REMOTE_URL="$(prompt "CSV-Quell-URL" "https://hey.com/csv.csv")"
  if yn "Regel-Editor aktivieren" "nein"; then CSV_EDITOR_ENABLED="true"; else CSV_EDITOR_ENABLED="false"; fi
}

install_system(){
  CADDY_NEW="false"; command -v caddy >/dev/null 2>&1 || CADDY_NEW="true"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq >>"$SETUP_LOG" 2>&1 || fail "Paketlisten konnten nicht geladen werden."
  apt-get install -y -qq --no-install-recommends ca-certificates caddy curl git iproute2 openssl postgresql python3 python3-pip python3-venv >>"$SETUP_LOG" 2>&1 || fail "Systempakete konnten nicht installiert werden."
  ok 1 "System"
}

prepare(){
  id "$SERVICE_USER" >/dev/null 2>&1 || useradd --system --user-group --home-dir "$PROJECT_ROOT" --create-home --shell /usr/sbin/nologin "$SERVICE_USER"
  install -d -o root -g "$SERVICE_GROUP" -m 0750 "$PROJECTS_ROOT_DIRECTORY" "$PROJECT_ROOT" "$APP_DIRECTORY" "$VENV_DIRECTORY" "$LOCAL_CADDY_DIRECTORY"
  install -d -o "$SERVICE_USER" -g "$SERVICE_GROUP" -m 0750 "$DATA_DIRECTORY" "$STATE_DIRECTORY"
  install -d -o root -g "$SERVICE_GROUP" -m 0750 "$CONFIG_DIRECTORY"
  install -d -o root -g root -m 0755 "$CADDY_SITES_DIRECTORY"
  usermod -a -G "$SERVICE_GROUP" caddy
  ok 2 "Verzeichnisse"
}

repository(){
  tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT HUP INT TERM
  git clone -q --branch "$PROJECT_BRANCH" --depth 1 "$PROJECT_REPOSITORY" "$tmp/repository" >>"$SETUP_LOG" 2>&1 || fail "Repository konnte nicht geladen werden."
  for f in backend.py frontend/index.html frontend/style.css frontend/app.js frontend/index.svg frontend/edit.svg; do [ -f "$tmp/repository/$f" ] || fail "$f fehlt im Repository."; done
  if [ -n "$(find "$APP_DIRECTORY" -mindepth 1 -maxdepth 1 -print -quit)" ]; then backup="$STATE_DIRECTORY/app-backup-$(date +%Y%m%d-%H%M%S)"; mkdir -p "$backup"; cp -a "$APP_DIRECTORY/." "$backup/"; fi
  find "$APP_DIRECTORY" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  cp -a "$tmp/repository/." "$APP_DIRECTORY/"; rm -rf "$APP_DIRECTORY/.git"
  chown -R root:"$SERVICE_GROUP" "$APP_DIRECTORY"; chmod -R u=rwX,g=rX,o= "$APP_DIRECTORY"
  rm -rf "$tmp"; trap - EXIT HUP INT TERM
  ok 3 "Projekt"
}

python_env(){
  [ -x "$VENV_DIRECTORY/bin/python" ] || python3 -m venv "$VENV_DIRECTORY"
  "$VENV_DIRECTORY/bin/python" -m pip install -q --upgrade pip >>"$SETUP_LOG" 2>&1 || fail "pip konnte nicht aktualisiert werden."
  "$VENV_DIRECTORY/bin/python" "$APP_DIRECTORY/backend.py" install --django-version "$DJANGO_VERSION" --gunicorn-version "$GUNICORN_VERSION" >>"$SETUP_LOG" 2>&1 || fail "Python-Pakete konnten nicht installiert werden."
  chown -R root:"$SERVICE_GROUP" "$VENV_DIRECTORY"; chmod -R g+rX "$VENV_DIRECTORY"
  ok 4 "Python"
}

database(){
  systemctl enable --now postgresql >>"$SETUP_LOG" 2>&1 || fail "PostgreSQL konnte nicht gestartet werden."
  DB_NAME="$(printf '%s' "$PROJECT_PATH" | tr '-' '_')"; DB_USER="$DB_NAME"; DB_PASSWORD=""
  [ -r "$ENVIRONMENT_FILE" ] && DB_PASSWORD="$(sed -n 's/^DATABASE_PASSWORD=//p' "$ENVIRONMENT_FILE" | head -n1)"
  [ -n "$DB_PASSWORD" ] || DB_PASSWORD="$(openssl rand -hex 32)"
  runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1 || runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "CREATE ROLE \"$DB_USER\" LOGIN PASSWORD '$DB_PASSWORD';" >>"$SETUP_LOG" 2>&1
  runuser -u postgres -- psql -v ON_ERROR_STOP=1 -c "ALTER ROLE \"$DB_USER\" PASSWORD '$DB_PASSWORD';" >>"$SETUP_LOG" 2>&1
  runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1 || runuser -u postgres -- createdb --owner "$DB_USER" "$DB_NAME"
  ok 5 "PostgreSQL"
}

environment(){
  SECRET_KEY=""; [ -r "$ENVIRONMENT_FILE" ] && SECRET_KEY="$(sed -n 's/^DJANGO_SECRET_KEY=//p' "$ENVIRONMENT_FILE" | head -n1)"; [ -n "$SECRET_KEY" ] || SECRET_KEY="$(openssl rand -hex 64)"
  DOMAIN_VALUE="${DOMAIN:-$LAN_IP}"
  cat >"$ENVIRONMENT_FILE" <<EOF
DJANGO_SECRET_KEY=$SECRET_KEY
DJANGO_DEBUG=false
DJANGO_HTTPS=$DJANGO_HTTPS
DJANGO_ALLOWED_HOSTS=$DOMAIN_VALUE
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
  ok 6 "Konfiguration"
}

backend_tasks(){
  runuser -u "$SERVICE_USER" -- sh -c "set -a; . '$ENVIRONMENT_FILE'; set +a; cd '$APP_DIRECTORY'; '$VENV_DIRECTORY/bin/python' backend.py check && '$VENV_DIRECTORY/bin/python' backend.py migrate" >>"$SETUP_LOG" 2>&1 || fail "Django-Prüfung oder Migration fehlgeschlagen."
  ok 7 "Backend"
}

service(){
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
  systemctl daemon-reload; systemctl enable "$PROJECT_PATH.service" >>"$SETUP_LOG" 2>&1
}

caddy(){
  cat >"$CADDY_SITE_FILE" <<EOF
$SITE_ADDRESS {
$BIND_LINE
$TLS_LINE
    encode zstd gzip
    handle_path ${PROJECT_URL_PATH%/}/* {
        handle $DJANGO_API_PREFIX/* { reverse_proxy unix//$SOCKET_PATH }
        handle { root * $APP_DIRECTORY/$FRONTEND_DIRECTORY; try_files {path} /$FRONTEND_FALLBACK_FILE; file_server }
    }
    header { X-Content-Type-Options "nosniff"; Referrer-Policy "strict-origin-when-cross-origin"; -Server }
}
EOF
  cp "$CADDY_SITE_FILE" "$LOCAL_CADDY_DIRECTORY/Caddyfile"
  if [ "$CADDY_NEW" = "true" ]; then cp /etc/caddy/Caddyfile "/etc/caddy/Caddyfile.package-backup" 2>/dev/null || true; printf 'import sites/*.caddy\n' >/etc/caddy/Caddyfile; else grep -Fq 'import sites/*.caddy' /etc/caddy/Caddyfile || printf '\nimport sites/*.caddy\n' >>/etc/caddy/Caddyfile; fi
  caddy validate --config /etc/caddy/Caddyfile >>"$SETUP_LOG" 2>&1 || fail "Caddy-Konfiguration ist ungültig."
  systemctl enable caddy >>"$SETUP_LOG" 2>&1
  ok 8 "Webserver"
}

start_checks(){
  systemctl restart "$PROJECT_PATH.service" >>"$SETUP_LOG" 2>&1 || fail "Backend konnte nicht gestartet werden."
  systemctl restart caddy >>"$SETUP_LOG" 2>&1 || fail "Caddy konnte nicht gestartet werden."
  n=0; while [ "$n" -lt 20 ]; do curl --unix-socket "$SOCKET_PATH" -fsS "http://localhost$DJANGO_API_PREFIX/health" >/dev/null 2>&1 && break; n=$((n+1)); sleep 1; done
  [ "$n" -lt 20 ] || fail "Backend-Health-Check fehlgeschlagen."
  curl -fsS --max-time 5 "$PUBLIC_URL" >/dev/null 2>&1 || fail "Frontend ist über $PUBLIC_URL nicht erreichbar."
  if [ "$ACCESS_MODE" = "lan" ]; then
    ss -H -ltn "sport = :$LAN_PORT" | grep -q "$LAN_IP:$LAN_PORT" || fail "Caddy lauscht nicht auf $LAN_IP:$LAN_PORT."
    ss -H -ltn "sport = :$LAN_PORT" | grep -Eq '(^|[[:space:]])(0\.0\.0\.0|\*):' && fail "Port $LAN_PORT lauscht unerwartet auf allen IPv4-Adressen."
  fi
  ok 9 "Erreichbarkeit"
  runuser -u "$SERVICE_USER" -- sh -c "set -a; . '$ENVIRONMENT_FILE'; set +a; cd '$APP_DIRECTORY'; '$VENV_DIRECTORY/bin/python' backend.py refresh" >>"$SETUP_LOG" 2>&1 || fail "CSV-Quelle oder CSV-Inhalt ist ungültig."
  ok 10 "CSV-Quelle"
  printf '\n'; info "URL" "$PUBLIC_URL"; info "Bindung" "${LAN_IP:-öffentlich}${LAN_PORT:+:$LAN_PORT}"; info "Extern blockiert" "von diesem Server nicht prüfbar"; info "Log" "$SETUP_LOG"
}

main(){ validate; paths; choose_access; install_system; prepare; repository; python_env; database; environment; backend_tasks; service; caddy; start_checks; }
main "$@"
