#!/bin/sh
set -eu

PROJECT_NAME="CSV Reporting"
PROJECT_PATH="csv-reporting"
PROJECT_REPOSITORY="https://github.com/florianthepro/csv-reporting.git"
PROJECT_BRANCH="main"
PROJECT_ROOT="/srv/webapps/$PROJECT_PATH"
PROJECT_URL_PATH="/csv-reporting"
DJANGO_VERSION="5.2.*"
GUNICORN_VERSION="23.*"
DEFAULT_HTTP_PORT="8080"
DEFAULT_HTTPS_PORT="8443"
LOG="/tmp/$PROJECT_PATH-setup.log"

say(){ printf '%s\n' "$1"; }
ok(){ printf '[%s/10] %-20s OK\n' "$1" "$2"; }
fail(){ printf '\nFehler: %s\nLog: %s\n' "$1" "$LOG" >&2; tail -n 20 "$LOG" 2>/dev/null >&2 || true; exit 1; }
ask(){ printf '%s [%s]: ' "$1" "$2" >/dev/tty; IFS= read -r x </dev/tty || x=""; [ -n "$x" ] && printf '%s' "$x" || printf '%s' "$2"; }
listens(){ ss -H -ltn "sport = :$1" 2>/dev/null | grep -q .; }
owner(){ ss -H -ltnp "sport = :$1" 2>/dev/null | sed -n '1p' | sed 's/.*users:/users:/' || true; }
free_port(){ p="$1"; while listens "$p"; do p=$((p+1)); [ "$p" -le 65535 ] || fail "Kein freier Port."; done; printf '%s' "$p"; }
lan_ip(){ ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([^ ]*\).*/\1/p' | head -n1; }

preflight(){
  [ "$(id -u)" -eq 0 ] || fail "Mit sudo ausführen."
  . /etc/os-release; [ "${ID:-}" = debian ] || fail "Nur Debian wird unterstützt."
  command -v ss >/dev/null 2>&1 || apt-get install -y -qq iproute2 >/dev/null 2>&1
  APP="$PROJECT_ROOT/app"; VENV="$PROJECT_ROOT/venv"; DATA="/var/lib/$PROJECT_PATH"; STATE="$DATA/state"
  ENV="/etc/$PROJECT_PATH/environment"; UNIT="/etc/systemd/system/$PROJECT_PATH.service"
  SITES="/etc/caddy/sites"; SITE="$SITES/$PROJECT_PATH.caddy"; LOCAL_CADDY="$PROJECT_ROOT/caddy/Caddyfile"
  SOCKDIR="/run/$PROJECT_PATH"; SOCK="$SOCKDIR/$PROJECT_PATH.sock"; USER="$PROJECT_PATH"
  : >"$LOG"
}

choose_listener(){
  if listens 80 || listens 443; then
    say ""; say "Webserver belegt"; say "80:  $(owner 80)"; say "443: $(owner 443)"
    say "[1] Bestehenden Webserver behalten"; say "[2] Apache stoppen, Caddy übernimmt 80/443"; say "[Q] Beenden"
    mode="$(ask "Auswahl" "1")"
  else mode=2; fi
  case "$mode" in
    1)
      IP="$(ask "LAN-IP" "$(lan_ip)")"; [ -n "$IP" ] || fail "Keine LAN-IP."
      say "[1] HTTPS intern (self-signed)"; say "[2] HTTPS mit Zertifikat"; say "[3] HTTP"
      tls="$(ask "Verbindung" "1")"
      case "$tls" in
        1) PORT="$(ask "Port" "$(free_port "$DEFAULT_HTTPS_PORT")")"; SCHEME=https; TLS="    tls internal";;
        2) PORT="$(ask "Port" "$(free_port "$DEFAULT_HTTPS_PORT")")"; CERT="$(ask "Zertifikat" "/etc/ssl/certs/$PROJECT_PATH.pem")"; KEY="$(ask "Schlüssel" "/etc/ssl/private/$PROJECT_PATH.key")"; [ -r "$CERT" ] && [ -r "$KEY" ] || fail "Zertifikat oder Schlüssel nicht lesbar."; SCHEME=https; TLS="    tls $CERT $KEY";;
        3) PORT="$(ask "Port" "$(free_port "$DEFAULT_HTTP_PORT")")"; SCHEME=http; TLS="";;
        *) fail "Ungültige Auswahl.";;
      esac
      printf '%s' "$PORT" | grep -Eq '^[0-9]+$' || fail "Ungültiger Port."
      listens "$PORT" && fail "Port $PORT ist belegt."
      ADDRESS="$SCHEME://$IP:$PORT"; BIND="    bind $IP"; HOSTS="$IP,localhost,127.0.0.1"; HTTPS=$([ "$SCHEME" = https ] && echo true || echo false)
      ;;
    2)
      if systemctl is-active --quiet apache2 2>/dev/null; then systemctl disable --now apache2 >>"$LOG" 2>&1 || fail "Apache konnte nicht gestoppt werden."; fi
      say "[1] HTTPS automatisch"; say "[2] HTTPS mit Zertifikat"; say "[3] HTTP"
      tls="$(ask "Verbindung" "1")"; IP="$(ask "Domain oder IP" "localhost")"; BIND=""; HOSTS="$IP,localhost,127.0.0.1"
      case "$tls" in
        1) ADDRESS="$IP"; TLS=""; HTTPS=true;;
        2) CERT="$(ask "Zertifikat" "/etc/ssl/certs/$PROJECT_PATH.pem")"; KEY="$(ask "Schlüssel" "/etc/ssl/private/$PROJECT_PATH.key")"; [ -r "$CERT" ] && [ -r "$KEY" ] || fail "Zertifikat oder Schlüssel nicht lesbar."; ADDRESS="$IP"; TLS="    tls $CERT $KEY"; HTTPS=true;;
        3) ADDRESS="http://$IP"; TLS=""; HTTPS=false;;
        *) fail "Ungültige Auswahl.";;
      esac
      ;;
    q|Q) exit 0;; *) fail "Ungültige Auswahl.";;
  esac
  CSV_URL="$(ask "CSV-Quell-URL" "https://hey.com/csv.csv")"
  EDITOR="$(ask "Regel-Editor (true/false)" "false")"
  URL="$ADDRESS${PROJECT_URL_PATH%/}/"
}

packages(){
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq >>"$LOG" 2>&1 || fail "apt update fehlgeschlagen."
  apt-get install -y -qq --no-install-recommends ca-certificates caddy curl git iproute2 openssl postgresql python3 python3-pip python3-venv >>"$LOG" 2>&1 || fail "Paketinstallation fehlgeschlagen."
  ok 1 System
}

dirs(){
  id "$USER" >/dev/null 2>&1 || useradd --system --user-group --home-dir "$PROJECT_ROOT" --create-home --shell /usr/sbin/nologin "$USER"
  install -d -o root -g "$USER" -m 0750 "$PROJECT_ROOT" "$APP" "$VENV" "$(dirname "$LOCAL_CADDY")"
  install -d -o "$USER" -g "$USER" -m 0750 "$DATA" "$STATE"
  install -d -o root -g "$USER" -m 0750 "$(dirname "$ENV")"
  install -d -m 0755 "$SITES"; usermod -a -G "$USER" caddy
  ok 2 Verzeichnisse
}

repo(){
  T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT HUP INT TERM
  git clone -q --depth 1 --branch "$PROJECT_BRANCH" "$PROJECT_REPOSITORY" "$T/repo" >>"$LOG" 2>&1 || fail "Repository nicht erreichbar."
  for f in backend.py frontend/index.html frontend/style.css frontend/app.js frontend/index.svg frontend/edit.svg; do [ -f "$T/repo/$f" ] || fail "$f fehlt."; done
  [ ! -e "$APP/backend.py" ] || { B="$STATE/backup-$(date +%Y%m%d-%H%M%S)"; mkdir -p "$B"; cp -a "$APP/." "$B/"; }
  find "$APP" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; cp -a "$T/repo/." "$APP/"; rm -rf "$APP/.git"
  chown -R root:"$USER" "$APP"; chmod -R u=rwX,g=rX,o= "$APP"; rm -rf "$T"; trap - EXIT HUP INT TERM
  ok 3 Projekt
}

python_env(){
  [ -x "$VENV/bin/python" ] || python3 -m venv "$VENV"
  "$VENV/bin/python" -m pip install -q --upgrade pip >>"$LOG" 2>&1
  "$VENV/bin/python" "$APP/backend.py" install --django-version "$DJANGO_VERSION" --gunicorn-version "$GUNICORN_VERSION" >>"$LOG" 2>&1 || fail "Python-Installation fehlgeschlagen."
  chown -R root:"$USER" "$VENV"; chmod -R g+rX "$VENV"; ok 4 Python
}

db(){
  systemctl enable --now postgresql >>"$LOG" 2>&1 || fail "PostgreSQL startet nicht."
  DB="$(printf %s "$PROJECT_PATH" | tr - _)"; PASS=""; [ -r "$ENV" ] && PASS="$(sed -n 's/^DATABASE_PASSWORD=//p' "$ENV" | head -n1)"; [ -n "$PASS" ] || PASS="$(openssl rand -hex 32)"
  runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB'" | grep -q 1 || runuser -u postgres -- psql -c "CREATE ROLE \"$DB\" LOGIN PASSWORD '$PASS';" >>"$LOG" 2>&1
  runuser -u postgres -- psql -c "ALTER ROLE \"$DB\" PASSWORD '$PASS';" >>"$LOG" 2>&1
  runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB'" | grep -q 1 || runuser -u postgres -- createdb --owner "$DB" "$DB"
  ok 5 PostgreSQL
}

envfile(){
  SECRET=""; [ -r "$ENV" ] && SECRET="$(sed -n 's/^DJANGO_SECRET_KEY=//p' "$ENV" | head -n1)"; [ -n "$SECRET" ] || SECRET="$(openssl rand -hex 64)"
  cat >"$ENV" <<EOF
DJANGO_SECRET_KEY=$SECRET
DJANGO_DEBUG=false
DJANGO_HTTPS=$HTTPS
DJANGO_ALLOWED_HOSTS=$HOSTS
DJANGO_API_PREFIX=/api
DATABASE_NAME=$DB
DATABASE_USER=$DB
DATABASE_PASSWORD=$PASS
DATABASE_HOST=127.0.0.1
DATABASE_PORT=5432
STATE_DIRECTORY=$STATE
CSV_REMOTE_URL=$CSV_URL
CSV_EDITOR_ENABLED=$EDITOR
CSV_MAX_DOWNLOAD_BYTES=10485760
EOF
  chown root:"$USER" "$ENV"; chmod 0640 "$ENV"; ok 6 Konfiguration
}

backend(){
  runuser -u "$USER" -- sh -c "set -a; . '$ENV'; set +a; cd '$APP'; '$VENV/bin/python' backend.py check && '$VENV/bin/python' backend.py migrate" >>"$LOG" 2>&1 || fail "Backend-Prüfung fehlgeschlagen."
  cat >"$UNIT" <<EOF
[Unit]
Description=$PROJECT_NAME
After=network.target postgresql.service
[Service]
User=$USER
Group=$USER
WorkingDirectory=$APP
EnvironmentFile=$ENV
RuntimeDirectory=$PROJECT_PATH
RuntimeDirectoryMode=0750
ExecStart=$VENV/bin/gunicorn --bind unix:$SOCK --umask 007 --workers 3 --timeout 30 backend:application
Restart=on-failure
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$DATA $SOCKDIR
[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload; systemctl enable "$PROJECT_PATH" >>"$LOG" 2>&1; ok 7 Backend
}

web(){
  TMP_SITE="$(mktemp)"
  cat >"$TMP_SITE" <<EOF
$ADDRESS {
$BIND
$TLS
    encode zstd gzip
    handle_path ${PROJECT_URL_PATH%/}/* {
        handle /api/* {
            reverse_proxy unix//$SOCK
        }
        handle {
            root * $APP/frontend
            try_files {path} /index.html
            file_server
        }
    }
    header {
        X-Content-Type-Options nosniff
        Referrer-Policy strict-origin-when-cross-origin
        -Server
    }
}
EOF
  cp "$TMP_SITE" "$SITE.new"; rm -f "$TMP_SITE"
  [ -f /etc/caddy/Caddyfile ] || printf 'import sites/*.caddy\n' >/etc/caddy/Caddyfile
  grep -Fq 'import sites/*.caddy' /etc/caddy/Caddyfile || printf '\nimport sites/*.caddy\n' >>/etc/caddy/Caddyfile
  [ ! -f "$SITE" ] || cp "$SITE" "$SITE.backup"
  mv "$SITE.new" "$SITE"
  caddy validate --config /etc/caddy/Caddyfile >>"$LOG" 2>&1 || { [ ! -f "$SITE.backup" ] || mv "$SITE.backup" "$SITE"; fail "Caddy-Konfiguration ungültig."; }
  cp "$SITE" "$LOCAL_CADDY"; systemctl enable caddy >>"$LOG" 2>&1; ok 8 Webserver
}

checks(){
  systemctl restart "$PROJECT_PATH" >>"$LOG" 2>&1 || fail "Backend startet nicht."
  systemctl restart caddy >>"$LOG" 2>&1 || fail "Caddy startet nicht."
  n=0; until curl -fsS --unix-socket "$SOCK" -H 'Host: localhost' http://localhost/api/health >/dev/null 2>&1; do n=$((n+1)); [ "$n" -lt 20 ] || fail "Backend-Health-Check fehlgeschlagen."; sleep 1; done
  ok 9 Erreichbarkeit
  runuser -u "$USER" -- sh -c "set -a; . '$ENV'; set +a; cd '$APP'; '$VENV/bin/python' backend.py refresh" >>"$LOG" 2>&1 || fail "CSV-Abruf oder CSV-Format fehlerhaft."
  if [ "$SCHEME" = https ]; then curl -kfsS --max-time 8 "$URL" >/dev/null 2>&1 || fail "HTTPS-Frontend nicht erreichbar."; else curl -fsS --max-time 8 "$URL" >/dev/null 2>&1 || fail "Frontend nicht erreichbar."; fi
  if [ -n "${PORT:-}" ]; then ss -H -ltn "sport = :$PORT" | grep -q "$IP:$PORT" || fail "Falsche Portbindung."; fi
  ok 10 CSV; say ""; say "URL: $URL"; [ "${tls:-}" = 1 ] && [ "${mode:-}" = 1 ] && say "CA:  /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt" || true; say "Log: $LOG"
}

main(){ preflight; choose_listener; packages; dirs; repo; python_env; db; envfile; backend; web; checks; }
main "$@"
