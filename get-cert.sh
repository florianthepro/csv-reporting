#!/bin/sh
set -eu

fail(){ printf '\nFehler: %s\n' "$1" >&2; exit 1; }
ask(){ printf '%s' "$1" >/dev/tty; IFS= read -r value </dev/tty || value=''; printf '%s' "$value"; }
need(){ command -v "$1" >/dev/null 2>&1 || fail "$1 fehlt. Installieren: apt-get install -y $2"; }
listen(){ ss -H -ltn "sport = :$1" 2>/dev/null | grep -q .; }
free_port(){ p=8765; while listen "$p"; do p=$((p+1)); [ "$p" -le 8999 ] || fail 'Kein freier Port zwischen 8765 und 8999.'; done; printf '%s' "$p"; }
local_ip(){ ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([^ ]*\).*/\1/p' | head -n1; }

[ "$(id -u)" -eq 0 ] || fail 'Mit sudo ausführen.'
need openssl openssl
need python3 python3
need ip iproute2
need ss iproute2

input="$(ask 'HTTPS-Link oder Domain: ')"
[ -n "$input" ] || fail 'Keine Eingabe.'
case "$input" in http://*) fail 'Der Link muss HTTPS verwenden.';; https://*) url="$input";; *) url="https://$input";; esac

parsed="$(python3 - "$url" <<'PY'
import sys
from urllib.parse import urlsplit
u=urlsplit(sys.argv[1])
if u.scheme != 'https' or not u.hostname:
    raise SystemExit(1)
print(u.hostname)
print(u.port or 443)
PY
)" || fail 'Link oder Domain ungültig.'
host="$(printf '%s\n' "$parsed" | sed -n '1p')"
tls_port="$(printf '%s\n' "$parsed" | sed -n '2p')"

case "$host" in *[!A-Za-z0-9._:-]*|'') fail 'Domain enthält ungültige Zeichen.';; esac
case "$tls_port" in *[!0-9]*|'') fail 'Port ungültig.';; esac

connect_host="$host"
if getent ahostsv4 "$host" >/dev/null 2>&1; then
    while IFS= read -r candidate; do
        case "$candidate" in 127.*|10.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[01].*) connect_host="$candidate"; break;; esac
    done <<EOF
$(getent ahostsv4 "$host" | awk '{print $1}' | sort -u)
EOF
fi

work="$(mktemp -d /tmp/get-cert.XXXXXX)"
cleanup(){ rm -rf "$work"; }
trap cleanup EXIT HUP INT TERM

printf 'Zertifikat abrufen: %s:%s (SNI: %s)\n' "$connect_host" "$tls_port" "$host"
openssl s_client -connect "$connect_host:$tls_port" -servername "$host" -showcerts </dev/null 2>"$work/openssl.log" |
awk '
/-----BEGIN CERTIFICATE-----/{n++; out=sprintf("cert-%02d.pem",n); writing=1}
writing{print > (dir "/" out)}
/-----END CERTIFICATE-----/{writing=0}
' dir="$work" || true

[ -s "$work/cert-01.pem" ] || { cat "$work/openssl.log" >&2; fail 'Kein Zertifikat vom lokalen HTTPS-Dienst erhalten.'; }

cp "$work/cert-01.pem" "$work/server-certificate.pem"
: >"$work/certificate-chain.pem"
i=1
while [ -s "$(printf "$work/cert-%02d.pem" "$i")" ]; do
    cat "$(printf "$work/cert-%02d.pem" "$i")" >>"$work/certificate-chain.pem"
    i=$((i+1))
done

openssl x509 -in "$work/server-certificate.pem" -outform DER 2>/dev/null | openssl dgst -sha256 -r | awk '{print $1}' >"$work/fingerprint"
subject="$(openssl x509 -in "$work/server-certificate.pem" -noout -subject 2>/dev/null | sed 's/^subject=//')"
issuer="$(openssl x509 -in "$work/server-certificate.pem" -noout -issuer 2>/dev/null | sed 's/^issuer=//')"
expiry="$(openssl x509 -in "$work/server-certificate.pem" -noout -enddate 2>/dev/null | sed 's/^notAfter=//')"

found=''
for root in /etc/letsencrypt/live /etc/nginx /etc/apache2 /etc/ssl /usr/local/share/ca-certificates /var/lib/caddy/.local/share/caddy; do
    [ -d "$root" ] || continue
    for file in $(find "$root" -type f \( -name '*.pem' -o -name '*.crt' -o -name '*.cer' \) 2>/dev/null); do
        local_fp="$(openssl x509 -in "$file" -outform DER 2>/dev/null | openssl dgst -sha256 -r 2>/dev/null | awk '{print $1}')"
        if [ -n "$local_fp" ] && [ "$local_fp" = "$(cat "$work/fingerprint")" ]; then found="$file"; break 2; fi
    done
done

cat >"$work/README.txt" <<EOF
CSV Reporting certificate export

Domain: $host
TLS port: $tls_port
Subject: $subject
Issuer: $issuer
Expires: $expiry
SHA-256: $(cat "$work/fingerprint")
Local certificate: ${found:-not found as a separate file}

Files:
- server-certificate.pem: certificate presented for the domain
- certificate-chain.pem: complete chain presented by the HTTPS service

No private key is included.
EOF

ip="$(local_ip)"
[ -n "$ip" ] || fail 'Keine lokale IPv4-Adresse erkannt.'
port="$(free_port)"
token="$(openssl rand -hex 18)"
serve="$work/share/$token"
mkdir -p "$serve"
cp "$work/server-certificate.pem" "$work/certificate-chain.pem" "$work/README.txt" "$serve/"

printf '\nGefunden\nDomain: %s\nBetreff: %s\nAussteller: %s\nAblauf: %s\n' "$host" "$subject" "$issuer" "$expiry"
[ -n "$found" ] && printf 'Lokale Datei: %s\n' "$found" || printf 'Lokale Datei: nicht eindeutig gefunden; Zertifikat wurde direkt vom lokalen HTTPS-Dienst gelesen.\n'
printf '\nDownload: http://%s:%s/%s/\n' "$ip" "$port" "$token"
printf 'Enthalten: Serverzertifikat, Zertifikatskette, Informationen\n'
printf 'Kein privater Schlüssel. Ende automatisch nach 10 Minuten oder Strg+C.\n\n'

cd "$work/share"
exec python3 - "$ip" "$port" <<'PY'
import http.server, socketserver, sys, threading
host=sys.argv[1]
port=int(sys.argv[2])
class Handler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, fmt, *args):
        print('Download:', args[0])
class Server(socketserver.TCPServer):
    allow_reuse_address=True
with Server((host,port),Handler) as server:
    timer=threading.Timer(600,server.shutdown)
    timer.daemon=True
    timer.start()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        timer.cancel()
PY
