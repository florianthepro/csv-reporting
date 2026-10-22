# CSV Reporting

CSV Reporting lädt eine entfernte CSV-Datei, filtert und visualisiert deren Inhalte und stellt einen optionalen Regel-Editor bereit.

## Dateien

- `setup.sh`: interaktive Installation für Debian/Ubuntu
- `backend.py`: Django-/Gunicorn-Backend
- `frontend/`: Browseroberfläche
- `index.php`: Referenz und Importquelle des alten Systems

## Installation

```bash
curl -fsSL https://raw.githubusercontent.com/florianthepro/csv-reporting/main/setup.sh | sudo bash
```

Vor der Installation müssen zusätzlich `frontend/index.svg` und `frontend/edit.svg` im Repository liegen.

## Seiten

- `?` oder `?_page=index`
- `?_page=map`
- `?_page=raw`
- `?_page=edit`

## Konfiguration

Die Editor-Konfiguration wird als JSON gespeichert. Alte JSON-Dateien aus `index.php` können importiert werden. Titel, Überschrift und Downloadfilter, die im alten System außerhalb der JSON-Datei standen, werden beim Import über einen Dialog ergänzt.
