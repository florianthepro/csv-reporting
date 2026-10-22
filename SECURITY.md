# Sicherheit

## Unterstützte Umgebung

Die Anwendung ist für aktuelle Debian-/Ubuntu-Systeme hinter Caddy oder Apache vorgesehen. Gunicorn soll ausschließlich über den konfigurierten Unix-Socket erreichbar sein.

## Sicherheitsmeldungen

Sicherheitsprobleme nicht als öffentliche Fehlermeldung mit Zugangsdaten, Tokens, vollständigen Umgebungsdateien oder Datenbankpasswörtern veröffentlichen. Für eine Meldung nur die betroffene Version, reproduzierbare Schritte und bereinigte Protokollauszüge bereitstellen.

## Betrieb

- `CSV_EDITOR_ENABLED` nur aktivieren, wenn das Bearbeiten benötigt wird.
- HTTPS verwenden.
- PostgreSQL nicht öffentlich freigeben.
- `/var/lib/csv-reporting` und die Environment-Datei nur für den Dienstbenutzer und `root` lesbar halten.
- Regelmäßig Debian-/Ubuntu-, Python-, Django-, Gunicorn-, PostgreSQL- und Caddy-/Apache-Sicherheitsupdates installieren.
- Importierte JSON-Dateien vor dem Speichern im Editor prüfen.
