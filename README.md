# CSV Reporting

Webanwendung zur Darstellung, Filterung und regelbasierten Verarbeitung einer entfernten CSV-Datenquelle. Die bisherige einzelne PHP-Datei wurde sauber in statisches Frontend, Django-Backend und Server-Setup getrennt.

## Funktionen

- CSV-Abruf zuerst per POST (`downloadBtn=1`), danach als GET-Fallback
- Erkennung von Komma, Semikolon und Tabulator
- Prüfung auf gültige, konsistente CSV-Daten
- konfigurierbare Ausschlussfilter beim Abruf
- Ergebnis-, Regel- und Rohdatenansicht
- Spaltenauswahl und Umbenennungen über JSON-Konfiguration
- Wildcard-, Negations- und Zeitwertregeln
- optionaler Konfigurationseditor
- keine Benutzerkonten

## Installation

```bash
curl -fsSL https://raw.githubusercontent.com/florianthepro/csv-reporting/main/setup.sh | sudo bash
```

Das Setup fragt nach Domain, HTTP/HTTPS, CSV-Quell-URL und danach, ob der Konfigurationseditor aktiviert werden soll.

## Struktur

```text
csv-reporting/
├── README.md
├── structure.md
├── setup.sh
├── backend.py
└── frontend/
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

Geschützte Serverwerte liegen in:

```text
/etc/csv-reporting/environment
```

Regeln, Spalten und Downloadfilter liegen in:

```text
/var/lib/csv-reporting/state/csv-reporting.json
```

Beispiel:

```json
{
  "show_columns": ["ID", "Status", "Zeit"],
  "column_renames": {"Zeit": "Verbleibend"},
  "rules": [
    {
      "description": "Offene Einträge über 30 Minuten",
      "conditions": [
        {"column": "Status", "pattern": "open", "negate": false},
        {"column": "Zeit", "pattern": ">30", "negate": false}
      ]
    }
  ],
  "enable_links": false,
  "column_links": {},
  "download_filters": {"row": ["line"]}
}
```

Der Editor ist standardmäßig deaktiviert. Ohne separate Authentifizierung sollte der Editor nicht öffentlich aktiviert werden.

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

## Daten

```text
/srv/webapps/csv-reporting/app/     Programmdateien
/srv/webapps/csv-reporting/venv/    Python-Umgebung
/var/lib/csv-reporting/state/       CSV-Cache und Regelkonfiguration
/etc/csv-reporting/environment      geschützte Produktionswerte
```

## Lizenz

MIT
