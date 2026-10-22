# CSV Reporting

CSV Reporting lädt eine entfernte CSV-Datei, zeigt die Ansichten `index`, `map` und `raw` und bietet optional einen Regel-Editor.

## Installation

```bash
curl -fsSL https://raw.githubusercontent.com/florianthepro/csv-reporting/main/setup.sh | sudo bash
```

Bei einer vorhandenen Installation fragt das Setup, ob aktualisiert oder die Projektdateien samt Python-Umgebung neu erstellt werden sollen. Konfiguration, Statusdaten und PostgreSQL bleiben bei beiden Varianten erhalten.

## Seiten

- `?`
- `?_page=map`
- `?_page=raw`
- `?_page=edit`

Vor der Installation müssen `frontend/index.svg` und `frontend/edit.svg` im Repository vorhanden sein.

## Editor

Änderungen im Editor werden nach etwa 650 ms automatisch gespeichert. Die Bereichsschalter Allgemein, Spalten, Links, Downloadfilter und Regeln zeigen jeweils genau einen Bereich.
