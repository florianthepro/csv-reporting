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
SETUP_VERSION="10"

step(){ printf '[%s/10] %-18s %s\n' "$1" "$2" "$3"; }
fail(){ printf '\nFehler: %s\nLog: %s\n' "$1" "$LOG" >&2; tail -n 15 "$LOG" 2>/dev/null >&2 || true; exit 1; }
ask(){ printf '%s [%s]: ' "$1" "$2" >/dev/tty; IFS= read -r v </dev/tty || v=""; [ -n "$v" ] && printf '%s' "$v" || printf '%s' "$2"; }
listens(){ ss -H -ltn "sport = :$1" 2>/dev/null | grep -q .; }
process_on(){ ss -H -ltnp "sport = :$1" 2>/dev/null | grep -oE '\(\"[^\"]+' | head -n1 | tr -d '(\"' || true; }
port_ours(){ [ -f "$SITE" ] && grep -Eq "(^|[^0-9]):$1([^0-9]|$)" "$SITE"; }
port_available(){ ! listens "$1" || port_ours "$1"; }
free_port(){ p="$1"; while ! port_available "$p"; do p=$((p+1)); [ "$p" -le 65535 ] || fail "Kein freier Port gefunden."; done; printf '%s' "$p"; }
port_list(){ start="$1"; end="$2"; out=""; p="$start"; while [ "$p" -le "$end" ]; do if port_available "$p"; then if port_ours "$p"; then mark="$p (aktuell)"; else mark="$p"; fi; out="${out}${out:+, }$mark"; fi; p=$((p+1)); done; [ -n "$out" ] && printf '%s' "$out" || printf 'keiner'; }
choose_port(){ default="$1"; first="$2"; last="$3"; while :; do printf 'Verfügbar %s-%s: %s
' "$first" "$last" "$(port_list "$first" "$last")" >/dev/tty; chosen="$(ask "Port" "$(free_port "$default")")"; case "$chosen" in ''|*[!0-9]*) printf 'Bitte Port 1-65535 eingeben.
' >/dev/tty; continue;; esac; if [ "$chosen" -lt 1 ] || [ "$chosen" -gt 65535 ]; then printf 'Bitte Port 1-65535 eingeben.
' >/dev/tty; continue; fi; if port_available "$chosen"; then printf '%s' "$chosen"; return; fi; owner="$(process_on "$chosen")"; [ -n "$owner" ] || owner="anderem Dienst"; printf 'Port %s ist durch %s belegt. Bitte anderen Port wählen.
' "$chosen" "$owner" >/dev/tty; done; }
lan_ip(){ ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([^ ]*\).*/\1/p' | head -n1; }

init(){
 [ "$(id -u)" -eq 0 ] || fail "Mit sudo ausführen."
 . /etc/os-release; [ "${ID:-}" = debian ] || fail "Nur Debian wird unterstützt."
 : >"$LOG"
 APP="$PROJECT_ROOT/app"; VENV="$PROJECT_ROOT/venv"; DATA="/var/lib/$PROJECT_PATH"; STATE="$DATA/state"
 ENV="/etc/$PROJECT_PATH/environment"; UNIT="/etc/systemd/system/$PROJECT_PATH.service"
 SITES="/etc/caddy/sites"; SITE="$SITES/$PROJECT_PATH.caddy"; MASTER="/etc/caddy/Caddyfile"
 SOCK="/run/$PROJECT_PATH/$PROJECT_PATH.sock"; USER="$PROJECT_PATH"
}

questions(){
 printf 'CSV Reporting Setup v%s\n' "$SETUP_VERSION"
 EXISTING_ACTION=install
 if [ -d "$PROJECT_ROOT" ] && [ "$(find "$PROJECT_ROOT" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
  printf '
Vorhandene Installation
%s
' "$PROJECT_ROOT"
  printf '[1] Sicher aktualisieren: Vollsicherung, App und Python neu, Daten zurück
[2] Neu aufbauen: Vollsicherung, App und Python neu, Daten zurück
[Q] Beenden
'
  EXISTING_ACTION="$(ask "Auswahl" "1")"
  case "$EXISTING_ACTION" in 1) EXISTING_ACTION=upgrade;; 2) EXISTING_ACTION=rebuild;; q|Q) exit 0;; *) fail "Ungültige Auswahl.";; esac
 fi
 if listens 80 || listens 443; then
  P80="$(process_on 80)"; P443="$(process_on 443)"; [ -n "$P80" ] || P80=belegt; [ -n "$P443" ] || P443=belegt
  printf '\nWebserver belegt\n80:  %s\n443: %s\n' "$P80" "$P443"
  printf '[1] Behalten, separater LAN-Port\n[2] Belegenden Webserver stoppen, Caddy auf 80/443\n[Q] Beenden\n'
  MODE="$(ask "Auswahl" "1")"
 else MODE=2; fi
 case "$MODE" in
  1)
   IP="$(ask "LAN-IP" "$(lan_ip)")"; [ -n "$IP" ] || fail "Keine LAN-IP erkannt."
   printf '[1] HTTPS intern\n[2] HTTPS mit Zertifikat\n[3] HTTP\n'
   TLS_MODE="$(ask "Verbindung" "1")"
   case "$TLS_MODE" in
    1) SCHEME=https; PORT="$(choose_port "$DEFAULT_HTTPS_PORT" 8443 8453)"; TLS_LINE='    tls internal';;
    2) SCHEME=https; PORT="$(choose_port "$DEFAULT_HTTPS_PORT" 8443 8453)"; CERT="$(ask "Zertifikat" "/etc/ssl/certs/$PROJECT_PATH.pem")"; KEY="$(ask "Schlüssel" "/etc/ssl/private/$PROJECT_PATH.key")"; [ -r "$CERT" ] && [ -r "$KEY" ] || fail "Zertifikatsdateien nicht lesbar."; TLS_LINE="    tls $CERT $KEY";;
    3) SCHEME=http; PORT="$(choose_port "$DEFAULT_HTTP_PORT" 8080 8090)"; TLS_LINE='';;
    *) fail "Ungültige Auswahl.";;
   esac
   ADDRESS="$SCHEME://$IP:$PORT"; BIND_LINE="    bind $IP"; ALLOWED="$IP,localhost,127.0.0.1"; HTTPS=$([ "$SCHEME" = https ] && echo true || echo false); DISABLE_REDIRECTS=true
   ;;
  2)
   for SERVICE in apache2 nginx lighttpd; do if systemctl is-active --quiet "$SERVICE" 2>/dev/null; then systemctl disable --now "$SERVICE" >>"$LOG" 2>&1 || fail "$SERVICE konnte nicht gestoppt werden."; fi; done
   listens 80 && fail "Port 80 bleibt durch einen unbekannten Dienst belegt."
   listens 443 && fail "Port 443 bleibt durch einen unbekannten Dienst belegt."
   printf '[1] HTTPS automatisch\n[2] HTTPS mit Zertifikat\n[3] HTTP\n'
   TLS_MODE="$(ask "Verbindung" "1")"; IP="$(ask "Domain oder IP" "localhost")"; BIND_LINE=''; ALLOWED="$IP,localhost,127.0.0.1"; DISABLE_REDIRECTS=false
   case "$TLS_MODE" in
    1) ADDRESS="$IP"; TLS_LINE=''; HTTPS=true;;
    2) CERT="$(ask "Zertifikat" "/etc/ssl/certs/$PROJECT_PATH.pem")"; KEY="$(ask "Schlüssel" "/etc/ssl/private/$PROJECT_PATH.key")"; [ -r "$CERT" ] && [ -r "$KEY" ] || fail "Zertifikatsdateien nicht lesbar."; ADDRESS="$IP"; TLS_LINE="    tls $CERT $KEY"; HTTPS=true;;
    3) ADDRESS="http://$IP"; TLS_LINE=''; HTTPS=false;;
    *) fail "Ungültige Auswahl.";;
   esac
   ;;
  q|Q) exit 0;; *) fail "Ungültige Auswahl.";;
 esac
 OLD_CSV=""; OLD_EDITOR=""; [ ! -r "$ENV" ] || { OLD_CSV="$(sed -n 's/^CSV_REMOTE_URL=//p' "$ENV" | head -n1)"; OLD_EDITOR="$(sed -n 's/^CSV_EDITOR_ENABLED=//p' "$ENV" | head -n1)"; }
 [ -n "$OLD_CSV" ] || OLD_CSV="https://hey.com/csv.csv"; [ -n "$OLD_EDITOR" ] || OLD_EDITOR=false
 CSV_URL="$(ask "CSV-Quell-URL" "$OLD_CSV")"
 EDITOR="$(ask "Regel-Editor (true/false)" "$OLD_EDITOR")"
 URL="$ADDRESS${PROJECT_URL_PATH%/}/"
}

backup_existing(){
 [ "$EXISTING_ACTION" != install ] || return 0
 stamp="$(date +%Y%m%d-%H%M%S)"; BACKUP_ROOT="/var/backups/$PROJECT_PATH/$stamp"; install -d -m 0700 "$BACKUP_ROOT"
 [ ! -d "$APP" ] || cp -a "$APP" "$BACKUP_ROOT/app"
 [ ! -d "$VENV" ] || cp -a "$VENV" "$BACKUP_ROOT/venv"
 [ ! -d "$STATE" ] || cp -a "$STATE" "$BACKUP_ROOT/state"
 [ ! -f "$ENV" ] || cp -a "$ENV" "$BACKUP_ROOT/environment"
 [ ! -f "$SITE" ] || cp -a "$SITE" "$BACKUP_ROOT/csv-reporting.caddy"
 [ ! -f "$UNIT" ] || cp -a "$UNIT" "$BACKUP_ROOT/csv-reporting.service"
 printf 'Sicherung: %s
' "$BACKUP_ROOT"
 systemctl stop "$PROJECT_PATH" 2>/dev/null || true
 rm -rf "$APP" "$VENV"
}
restore_data(){
 [ -n "${BACKUP_ROOT:-}" ] || return 0
 if [ ! -d "$STATE" ] && [ -d "$BACKUP_ROOT/state" ]; then install -d -o "$USER" -g "$USER" -m 0750 "$DATA"; cp -a "$BACKUP_ROOT/state" "$STATE"; fi
 if [ ! -f "$ENV" ] && [ -f "$BACKUP_ROOT/environment" ]; then install -d -m 0750 "$(dirname "$ENV")"; cp -a "$BACKUP_ROOT/environment" "$ENV"; fi
 chown -R "$USER:$USER" "$STATE" 2>/dev/null || true
}

packages(){ export DEBIAN_FRONTEND=noninteractive; apt-get update -qq >>"$LOG" 2>&1 && apt-get install -y -qq --no-install-recommends ca-certificates caddy curl git iproute2 openssl postgresql python3 python3-pip python3-venv >>"$LOG" 2>&1 || fail "Paketinstallation fehlgeschlagen."; step 1 System OK; }

directories(){
 id "$USER" >/dev/null 2>&1 || useradd --system --user-group --home-dir "$PROJECT_ROOT" --create-home --shell /usr/sbin/nologin "$USER"
 install -d -o root -g "$USER" -m 0750 "$PROJECT_ROOT" "$APP" "$VENV" "$PROJECT_ROOT/caddy"
 install -d -o "$USER" -g "$USER" -m 0750 "$DATA" "$STATE"
 install -d -o root -g "$USER" -m 0750 "$(dirname "$ENV")"; install -d -o root -g root -m 0755 /etc/caddy "$SITES"; usermod -aG "$USER" caddy
 step 2 Verzeichnisse OK
}

repository(){
 T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT HUP INT TERM
 git clone -q --depth 1 --branch "$PROJECT_BRANCH" "$PROJECT_REPOSITORY" "$T/repo" >>"$LOG" 2>&1 || fail "Repository nicht erreichbar."
 for f in backend.py frontend/index.html frontend/style.css frontend/app.js frontend/index.svg frontend/edit.svg; do [ -f "$T/repo/$f" ] || fail "$f fehlt."; done
 [ ! -e "$APP/backend.py" ] || { B="$STATE/backup-$(date +%Y%m%d-%H%M%S)"; mkdir -p "$B"; cp -a "$APP/." "$B/"; }
 find "$APP" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; cp -a "$T/repo/." "$APP/"; rm -rf "$APP/.git"
 chown -R root:"$USER" "$APP"; chmod -R u=rwX,g=rX,o= "$APP"; rm -rf "$T"; trap - EXIT HUP INT TERM; step 3 Projekt OK
}

python_env(){
 [ -x "$VENV/bin/python" ] || python3 -m venv "$VENV"
 "$VENV/bin/python" -m pip install -q --upgrade pip >>"$LOG" 2>&1 || fail "pip fehlgeschlagen."
 "$VENV/bin/python" "$APP/backend.py" install --django-version "$DJANGO_VERSION" --gunicorn-version "$GUNICORN_VERSION" >>"$LOG" 2>&1 || fail "Python-Pakete fehlgeschlagen."
 chown -R root:"$USER" "$VENV"; chmod -R g+rX "$VENV"; step 4 Python OK
}

database(){
 systemctl enable --now postgresql >>"$LOG" 2>&1 || fail "PostgreSQL startet nicht."
 DB="$(printf %s "$PROJECT_PATH" | tr - _)"; PASS=""; [ -r "$ENV" ] && PASS="$(sed -n 's/^DATABASE_PASSWORD=//p' "$ENV" | head -n1)"; [ -n "$PASS" ] || PASS="$(openssl rand -hex 32)"
 runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB'" | grep -q 1 || runuser -u postgres -- psql -c "CREATE ROLE \"$DB\" LOGIN PASSWORD '$PASS';" >>"$LOG" 2>&1
 runuser -u postgres -- psql -c "ALTER ROLE \"$DB\" PASSWORD '$PASS';" >>"$LOG" 2>&1
 runuser -u postgres -- psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB'" | grep -q 1 || runuser -u postgres -- createdb --owner "$DB" "$DB"
 step 5 PostgreSQL OK
}

configuration(){
 SECRET=""; [ -r "$ENV" ] && SECRET="$(sed -n 's/^DJANGO_SECRET_KEY=//p' "$ENV" | head -n1)"; [ -n "$SECRET" ] || SECRET="$(openssl rand -hex 64)"
 cat >"$ENV" <<EOF
DJANGO_SECRET_KEY=$SECRET
DJANGO_DEBUG=false
DJANGO_HTTPS=$HTTPS
DJANGO_ALLOWED_HOSTS=$ALLOWED
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
 chown root:"$USER" "$ENV"; chmod 0640 "$ENV"; step 6 Konfiguration OK
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
ReadWritePaths=$DATA /run/$PROJECT_PATH
[Install]
WantedBy=multi-user.target
EOF
 systemctl daemon-reload; systemctl enable "$PROJECT_PATH" >>"$LOG" 2>&1; step 7 Backend OK
}

web(){
 TMP="$(mktemp)"
 cat >"$TMP" <<EOF
$ADDRESS {
$BIND_LINE
$TLS_LINE
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
        Cache-Control "no-store"
        -Server
    }
}
EOF
 cp "$TMP" "$SITE.new"; rm -f "$TMP"
 [ -f "$MASTER" ] || : >"$MASTER"
 chown root:root "$MASTER"
 chmod 0644 "$MASTER"
 find "$SITES" -maxdepth 1 -type f -name '*.caddy' -exec chown root:root {} + -exec chmod 0644 {} +
 cp "$MASTER" "$MASTER.before-$PROJECT_PATH"
 if [ "$DISABLE_REDIRECTS" = true ]; then
  awk 'BEGIN{skip=0} /^\{/{skip=1} skip && /^\}/{skip=0;next} !skip{print}' "$MASTER.before-$PROJECT_PATH" >"$MASTER.body"
  { printf '{\n    auto_https disable_redirects\n}\n\n'; cat "$MASTER.body"; } >"$MASTER"
  rm -f "$MASTER.body"
 fi
 grep -Fq 'import sites/*.caddy' "$MASTER" || printf '\nimport sites/*.caddy\n' >>"$MASTER"
 [ ! -f "$SITE" ] || cp "$SITE" "$SITE.before"
 mv "$SITE.new" "$SITE"
 chown root:root "$SITE"
 chmod 0644 "$SITE"
 caddy fmt --overwrite "$MASTER" >>"$LOG" 2>&1 || true
 caddy fmt --overwrite "$SITE" >>"$LOG" 2>&1 || true
 if ! caddy validate --config "$MASTER" >>"$LOG" 2>&1; then cp "$MASTER.before-$PROJECT_PATH" "$MASTER"; [ ! -f "$SITE.before" ] || mv "$SITE.before" "$SITE"; fail "Caddy-Konfiguration ungültig."; fi
 cp "$SITE" "$PROJECT_ROOT/caddy/Caddyfile"; chown root:"$USER" "$PROJECT_ROOT/caddy/Caddyfile"; chmod 0640 "$PROJECT_ROOT/caddy/Caddyfile"; systemctl enable caddy >>"$LOG" 2>&1; step 8 Webserver OK
}

verify(){
 systemctl restart "$PROJECT_PATH" >>"$LOG" 2>&1 || fail "Backend startet nicht."
 if ! systemctl restart caddy >>"$LOG" 2>&1; then journalctl -u caddy -n 30 --no-pager >>"$LOG" 2>&1 || true; fail "Caddy startet nicht."; fi
 n=0; until curl -fsS --unix-socket "$SOCK" -H 'Host: localhost' http://localhost/api/health >/dev/null 2>&1; do n=$((n+1)); [ "$n" -lt 20 ] || fail "Backend-Health-Check fehlgeschlagen."; sleep 1; done
 step 9 Erreichbarkeit OK
 runuser -u "$USER" -- sh -c "set -a; . '$ENV'; set +a; cd '$APP'; '$VENV/bin/python' backend.py refresh" >>"$LOG" 2>&1 || fail "CSV-Abruf oder CSV-Format fehlerhaft."
 if [ "$HTTPS" = true ]; then curl -kfsS --max-time 10 "$URL" >/dev/null 2>&1 || fail "HTTPS-Frontend nicht erreichbar."; else curl -fsS --max-time 10 "$URL" >/dev/null 2>&1 || fail "Frontend nicht erreichbar."; fi
 step 10 CSV OK; printf '\nURL: %s\n' "$URL"; [ "$MODE" = 1 ] && [ "$TLS_MODE" = 1 ] && printf 'CA:  /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt\n'; printf 'Log: %s\n' "$LOG"
}

main(){ init; questions; backup_existing; packages; directories; restore_data; repository; python_env; database; configuration; backend; web; verify; }
main "$@"
