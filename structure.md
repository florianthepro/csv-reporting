# csv-reporting structure

## Public repository

- `src/csv_reporting/`: CSV-specific backend
- `src/csv_reporting/frontend/`: HTML, CSS, JavaScript and SVG assets
- `src/csv_reporting/app-manifest.json`: manager contract
- `setup.sh`: thin manager-aware bootstrap
- `tests/`: isolated app tests

## Instance layout

```text
/sites/csv-reporting-<uuid>/
├── data/       config.json, report.csv, cache and import metadata
├── frontend/   static assets
├── backend/    installed application package
└── srv/        virtual environment, Unix socket and temporary runtime files
```

`data/config.json` and `data/report.csv` are persistent and included in backup. Cache is reproducible. Imported uploads become persistent only after validation. `srv/` is always reproducible.

Secrets are supplied via a protected systemd environment file. They are not in frontend assets, app manifest, configuration exports, logs or API responses.

## Interfaces

The app imports generic validation, UUID, atomic JSON, locking and safe-path functions from `ftpkit-pylib`. `setup.sh` sources `ftpkit-shlib` and verifies the central manager. Installation, users, services, Caddy, certificates, ports and the global registry remain responsibilities of `backend`.

The management endpoint is `/management/v1`. It exposes status, health, version, configuration schema, declared capabilities, backup scope and restore validation. It never performs system-wide changes.

## Upgrade and rollback

The manager backs up `data/`, replaces code and the virtual environment, validates configuration migration and health, then commits the global registry. On failure it restores code, configuration, service/proxy state and the previous registry revision. Removal preserves `data/` and backups unless the confirmed manager plan explicitly includes them.
