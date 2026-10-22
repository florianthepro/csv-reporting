# Struktur

## Trennung des früheren Ein-Datei-Systems

Die frühere `index.php` enthielt Datenabruf, CSV-Parser, Regeln, Editor, HTML, CSS und JavaScript in einer Datei. Die neue Struktur trennt diese Verantwortlichkeiten:

```text
setup.sh
└── Debian, Verzeichnisse, PostgreSQL, systemd, Gunicorn und Caddy

backend.py
├── ftpkit-Import
├── CSV-Abruf und Validierung
├── CSV-Cache
├── Regelverarbeitung
├── Konfigurationsspeicherung
└── JSON-/CSV-API

frontend/
├── index.html
├── style.css
└── app.js
```

## Serverstruktur

```text
/srv/webapps/csv-reporting/
├── app/
├── venv/
└── caddy/Caddyfile

/var/lib/csv-reporting/state/
├── latest.csv
└── csv-reporting.json

/etc/csv-reporting/environment
/etc/systemd/system/csv-reporting.service
/etc/caddy/sites/csv-reporting.caddy
/run/csv-reporting/csv-reporting.sock
```

## Anfrageweg

```text
Browser
→ Caddy
  ├── /csv-reporting/* statisches Frontend
  └── /csv-reporting/api/*
      → Gunicorn-Socket
        → Django backend.py
          ├── Remote-CSV
          ├── JSON-Konfiguration
          └── PostgreSQL-Grundlage
```

## Bewusste Entscheidungen

- Keine Benutzerkonten, weil die bisherige Anwendung keine Benutzerverwaltung benötigt.
- Der Konfigurationseditor ist standardmäßig deaktiviert, weil Schreibzugriff ohne Authentifizierung öffentlich unsicher wäre.
- CSV-Quelle und Geheimnisse stehen nicht im Repository.
- Die CSV wird begrenzt heruntergeladen und vor Verwendung strukturell geprüft.
- Die letzte gültige CSV wird außerhalb des Codes unter `/var/lib` gespeichert.
- `ftpkit` bleibt für allgemeine Hilfsfunktionen vorgesehen; CSV-Reporting-Logik bleibt im Projekt.
- PostgreSQL wird entsprechend dem gemeinsamen Plattformstandard eingerichtet, obwohl die CSV-Fachdaten weiter dateibasiert bleiben.

## Übertragung der bisherigen Funktionen

```text
remote_fetch             → fetch_remote
Delimiter-Erkennung      → detect_delimiter
CSV-Parser               → parse_csv
Downloadfilter           → apply_download_filters
Text-/Wildcard-Regeln    → match_pattern
Zeitbedingungen          → timestamp_minutes + match_pattern
Regelprüfung             → rule_matches
JSON-Regeldatei          → csv-reporting.json
index/map/raw/edit        → Frontendansichten + API
```

## Noch offen für eine vollständige Funktionsgleichheit

- visuelle Regelbearbeitung mit einzelnen Formularfeldern statt JSON-Textfeld
- generische Spaltenlinks im Frontend
- Drag-and-drop-Sortierung von Regeln
- Info-Texte pro Regel
- getrennte Kartenansicht mit eigener Darstellung
- Import und Export der Konfiguration über Schaltflächen

Diese Punkte sollten als normale Frontend-Funktionen umgesetzt werden und nicht wieder in eine einzelne Backend-Datei gemischt werden.
