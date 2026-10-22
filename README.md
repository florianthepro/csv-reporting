# CSV Reporting

CSV Reporting lädt CSV-Daten per HTTP(S), validiert und filtert die Daten und stellt sie als Ergebnis-, Karten-, Rohdaten- und Regelansicht bereit. Die frühere Ein-Datei-PHP-Anwendung ist vollständig in ein statisches Frontend, ein Django-Backend und ein reproduzierbares Server-Setup getrennt.

## Installation

```bash
curl -fsSL https://raw.githubusercontent.com/florianthepro/csv-reporting/main/setup.sh | sudo bash
```

Die Dateien `index.svg` und `edit.svg` müssen im Ordner `frontend/` des `main`-Branches liegen. `index.svg` ist das Standardsymbol der Anwendung; `edit.svg` wird in der Regelansicht verwendet. Das Setup prüft beide Dateien und Caddy liefert sie unterhalb des Projektpfads aus.

Das Setup fragt nach:

- Domain oder IP
- automatischem HTTPS, eigenem Zertifikat oder HTTP
- CSV-Quell-URL
- Aktivierung des Regel-Editors

## Funktionen

- CSV-Abruf über POST mit `downloadBtn=1`
- automatischer GET-Fallback
- Begrenzung der maximalen Downloadgröße
- Erkennung von Komma, Semikolon und Tabulator
- Prüfung auf leere, ungültige oder inkonsistente CSV-Daten
- konfigurierbare Ausschlussfilter direkt nach dem Abruf
- Ergebnisansicht mit regelbasiert ausgewählten Zeilen
- Kartenansicht für regelbasierte Treffer
- Rohdatenansicht ohne Regelbegrenzung
- Filter pro sichtbarer Spalte
- Spalten ein- und ausblenden
- Spalten umbenennen
- generische Links mit `*` als Platzhalter
- Wildcards `*` und `?`
- Negation von Bedingungen
- Zeitbedingungen `>`, `>=`, `<`, `<=`, Bereiche und exakte Werte
- mehrere UND-Bedingungen pro Regel
- Regeln aktivieren, deaktivieren, bearbeiten und löschen
- Drag-and-drop-Reihenfolge
- Infotext pro Regel
- JSON-Import und -Export
- CSV-Rohdatenexport
- manueller und API-basierter CSV-Refresh
- heller und dunkler Systemmodus
- keine Benutzerkonten und keine Registrierung

## Repository-Struktur

```text
csv-reporting/
├── README.md
├── structure.md
├── setup.sh
├── backend.py
└── frontend/
    ├── index.svg
    ├── edit.svg
    ├── index.html
    ├── style.css
    └── app.js
```

## Betrieb

```bash
sudo systemctl status csv-reporting
sudo systemctl restart csv-reporting
sudo journalctl -u csv-reporting -f
```

CSV manuell aktualisieren:

```bash
sudo -u csv-reporting sh -c 'set -a; . /etc/csv-reporting/environment; set +a; /srv/webapps/csv-reporting/venv/bin/python /srv/webapps/csv-reporting/app/backend.py refresh'
```

## Konfiguration

Geschützte Produktionswerte:

```text
/etc/csv-reporting/environment
```

Regeln und Anzeigeeinstellungen:

```text
/var/lib/csv-reporting/state/csv-reporting.json
```

Letzte gültige CSV:

```text
/var/lib/csv-reporting/state/latest.csv
```

Beispielkonfiguration:

```json
{
  "show_columns": ["ID", "Status", "Zeit"],
  "column_renames": {"Zeit": "Verbleibend"},
  "rules": [
    {
      "description": "Status = open & Zeit > 30",
      "info": "Offene Einträge über 30 Minuten",
      "conditions": [
        {"column": "Status", "pattern": "open", "negate": false},
        {"column": "Zeit", "pattern": ">30", "negate": false}
      ]
    }
  ],
  "enable_links": true,
  "column_links": {
    "ID": "*",
    "Ticket": "https://example.invalid/ticket/*"
  },
  "download_filters": {
    "row": ["line"]
  }
}
```

## API

```text
GET /api/health
GET /api/data?view=index
GET /api/data?view=map
GET /api/data?view=raw
GET /api/data?refresh=1
GET /api/config
PUT /api/config
GET /api/raw
```

`PUT /api/config` funktioniert nur, wenn der Editor bei der Installation aktiviert wurde. Da CSV Reporting bewusst keine Benutzerkonten besitzt, sollte Schreibzugriff nur in einem vertrauenswürdigen Netz oder hinter einer externen Zugriffskontrolle aktiviert werden.

## Lizenz

MIT


## ftpkit

`ftpkit` ist optional, solange CSV Reporting keine Funktion daraus verwendet. Besitzt das `ftpkit`-Repository noch kein `pyproject.toml`, zeigt die Installation nur einen Hinweis und fährt fort. Sobald `ftpkit` als Python-Paket aufgebaut ist, installiert und importiert `backend.py` die Bibliothek automatisch.
