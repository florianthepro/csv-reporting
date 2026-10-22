#!/bin/sh
set -eu

CA_FILE="/var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt"
SITE_FILE="/etc/caddy/sites/csv-reporting.caddy"
TMP=""
PID=""
cleanup(){ [ -z "$PID" ] || kill "$PID" 2>/dev/null || true; [ -z "$TMP" ] || rm -rf "$TMP"; }
trap cleanup EXIT HUP INT TERM
ask(){ printf '%s [%s]: ' "$1" "$2" >/dev/tty; IFS= read -r value </dev/tty || value=""; [ -n "$value" ] && printf '%s' "$value" || printf '%s' "$2"; }
listen(){ ss -H -ltn "sport = :$1" 2>/dev/null | grep -q .; }
free_port(){ port="$1"; while listen "$port"; do port=$((port+1)); [ "$port" -le 65535 ] || exit 1; done; printf '%s' "$port"; }
lan_ip(){ ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([^ ]*\).*/\1/p' | head -n1; }
default_target(){
 if [ -r "$SITE_FILE" ]; then
  sed -n 's/^[[:space:]]*https:\/\/\([^ {]*\).*/https:\/\/\1/p' "$SITE_FILE" | head -n1
 fi
}

[ "$(id -u)" -eq 0 ] || { printf 'Mit sudo ausführen.\n' >&2; exit 1; }
[ -r "$CA_FILE" ] || { printf 'Caddy-CA nicht gefunden: %s\n' "$CA_FILE" >&2; exit 1; }
command -v openssl >/dev/null 2>&1 || { printf 'openssl fehlt.\n' >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { printf 'python3 fehlt.\n' >&2; exit 1; }

DEFAULT="$(default_target)"
[ -n "$DEFAULT" ] || DEFAULT="https://$(lan_ip):8443"
printf 'CSV Reporting Zertifikat-Download\n\n'
TARGET="$(ask "HTTPS-Adresse der Anwendung" "$DEFAULT")"
case "$TARGET" in https://*) ;; *) TARGET="https://$TARGET";; esac

AUTHORITY="${TARGET#https://}"; AUTHORITY="${AUTHORITY%%/*}"
case "$AUTHORITY" in
 \[*\]:*) HOST="${AUTHORITY%%]*}"; HOST="${HOST#[}"; PORT="${AUTHORITY##*:}";;
 \[*\]) HOST="${AUTHORITY#[}"; HOST="${HOST%]}"; PORT=443;;
 *:*) HOST="${AUTHORITY%:*}"; PORT="${AUTHORITY##*:}";;
 *) HOST="$AUTHORITY"; PORT=443;;
esac
[ -n "$HOST" ] || { printf 'Adresse ungültig.\n' >&2; exit 1; }
case "$PORT" in ''|*[!0-9]*) printf 'Port ungültig.\n' >&2; exit 1;; esac

TMP="$(mktemp -d)"
cp "$CA_FILE" "$TMP/csv-reporting-ca.crt"
chmod 0644 "$TMP/csv-reporting-ca.crt"

if ! openssl s_client -connect "$HOST:$PORT" -servername "$HOST" -showcerts </dev/null 2>"$TMP/tls-error" | openssl x509 -outform PEM >"$TMP/csv-reporting-server.crt" 2>/dev/null; then
 printf 'Serverzertifikat konnte nicht von %s:%s gelesen werden.\n' "$HOST" "$PORT" >&2
 cat "$TMP/tls-error" >&2
 exit 1
fi
chmod 0644 "$TMP/csv-reporting-server.crt"

SUBJECT="$(openssl x509 -in "$TMP/csv-reporting-server.crt" -noout -subject | sed 's/^subject=//')"
ISSUER="$(openssl x509 -in "$TMP/csv-reporting-server.crt" -noout -issuer | sed 's/^issuer=//')"
SAN="$(openssl x509 -in "$TMP/csv-reporting-server.crt" -noout -ext subjectAltName 2>/dev/null | tail -n +2 | tr -d '\n' | sed 's/^[[:space:]]*//')"
FINGERPRINT="$(openssl x509 -in "$TMP/csv-reporting-ca.crt" -noout -fingerprint -sha256 | cut -d= -f2-)"

cat >"$TMP/index.html" <<EOF
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CSV Reporting Zertifikate</title><style>body{font:16px Arial,sans-serif;max-width:760px;margin:40px auto;padding:0 18px;line-height:1.45}a{display:block;margin:12px 0;padding:12px;border:1px solid #ccc;border-radius:5px}code{overflow-wrap:anywhere}</style></head><body><h1>CSV Reporting Zertifikate</h1><p>Ziel: <code>$TARGET</code></p><a href="csv-reporting-ca.crt" download>CA-Zertifikat herunterladen und als vertrauenswürdige Stammzertifizierungsstelle installieren</a><a href="csv-reporting-server.crt" download>Serverzertifikat herunterladen, nur zur Prüfung</a><p>Nach dem Import des CA-Zertifikats den Browser vollständig neu starten.</p></body></html>
EOF

IP="$(lan_ip)"; [ -n "$IP" ] || { printf 'Keine LAN-IP erkannt.\n' >&2; exit 1; }
DOWNLOAD_PORT="$(free_port 8099)"

printf '\nGeprüfte HTTPS-Adresse: %s\n' "$TARGET"
printf 'Serverzertifikat:\n  Subject: %s\n  Issuer:  %s\n  SAN:     %s\n' "$SUBJECT" "$ISSUER" "$SAN"
printf '\nZu installieren ist die ausstellende CA, nicht das wechselnde Serverzertifikat.\n'
printf 'CA SHA-256: %s\n' "$FINGERPRINT"
printf '\nTemporärer Downloadserver:\n  http://%s:%s/\n' "$IP" "$DOWNLOAD_PORT"
printf 'Direkt: http://%s:%s/csv-reporting-ca.crt\n' "$IP" "$DOWNLOAD_PORT"
printf '\nNur im lokalen Netz erreichbar. Beenden mit Enter oder Strg+C.\n'

cd "$TMP"
python3 -m http.server "$DOWNLOAD_PORT" --bind "$IP" >/dev/null 2>&1 &
PID=$!
sleep 1
kill -0 "$PID" 2>/dev/null || { printf 'Downloadserver konnte nicht gestartet werden.\n' >&2; exit 1; }
IFS= read -r _ </dev/tty || true
