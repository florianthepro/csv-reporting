#!/bin/sh
set -eu

NAME="csv-reporting-ca.crt"
DEFAULT_SOURCE="/var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt"

fail(){ printf 'Fehler: %s\n' "$1" >&2; exit 1; }

find_cert(){
 for f in \
  "$DEFAULT_SOURCE" \
  /root/.local/share/caddy/pki/authorities/local/root.crt \
  /var/lib/caddy/.config/caddy/pki/authorities/local/root.crt
 do
  [ -r "$f" ] && { printf '%s' "$f"; return 0; }
 done
 return 1
}

[ "$(id -u)" -eq 0 ] || fail "Mit sudo ausführen."
SOURCE="$(find_cert || true)"
[ -n "$SOURCE" ] || fail "Caddy-CA nicht gefunden. Zuerst CSV Reporting mit 'HTTPS intern' installieren."

TARGET_USER="${SUDO_USER:-root}"
if [ "$TARGET_USER" = root ]; then
 TARGET_DIR="${PWD:-/tmp}"
else
 TARGET_HOME="$(getent passwd "$TARGET_USER" | cut -d: -f6)"
 [ -n "$TARGET_HOME" ] || fail "Home-Verzeichnis für $TARGET_USER nicht gefunden."
 if [ -d "$TARGET_HOME/Downloads" ]; then TARGET_DIR="$TARGET_HOME/Downloads"; else TARGET_DIR="$TARGET_HOME"; fi
fi
TARGET="$TARGET_DIR/$NAME"

install -m 0644 "$SOURCE" "$TARGET"
[ "$TARGET_USER" = root ] || chown "$TARGET_USER:$TARGET_USER" "$TARGET"

FINGERPRINT="$(openssl x509 -in "$TARGET" -noout -fingerprint -sha256 2>/dev/null | sed 's/^sha256 Fingerprint=//;s/^SHA256 Fingerprint=//' || true)"
SUBJECT="$(openssl x509 -in "$TARGET" -noout -subject 2>/dev/null | sed 's/^subject=//;s/^ *//' || true)"

printf 'Zertifikat: %s\n' "$TARGET"
[ -n "$SUBJECT" ] && printf 'Aussteller:   %s\n' "$SUBJECT"
[ -n "$FINGERPRINT" ] && printf 'SHA-256:      %s\n' "$FINGERPRINT"
printf '\nWindows:\n'
printf '1. Datei auf den Windows-PC kopieren.\n'
printf '2. Datei öffnen.\n'
printf '3. Zertifikat installieren -> Lokaler Computer -> Vertrauenswürdige Stammzertifizierungsstellen.\n'
printf '\nDebian/Ubuntu-Client:\n'
printf 'sudo cp %s /usr/local/share/ca-certificates/%s\n' "$NAME" "$NAME"
printf 'sudo update-ca-certificates\n'
