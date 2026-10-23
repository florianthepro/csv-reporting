#!/bin/sh
set -eu
SHLIB=${FTPKIT_SHLIB:-/usr/local/lib/ftpkit-shlib/ftpkit.sh}
PLAN=${FTPKIT_CONFIRM_PLAN:-}
[ -r "$SHLIB" ] || { printf '%s\n' "ftpkit-shlib is required" >&2; exit 1; }
. "$SHLIB"
ftpkit_init
ftpkit_backend_status >/dev/null 2>&1 || { printf '%s\n' "Backend manager is not ready; bootstrap or repair it first" >&2; exit 2; }
[ -n "$PLAN" ] || { printf '%s\n' "Installation must be initiated by a confirmed backend-manager plan" >&2; exit 3; }
printf '%s\n' "CSV Reporting installation is delegated to backend plan $PLAN"
