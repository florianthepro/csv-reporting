#!/usr/bin/env python3
import argparse
import csv
import fnmatch
import importlib
import importlib.util
import io
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

FTPKIT_SOURCE = "git+https://github.com/florianthepro/ftpkit.git@main"


def install_package(value: str) -> None:
    subprocess.run([sys.executable, "-m", "pip", "install", value], check=True)


def load_ftpkit(allow_install: bool = False):
    if importlib.util.find_spec("ftpkit") is None:
        if not allow_install:
            raise RuntimeError("ftpkit fehlt. Zuerst `python backend.py install` ausführen.")
        install_package(FTPKIT_SOURCE)
        importlib.invalidate_caches()
    return importlib.import_module("ftpkit")


def install_dependencies(django_version: str, gunicorn_version: str) -> None:
    load_ftpkit(True)
    install_package(f"Django=={django_version}")
    install_package(f"gunicorn=={gunicorn_version}")
    install_package("psycopg[binary]>=3.2,<4")


if len(sys.argv) > 1 and sys.argv[1] == "install":
    parser = argparse.ArgumentParser()
    parser.add_argument("command")
    parser.add_argument("--django-version", default="5.2.*")
    parser.add_argument("--gunicorn-version", default="23.*")
    args = parser.parse_args()
    install_dependencies(args.django_version, args.gunicorn_version)
    raise SystemExit(0)

ftpkit = load_ftpkit()

from django.conf import settings

STATE_DIRECTORY = Path(os.getenv("STATE_DIRECTORY", "/var/lib/csv-reporting/state"))
CONFIG_FILE = STATE_DIRECTORY / "csv-reporting.json"
CACHE_FILE = STATE_DIRECTORY / "latest.csv"
REMOTE_URL = os.getenv("CSV_REMOTE_URL", "").strip()
EDITOR_ENABLED = os.getenv("CSV_EDITOR_ENABLED", "false").lower() == "true"
MAX_DOWNLOAD_BYTES = int(os.getenv("CSV_MAX_DOWNLOAD_BYTES", "10485760"))

if not settings.configured:
    settings.configure(
        DEBUG=os.getenv("DJANGO_DEBUG", "false").lower() == "true",
        SECRET_KEY=os.environ["DJANGO_SECRET_KEY"],
        ALLOWED_HOSTS=[v.strip() for v in os.getenv("DJANGO_ALLOWED_HOSTS", "localhost").split(",") if v.strip()],
        ROOT_URLCONF=__name__,
        INSTALLED_APPS=["django.contrib.contenttypes"],
        MIDDLEWARE=[
            "django.middleware.security.SecurityMiddleware",
            "django.middleware.common.CommonMiddleware",
            "django.middleware.csrf.CsrfViewMiddleware",
            "django.middleware.clickjacking.XFrameOptionsMiddleware",
        ],
        DATABASES={
            "default": {
                "ENGINE": "django.db.backends.postgresql",
                "NAME": os.environ["DATABASE_NAME"],
                "USER": os.environ["DATABASE_USER"],
                "PASSWORD": os.environ["DATABASE_PASSWORD"],
                "HOST": os.getenv("DATABASE_HOST", "127.0.0.1"),
                "PORT": os.getenv("DATABASE_PORT", "5432"),
            }
        },
        CSRF_COOKIE_SECURE=os.getenv("DJANGO_HTTPS", "true").lower() == "true",
        SECURE_PROXY_SSL_HEADER=("HTTP_X_FORWARDED_PROTO", "https"),
        USE_TZ=True,
    )

import django
django.setup()

from django.core.management import call_command
from django.core.wsgi import get_wsgi_application
from django.http import HttpResponse, JsonResponse
from django.urls import path
from django.views.decorators.csrf import ensure_csrf_cookie
from django.views.decorators.http import require_GET, require_http_methods

DEFAULT_CONFIG = {
    "show_columns": [],
    "column_renames": {},
    "rules": [],
    "enable_links": False,
    "column_links": {},
    "download_filters": {},
}


def detect_delimiter(text: str) -> str:
    first = text.splitlines()[0] if text.splitlines() else text
    counts = {",": first.count(","), ";": first.count(";"), "\t": first.count("\t")}
    delimiter = max(counts, key=counts.get)
    return delimiter if counts[delimiter] > 0 else ","


def parse_csv(text: str) -> tuple[list[str], list[dict[str, str]], str]:
    text = text.lstrip("\ufeff")
    delimiter = detect_delimiter(text)
    rows = list(csv.reader(io.StringIO(text), delimiter=delimiter))
    rows = [row for row in rows if row and any(cell.strip() for cell in row)]
    if not rows or len(rows[0]) < 2:
        raise ValueError("CSV benötigt mindestens zwei Spalten.")
    width = len(rows[0])
    if any(len(row) != width for row in rows[:10]):
        raise ValueError("CSV-Zeilen haben unterschiedliche Spaltenanzahlen.")
    header = [cell.strip() for cell in rows[0]]
    if len(set(name.casefold() for name in header)) != len(header):
        raise ValueError("CSV enthält doppelte Spaltennamen.")
    data = [dict(zip(header, row)) for row in rows[1:]]
    return header, data, delimiter


def read_limited(response) -> bytes:
    data = response.read(MAX_DOWNLOAD_BYTES + 1)
    if len(data) > MAX_DOWNLOAD_BYTES:
        raise ValueError(f"CSV überschreitet {MAX_DOWNLOAD_BYTES} Bytes.")
    return data


def fetch_remote() -> str:
    if not REMOTE_URL:
        raise ValueError("CSV_REMOTE_URL ist nicht konfiguriert.")
    parsed = urllib.parse.urlparse(REMOTE_URL)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise ValueError("CSV_REMOTE_URL muss eine gültige HTTP(S)-URL sein.")
    attempts = [
        ("POST", urllib.parse.urlencode({"downloadBtn": "1"}).encode()),
        ("GET", None),
    ]
    errors = []
    for method, body in attempts:
        request = urllib.request.Request(
            REMOTE_URL,
            data=body,
            method=method,
            headers={"Accept": "text/csv, application/csv, text/plain;q=0.9, */*;q=0.1", "User-Agent": "CSV-Reporting/3.0"},
        )
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                raw = read_limited(response)
                content_type = response.headers.get_content_charset() or "utf-8"
                text = raw.decode(content_type, errors="strict")
                parse_csv(text)
                return text
        except (urllib.error.URLError, UnicodeError, ValueError) as exc:
            errors.append(f"{method}: {exc}")
    raise ValueError("Remote-Abruf fehlgeschlagen. " + " | ".join(errors))


def load_config() -> dict:
    if not CONFIG_FILE.exists():
        return DEFAULT_CONFIG.copy()
    try:
        value = json.loads(CONFIG_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return DEFAULT_CONFIG.copy()
    result = DEFAULT_CONFIG.copy()
    if isinstance(value, dict):
        result.update(value)
    return result


def save_config(value: dict) -> None:
    STATE_DIRECTORY.mkdir(parents=True, exist_ok=True)
    temporary = CONFIG_FILE.with_suffix(".tmp")
    temporary.write_text(json.dumps(value, ensure_ascii=False, indent=2), encoding="utf-8")
    temporary.replace(CONFIG_FILE)


def apply_download_filters(header: list[str], rows: list[dict], config: dict) -> list[dict]:
    filters = config.get("download_filters", {})
    if not isinstance(filters, dict):
        return rows
    normalized = {name.casefold(): name for name in header}
    rules = []
    for column, blocked in filters.items():
        actual = normalized.get(str(column).strip().casefold())
        if actual:
            rules.append((actual, {str(v).strip().casefold() for v in blocked}))
    return [row for row in rows if not any(str(row.get(column, "")).strip().casefold() in blocked for column, blocked in rules)]


def timestamp_minutes(value: str):
    try:
        parsed = datetime.strptime(value.strip(), "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except ValueError:
        return None
    return round((parsed - datetime.now(timezone.utc)).total_seconds() / 60, 2)


def match_pattern(value: str, pattern: str) -> bool:
    value = str(value)
    pattern = str(pattern).strip()
    minutes = timestamp_minutes(value)
    if minutes is not None:
        for operator in (">=", "<=", ">", "<"):
            if pattern.startswith(operator):
                try: target = float(pattern[len(operator):])
                except ValueError: return False
                return {">=": minutes >= target, "<=": minutes <= target, ">": minutes > target, "<": minutes < target}[operator]
        if ".." in pattern:
            try: low, high = map(float, pattern.split("..", 1))
            except ValueError: return False
            return low <= minutes <= high
    if pattern.casefold() in {"n/a", "na"}:
        return value.strip() == "" or value.strip().casefold() in {"n/a", "na"}
    return fnmatch.fnmatchcase(value.casefold(), pattern.casefold())


def rule_matches(row: dict, rule: dict) -> bool:
    conditions = rule.get("conditions", [])
    if not isinstance(conditions, list) or not conditions:
        return False
    for condition in conditions:
        column = str(condition.get("column", ""))
        pattern = str(condition.get("pattern", ""))
        matched = match_pattern(str(row.get(column, "")), pattern)
        if condition.get("negate"):
            matched = not matched
        if not matched:
            return False
    return True


def current_dataset(force: bool = False):
    STATE_DIRECTORY.mkdir(parents=True, exist_ok=True)
    if force or not CACHE_FILE.exists():
        text = fetch_remote()
        CACHE_FILE.write_text(text, encoding="utf-8")
    else:
        text = CACHE_FILE.read_text(encoding="utf-8")
    header, rows, delimiter = parse_csv(text)
    rows = apply_download_filters(header, rows, load_config())
    return header, rows, delimiter


@require_GET
def health(request):
    return JsonResponse({"status": "ok"})


@require_GET
def data_view(request):
    try:
        force = request.GET.get("refresh") == "1"
        header, rows, delimiter = current_dataset(force)
        config = load_config()
        mode = request.GET.get("view", "index")
        visible = config.get("show_columns") or header
        visible = [name for name in visible if name in header]
        result = []
        for row in rows:
            matched = [rule for rule in config.get("rules", []) if rule_matches(row, rule)]
            if mode in {"index", "map"} and config.get("rules") and not matched:
                continue
            result.append({"values": {key: row.get(key, "") for key in visible}, "rules": matched})
        return JsonResponse({"header": visible, "rows": result, "delimiter": delimiter, "view": mode})
    except (OSError, ValueError) as exc:
        return JsonResponse({"error": str(exc)}, status=502)


@ensure_csrf_cookie
@require_http_methods(["GET", "PUT"])
def config_view(request):
    if request.method == "GET":
        return JsonResponse({"config": load_config(), "editable": EDITOR_ENABLED})
    if not EDITOR_ENABLED:
        return JsonResponse({"error": "Editor ist deaktiviert."}, status=403)
    try:
        value = json.loads(request.body or b"{}")
    except json.JSONDecodeError:
        return JsonResponse({"error": "Ungültiges JSON."}, status=400)
    if not isinstance(value, dict):
        return JsonResponse({"error": "Konfiguration muss ein Objekt sein."}, status=400)
    save_config(value)
    return JsonResponse({"saved": True})


@require_GET
def raw_csv(request):
    try:
        header, rows, delimiter = current_dataset(request.GET.get("refresh") == "1")
        output = io.StringIO()
        writer = csv.DictWriter(output, fieldnames=header, delimiter=delimiter)
        writer.writeheader()
        writer.writerows(rows)
        return HttpResponse(output.getvalue(), content_type="text/csv; charset=utf-8")
    except (OSError, ValueError) as exc:
        return JsonResponse({"error": str(exc)}, status=502)


api = os.getenv("DJANGO_API_PREFIX", "/api").strip("/")
urlpatterns = [
    path(f"{api}/health", health),
    path(f"{api}/data", data_view),
    path(f"{api}/config", config_view),
    path(f"{api}/raw", raw_csv),
]

application = get_wsgi_application()


def main() -> None:
    parser = argparse.ArgumentParser()
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("check")
    commands.add_parser("migrate")
    commands.add_parser("refresh")
    args = parser.parse_args()
    if args.command == "check":
        call_command("check", "--deploy")
    elif args.command == "migrate":
        call_command("migrate", interactive=False)
    elif args.command == "refresh":
        current_dataset(True)
        print("CSV aktualisiert.")


if __name__ == "__main__":
    main()
