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
SETUP_VERSION="17"

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
auto_port(){ first="$1"; last="$2"; count=$((last-first+1)); seed=$(($(date +%s)+$$)); offset=$((seed%count)); i=0; while [ "$i" -lt "$count" ]; do port=$((first+(offset+i)%count)); if port_available "$port"; then printf '%s' "$port"; return 0; fi; i=$((i+1)); done; fail "Kein freier Port im Bereich $first-$last."; }
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

installed(){ [ -f "$ENV" ] || [ -f "$UNIT" ] || [ -d "$APP" ]; }
confirm_word(){ printf '%s
Zum Bestätigen %s eingeben: ' "$1" "$2" >/dev/tty; IFS= read -r answer </dev/tty || answer=""; [ "$answer" = "$2" ]; }
main_menu(){
 if ! installed; then ACTION=install; return; fi
 printf '
CSV Reporting
[1] Aktualisieren
[2] Neu einrichten
[3] Löschen
[4] Zertifikat bereitstellen
[Q] Beenden
'
 choice="$(ask "Auswahl" "1")"
 case "$choice" in 1) ACTION=update;; 2) ACTION=reset;; 3) ACTION=delete;; 4) ACTION=certificate;; q|Q) exit 0;; *) fail "Ungültige Auswahl.";; esac
}
backup_all(){
 stamp="$(date +%Y%m%d-%H%M%S)"; BACKUP_ROOT="/var/backups/$PROJECT_PATH/$stamp"; install -d -m 0700 "$BACKUP_ROOT"
 [ ! -d "$APP" ] || cp -a "$APP" "$BACKUP_ROOT/app"
 [ ! -d "$VENV" ] || cp -a "$VENV" "$BACKUP_ROOT/venv"
 [ ! -d "$STATE" ] || cp -a "$STATE" "$BACKUP_ROOT/state"
 [ ! -f "$ENV" ] || cp -a "$ENV" "$BACKUP_ROOT/environment"
 [ ! -f "$SITE" ] || cp -a "$SITE" "$BACKUP_ROOT/csv-reporting.caddy"
 [ ! -f "$UNIT" ] || cp -a "$UNIT" "$BACKUP_ROOT/csv-reporting.service"
 printf 'Sicherung: %s
' "$BACKUP_ROOT"
}
load_existing(){
 [ -r "$ENV" ] || fail "Bestehende Konfiguration fehlt: $ENV"
 CSV_URL="$(sed -n 's/^CSV_REMOTE_URL=//p' "$ENV" | head -n1)"
 EDITOR="$(sed -n 's/^CSV_EDITOR_ENABLED=//p' "$ENV" | head -n1)"
 HTTPS="$(sed -n 's/^DJANGO_HTTPS=//p' "$ENV" | head -n1)"
 ALLOWED="$(sed -n 's/^DJANGO_ALLOWED_HOSTS=//p' "$ENV" | head -n1)"
 [ -n "$CSV_URL" ] || fail "CSV_REMOTE_URL fehlt."
 [ -n "$EDITOR" ] || EDITOR=false
}
update_existing(){
 printf '
Update
Konfiguration, Regeln, Statusdaten, Datenbank und Webadresse bleiben unverändert.
'
 load_existing; backup_all
 systemctl stop "$PROJECT_PATH" 2>/dev/null || true
 rm -rf "$APP" "$VENV"
 packages; directories; repository; python_env; database
 backend
 systemctl restart "$PROJECT_PATH" >>"$LOG" 2>&1 || fail "Backend startet nicht."
 n=0; until curl -fsS --unix-socket "$SOCK" -H 'Host: localhost' http://localhost/api/health >/dev/null 2>&1; do n=$((n+1)); [ "$n" -lt 20 ] || fail "Backend-Health-Check fehlgeschlagen."; sleep 1; done
 runuser -u "$USER" -- sh -c "set -a; . '$ENV'; set +a; cd '$APP'; '$VENV/bin/python' backend.py refresh" >>"$LOG" 2>&1 || fail "CSV-Prüfung fehlgeschlagen."
 systemctl reload caddy >>"$LOG" 2>&1 || systemctl restart caddy >>"$LOG" 2>&1 || fail "Caddy konnte nicht geladen werden."
 printf '
Update abgeschlossen
Daten: unverändert
Webserver: unverändert
Log: %s
' "$LOG"
}
reset_existing(){
 confirm_word 'Reset erstellt zuerst eine Sicherung und löscht danach Konfiguration, Regeln, Cache und Datenbank.' RESET || { printf 'Abgebrochen.
'; exit 0; }
 backup_all
 systemctl disable --now "$PROJECT_PATH" >/dev/null 2>&1 || true
 rm -rf "$APP" "$VENV" "$DATA" "$ENV" "$UNIT" "$SITE" "$PROJECT_ROOT/caddy"
 systemctl daemon-reload
 DB="$(printf %s "$PROJECT_PATH" | tr - _)"
 runuser -u postgres -- dropdb --if-exists "$DB" >>"$LOG" 2>&1 || true
 runuser -u postgres -- psql -c "DROP ROLE IF EXISTS "$DB";" >>"$LOG" 2>&1 || true
 install_questions; packages; directories; repository; python_env; database; configuration; backend; web; verify
}
delete_existing(){
 confirm_word 'Delete entfernt Dienst, App, Konfiguration, Regeln, Cache und Datenbank. Vorher wird gesichert.' DELETE || { printf 'Abgebrochen.
'; exit 0; }
 backup_all
 systemctl disable --now "$PROJECT_PATH" >/dev/null 2>&1 || true
 rm -f "$UNIT" "$SITE"; rm -rf "$PROJECT_ROOT" "$DATA" "$(dirname "$ENV")"
 systemctl daemon-reload
 DB="$(printf %s "$PROJECT_PATH" | tr - _)"
 runuser -u postgres -- dropdb --if-exists "$DB" >>"$LOG" 2>&1 || true
 runuser -u postgres -- psql -c "DROP ROLE IF EXISTS "$DB";" >>"$LOG" 2>&1 || true
 systemctl reload caddy >/dev/null 2>&1 || systemctl restart caddy >/dev/null 2>&1 || true
 id "$USER" >/dev/null 2>&1 && userdel "$USER" >/dev/null 2>&1 || true
 printf '
CSV Reporting gelöscht.
Sicherung: %s
' "$BACKUP_ROOT"
}

install_questions(){
 EXISTING_ACTION=install
 if listens 80 || listens 443; then
  P80="$(process_on 80)"; P443="$(process_on 443)"; [ -n "$P80" ] || P80=belegt; [ -n "$P443" ] || P443=belegt
  printf '\nWebserver belegt\n80:  %s\n443: %s\n' "$P80" "$P443"
  printf '[1] Behalten, separater LAN-Port\n[2] Belegenden Webserver stoppen, Caddy auf 80/443\n[Q] Beenden\n'
  MODE="$(ask "Auswahl" "1")"
 else MODE=2; fi
 case "$MODE" in
  1)
   IP="$(ask "LAN-IP" "$(lan_ip)")"; [ -n "$IP" ] || fail "Keine LAN-IP erkannt."
   TLS_MODE=1; SCHEME=https; PORT="$(auto_port 8443 8453)"; TLS_LINE='    tls internal'
   printf 'HTTPS intern: %s:%s\n' "$IP" "$PORT"
   ADDRESS="$SCHEME://$IP:$PORT"; BIND_LINE="    bind $IP"; ALLOWED="$IP,localhost,127.0.0.1"; HTTPS=true; DISABLE_REDIRECTS=true
   ;;
  2)
   for SERVICE in apache2 nginx lighttpd; do if systemctl is-active --quiet "$SERVICE" 2>/dev/null; then systemctl disable --now "$SERVICE" >>"$LOG" 2>&1 || fail "$SERVICE konnte nicht gestoppt werden."; fi; done
   listens 80 && fail "Port 80 bleibt durch einen unbekannten Dienst belegt."
   listens 443 && fail "Port 443 bleibt durch einen unbekannten Dienst belegt."
   TLS_MODE=1; IP="$(ask "Domain oder IP" "$(lan_ip)")"; BIND_LINE=''; ALLOWED="$IP,localhost,127.0.0.1"; DISABLE_REDIRECTS=false
   ADDRESS="https://$IP"; TLS_LINE='    tls internal'; HTTPS=true
   printf 'HTTPS intern: %s:443\n' "$IP"
   ;;
  q|Q) exit 0;; *) fail "Ungültige Auswahl.";;
 esac
 while :; do
  CSV_URL="$(ask "CSV-Quell-URL" "https://example.invalid/report.csv")"
  case "$CSV_URL" in http://*|https://*) break;; *) printf 'Bitte vollständige URL mit http:// oder https:// eingeben.
' >/dev/tty;; esac
 done
 EDITOR=true
 URL="$ADDRESS${PROJECT_URL_PATH%/}/"
}

packages(){ export DEBIAN_FRONTEND=noninteractive; apt-get update -qq >>"$LOG" 2>&1 && apt-get install -y -qq --no-install-recommends ca-certificates caddy curl git iproute2 openssl postgresql python3 python3-pip python3-venv >>"$LOG" 2>&1 || fail "Paketinstallation fehlgeschlagen."; step 1 System OK; }

directories(){
 if ! id "$USER" >/dev/null 2>&1; then
  if getent group "$USER" >/dev/null 2>&1; then useradd --system -g "$USER" --home-dir "$PROJECT_ROOT" --create-home --shell /usr/sbin/nologin "$USER"
  else useradd --system --user-group --home-dir "$PROJECT_ROOT" --create-home --shell /usr/sbin/nologin "$USER"; fi
 fi
 install -d -o root -g "$USER" -m 0750 "$PROJECT_ROOT" "$APP" "$VENV" "$PROJECT_ROOT/caddy"
 install -d -o "$USER" -g "$USER" -m 0750 "$DATA" "$STATE"
 install -d -o root -g "$USER" -m 0750 "$(dirname "$ENV")"; install -d -o root -g root -m 0755 /etc/caddy "$SITES"; usermod -aG "$USER" caddy
 step 2 Verzeichnisse OK
}

repository(){
 T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT HUP INT TERM
 git clone -q --depth 1 --branch "$PROJECT_BRANCH" "$PROJECT_REPOSITORY" "$T/repo" >>"$LOG" 2>&1 || fail "Repository nicht erreichbar."
 for f in backend.py get-cert.sh frontend/index.html frontend/style.css frontend/app.js frontend/index.svg frontend/edit.svg; do [ -f "$T/repo/$f" ] || fail "$f fehlt."; done
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
 if [ -n "$CSV_URL" ]; then runuser -u "$USER" -- sh -c "set -a; . '$ENV'; set +a; cd '$APP'; '$VENV/bin/python' backend.py refresh" >>"$LOG" 2>&1 || fail "CSV-Abruf oder CSV-Format fehlerhaft."; fi
 CA_SOURCE="/var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt"; if [ -r "$CA_SOURCE" ]; then cp "$CA_SOURCE" "$STATE/csv-reporting-ca.crt"; chown "$USER:$USER" "$STATE/csv-reporting-ca.crt"; chmod 0644 "$STATE/csv-reporting-ca.crt"; fi
 curl -kfsS --max-time 10 "$URL" >/dev/null 2>&1 || fail "HTTPS-Frontend nicht erreichbar."
 if [ -n "$CSV_URL" ]; then step 10 CSV OK; else step 10 Einrichtung WEBGUI; fi; printf '\nURL: %s\n' "$URL"; [ "$MODE" = 1 ] && [ "$TLS_MODE" = 1 ] && printf 'CA:  /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt\n'; printf 'Log: %s\n' "$LOG"
}

main(){
 init
 printf 'CSV Reporting Setup v%s
' "$SETUP_VERSION"
 main_menu
 case "$ACTION" in
  install) install_questions; packages; directories; repository; python_env; database; configuration; backend; web; verify;;
  update) update_existing;;
  reset) reset_existing;;
  delete) delete_existing;;
  certificate) [ -x "$APP/get-cert.sh" ] || fail "get-cert.sh fehlt. Zuerst aktualisieren."; exec "$APP/get-cert.sh";;
 esac
}
main "$@"
