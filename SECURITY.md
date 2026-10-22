# Sicherheit

- HTTPS bevorzugen. Bei bewusst gewähltem HTTP sind die Django-Hinweise zu HSTS, SSL-Weiterleitung und Secure-Cookies erwartbar.
- PostgreSQL und Gunicorn nicht öffentlich freigeben.
- Den Editor nur aktivieren, wenn er benötigt wird.
- Environment-Datei und Statusverzeichnis nur für `root` und den Dienstbenutzer lesbar halten.
- Importierte JSON-Dateien vor dem Speichern prüfen.
- Sicherheitsmeldungen ohne Passwörter, Tokens oder vollständige Environment-Dateien weitergeben.
