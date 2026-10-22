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

## Version 13: Verwaltungsmenü

Bei einer bestehenden Installation zeigt `setup.sh` nur noch:

```text
[1] Update
[2] Reset
[3] Delete
[Q] Beenden
```

`Update` übernimmt Environment, CSV-URL, Editorstatus, Regeln, Cache, PostgreSQL-Datenbank, Caddy-Site, IP, Port und TLS-Konfiguration unverändert. App und Python-Umgebung werden nach einer Vollsicherung frisch aufgebaut. Es gibt beim Update keine erneute Abfrage zu Hosting, Port, TLS oder CSV-Quelle.

`Reset` sichert zuerst alles, löscht anschließend Konfiguration, Regeln, Cache und Datenbank und führt danach die vollständige interaktive Neueinrichtung aus. `Delete` sichert zuerst alles und entfernt danach Dienst, Projekt, Daten, Konfiguration, Caddy-Site und Datenbank.

## Version 14

Finaler konsolidierter Stand auf Basis von Version 13. Enthalten sind die einzeiligen CSV-Header, die relative Spaltenbreite nach dem Durchschnitt der gesamten Spalte, der kompakte Editor, der reduzierte Regeldialog sowie die Installations- und Upgrade-Korrekturen der vorherigen Versionen.

## Zertifikatsdownload

`get-cert.sh` (separater Download) fragt die tatsächlich verwendete HTTPS-Adresse ab, liest das dazu ausgelieferte Serverzertifikat, zeigt Subject, Issuer und SAN an und startet anschließend einen temporären HTTP-Downloadserver im lokalen Netz. Installiert wird das Caddy-Root-CA-Zertifikat; das Serverzertifikat wird zusätzlich nur zur Kontrolle angeboten. Der Server endet mit Enter oder Strg+C.
