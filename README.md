# CSV Reporting

CSV Reporting lädt eine CSV-Quelle, stellt gefilterte Tabellenansichten bereit und enthält einen webbasierten Regel-Editor.

## Frontend

Das Frontend liegt in `frontend/`:

- `index.html`: Ansichten und Editor
- `app.js`: Tabellen, Filter, Regeln, Import/Export und automatisches Speichern
- `style.css`: responsive Darstellung
- `index.svg` und `edit.svg`: Icons, die vor der Installation ergänzt werden

Die Ansichten sind über `?`, `?_page=map`, `?_page=raw` und `?_page=edit` erreichbar. Beim ersten Aufruf ohne konfigurierte CSV-Quelle öffnet sich automatisch der Editor. Unter **Allgemein** werden CSV-Quell-URL, Seitentitel, Überschrift und das interne CA-Zertifikat verwaltet.

## Backend

`backend.py` stellt die Django-Endpunkte bereit:

- `api/health`
- `api/data`
- `api/config`
- `api/raw`
- `api/certificate`

Die Konfiguration liegt in `/var/lib/csv-reporting/state/csv-reporting.json`. Der Editor speichert Änderungen automatisch. PostgreSQL wird für Django verwendet, Gunicorn läuft über einen Unix-Socket und Caddy stellt die Weboberfläche standardmäßig per intern signiertem HTTPS bereit.

## Betrieb

`setup.sh` installiert, aktualisiert, setzt zurück und entfernt die Anwendung. Bestehende Daten werden vor Aktualisierungen gesichert. App und Python-Umgebung werden sauber neu aufgebaut, während Konfiguration, Regeln und Datenbank erhalten bleiben.

## Version 17

Die CSV-Quell-URL ist bei einer Neueinrichtung verpflichtend und muss mit `http://` oder `https://` beginnen. Der Editor bleibt auch ohne erfolgreich geladenen CSV-Datensatz funktionsfähig; Importe greifen nicht mehr auf `null.all_header` zu. Die Zertifikatsverwaltung kann die interne Caddy-CA exportieren sowie ein hochgeladenes Zertifikat samt passendem privaten Schlüssel prüfen und installieren.
