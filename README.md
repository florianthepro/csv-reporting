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

## Sichere Aktualisierung und Ports

Eine vorhandene Installation wird zuerst vollständig unter `/var/backups/csv-reporting/<Zeitstempel>/` gesichert. App und Python-Umgebung werden neu aufgebaut; Environment, Regeln, Statusdaten und PostgreSQL-Daten bleiben erhalten oder werden aus der Sicherung zurückgeführt.

Ein belegter Port beendet das Setup nicht mehr. Es zeigt verfügbare Ports in `8080-8090` beziehungsweise `8443-8453` und fragt erneut. Ein Port der bestehenden eigenen Caddy-Site wird als `aktuell` und wiederverwendbar angezeigt.

## Version 9

Das Setup zeigt beim Start `CSV Reporting Setup v9`. Fehlt diese Zeile, liegt im GitHub-Branch noch ein älteres `setup.sh`. Frontend-Dateien verwenden Cache-Busting und Caddy liefert `Cache-Control: no-store`, damit nach einem Upgrade nicht altes JavaScript mit neuem HTML gemischt wird.

Der Import akzeptiert `.json` und erkennt zusätzlich Metadaten aus einer alten `index.php`. Wenn die PHP-Datei kein eindeutig eingebettetes Regel-JSON enthält, fordert die Oberfläche verständlich die alte `csv-reporting.json` an.

## Version 10

Tabellenspalten werden anhand der Überschrift und einer Stichprobe der ersten 120 Zeilen gewichtet. Sehr kurze Spalten bleiben schmal; lange Inhalte erhalten mehr Platz, ohne die Seite horizontal zu verbreitern. Editor, Regelliste und Regeldialog sind kompakter. Die Regelbeschreibung wird weiterhin automatisch erzeugt, aber nicht mehr als unnötiges Feld angezeigt.

## Version 11

CSV-Spaltenüberschriften bleiben immer einzeilig. Die Spaltenbreite wird aus der durchschnittlichen Inhaltslänge aller Zellen der jeweiligen Spalte berechnet und anschließend relativ zu den übrigen Spalten verteilt. Die Überschrift setzt zusätzlich eine Mindestbreite; überlange Überschriften werden einzeilig mit Auslassungspunkten dargestellt.
