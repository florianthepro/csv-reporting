# Struktur

## Konzept

Die frühere `index.php` enthielt Remote-Abruf, CSV-Parser, Filter, Regeln, Editor, HTML, CSS und JavaScript. Die neue Implementierung trennt diese Bereiche, ohne Funktionen in das Setup zu verschieben.

```text
setup.sh
└── Installation und Betrieb

backend.py
├── CSV-Abruf
├── CSV-Validierung
├── Filter und Regeln
├── Konfigurationsspeicherung
├── Cache
└── API

index.svg und edit.svg
└── Anwendungssymbole im Repository-Root

frontend/
├── Darstellung
├── Tabellenfilter
├── Regel-Editor
├── Import und Export
└── Navigation
```

## setup.sh

`setup.sh` übernimmt ausschließlich Infrastruktur:

- Debian prüfen
- Python, Caddy, PostgreSQL und Git installieren
- Projektbenutzer und Verzeichnisse anlegen
- Repository installieren
- Python-venv erstellen
- `ftpkit`, Django, Gunicorn und psycopg installieren
- PostgreSQL-Datenbank und Rolle anlegen
- Geheimnisse in `/etc/csv-reporting/environment` speichern
- Gunicorn als systemd-Dienst einrichten
- Caddy-Site ergänzen
- HTTP oder HTTPS konfigurieren
- Health Check durchführen
- vorhandenen Code vor dem Ersetzen sichern

## backend.py

`backend.py` enthält die Fachlogik:

- `ftpkit` laden
- Remote-CSV per POST und GET abrufen
- Größe und Struktur validieren
- Zeichensatz dekodieren
- Delimiter erkennen
- Downloadfilter anwenden
- letzte gültige CSV cachen
- Regelbedingungen auswerten
- Zeitstempel in Minuten umrechnen
- JSON-Konfiguration atomar speichern
- JSON- und CSV-API bereitstellen

`ftpkit` bleibt für projektübergreifende Hilfsfunktionen vorgesehen. CSV-Reporting-spezifische Regeln bleiben in diesem Repository.

## Frontend

Das Frontend arbeitet ohne Buildsystem und ohne externe JavaScript-Abhängigkeiten:

- `index.html`: semantische Oberfläche und Verweise auf `index.svg`/`edit.svg`
- `style.css`: responsives Hell-/Dunkel-Design
- `app.js`: Ansichten, Filter, Links, Editor, Drag-and-drop sowie Import und Export

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
→ Caddy auf Port 80/443
  ├── statisches Frontend
  └── /api/*
      → Gunicorn-Unix-Socket
        → Django in backend.py
          ├── Remote-CSV
          ├── CSV-Cache
          ├── Regelkonfiguration
          └── PostgreSQL-Grundlage
```

## Funktionsübertragung

```text
csvreporting_remote_fetch      → fetch_remote
csvreporting_detect_delimiter  → detect_delimiter
csvreporting_parse_csv         → parse_csv
csvreporting_dwlfilters        → download_filters
parse_minutes_pattern          → match_pattern
rule_matches                   → rule_matches
csv-reporting.json             → CONFIG_FILE
index/map/raw/edit             → Frontendansichten und API
column_links                   → linkFor
show_columns                   → Spalteneditor
column_renames                 → Umbenennungseditor
Regel aktiv/deaktiv            → stash_conditions
Info pro Regel                 → info
Drag-and-drop                  → HTML Drag and Drop in app.js
Konfigurationsimport/-export   → Dateiaktionen im Frontend
```

## Sicherheitsgrenzen

- keine Benutzerkonten
- Editor standardmäßig deaktiviert
- CSV-URL und Geheimnisse außerhalb des Repositorys
- Download mit Größenlimit
- nur HTTP(S)-Quellen
- private Serverwerte mit Gruppenrechten `0640`
- Dienst läuft als eigener Benutzer, nicht als root
- Caddy greift nur auf statische Dateien und den Projektsocket zu
- Konfigurationsdatei wird über eine temporäre Datei atomar ersetzt


## Symbole

```text
csv-reporting/
├── index.svg   Standardsymbol für Ergebnis-, Karten- und Rohdatenansicht
└── edit.svg    Symbol für die Regel- und Konfigurationsansicht
```

Die SVG-Dateien bleiben bewusst im Root des `main`-Branches. `setup.sh` bricht mit einer klaren Meldung ab, wenn eine der Dateien fehlt. Caddy liefert die Dateien im Projektpfad aus, obwohl das übrige Frontend aus `frontend/` bereitgestellt wird. Dadurch gibt es keine Kopien und nur jeweils eine maßgebliche Symboldatei.
