# CSV Reporting

CSV Reporting lädt eine entfernte CSV-Datei, validiert sie und stellt Ergebnis-, Karten-, Rohdaten- und Regelansicht bereit. Die Oberfläche der Ergebnis-, Karten- und Rohdatenansicht orientiert sich am ursprünglichen PHP-Stand. Nur der Editor wurde kompakter und logisch gruppiert.

## Installation

```bash
curl -fsSL https://raw.githubusercontent.com/florianthepro/csv-reporting/main/setup.sh | sudo bash
```

Das Setup erkennt vorhandene Webserver und belegte Ports. Apache kann unverändert auf 80/443 bleiben; Caddy wird dann wahlweise per internem HTTPS auf einer LAN-IP und einem freien Port betrieben.

## Repository

```text
csv-reporting/
├── README.md
├── structure.md
├── setup.sh
├── backend.py
└── frontend/
    ├── index.html
    ├── style.css
    ├── app.js
    ├── index.svg
    └── edit.svg
```

`index.svg` und `edit.svg` werden nach dem Push im Ordner `frontend/` ergänzt.

## Betrieb

```bash
sudo systemctl status csv-reporting
sudo journalctl -u csv-reporting -f
```
