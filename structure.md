# Struktur

- `setup.sh`: Debian-Prüfung, Webserver-Konflikte, Ports, Verzeichnisse, PostgreSQL, Python, systemd, Caddy und Erreichbarkeit.
- `backend.py`: CSV-Abruf per POST/GET, Validierung, Cache, Downloadfilter, Regeln, Konfiguration und API.
- `frontend/index.html`: gemeinsame Oberfläche.
- `frontend/style.css`: ursprüngliche Tabellen-/Menüoptik; kompakter Editor.
- `frontend/app.js`: Ansichten, Filter, Links, Regeln, Drag-and-drop und JSON-Import/-Export.

```text
Browser → Caddy → statisches Frontend
                → /api/* → Gunicorn-Socket → Django → CSV/JSON/PostgreSQL
```

```text
/srv/webapps/csv-reporting/app
/srv/webapps/csv-reporting/venv
/var/lib/csv-reporting/state
/etc/csv-reporting/environment
/etc/caddy/sites/csv-reporting.caddy
/run/csv-reporting/csv-reporting.sock
```

Das Setup arbeitet wiederholbar: bestehender Code wird gesichert, Geheimnisse bleiben erhalten, Caddy-Konfiguration wird vor Aktivierung validiert und bei Fehlern zurückgerollt. Apache wird nur nach ausdrücklicher Auswahl gestoppt.
