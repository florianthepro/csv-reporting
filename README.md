# csv-reporting

Managed CSV reporting web application with the `index`, `map`, `raw` and `edit` views. It supports rules, filters, column selection and renaming, links, download filters, import/export and atomic autosave.

## Development

```sh
python -m venv .venv
. .venv/bin/activate
python -m pip install ../ftpkit-pylib
python -m pip install -e .
FTPKIT_INSTANCE_ID=550e8400-e29b-41d4-a716-446655440000 \
CSV_REPORTING_DATA_ROOT=./var \
CSV_REPORTING_AUTH_TOKEN=development-only-token \
csv-reporting serve --host 127.0.0.1 --port 8080
```

Place a CSV file at `var/report.csv`, then open `http://127.0.0.1:8080/`.

## Production

Installation is handed to the central `backend` manager. `setup.sh` verifies that manager first and does not create a parallel global registry.

```sh
sudo FTPKIT_CONFIRM_PLAN=<manager-plan-id> ./setup.sh install
```

The editor is enabled by default. An administrator may explicitly disable it with `CSV_REPORTING_EDITOR_ENABLED=false`.
