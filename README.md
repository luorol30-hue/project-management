# Procura — Purchase Management Dashboard

Procura is a PHP and vanilla JavaScript ERP-style dashboard for managing suppliers, items, and purchase orders without a database.

## Technology

- PHP 8.1+ JSON API
- HTML, CSS, and vanilla JavaScript
- JSON-file persistence with file locking

## Project structure

- `index.html` — Vercel static application shell
- `index.php` — local application shell and shared PHP API implementation
- `api/index.php` — Vercel Function entrypoint
- `assets/app.js` — UI, real-time totals, and API calls
- `assets/style.css`, `assets/modal.css` — responsive styling
- `data/*.json` — local runtime supplier, item, and PO records

## Run locally

1. Install PHP 8.1 or later.
2. Run `php -S localhost:8000` from the project root.
3. Visit `http://localhost:8000`.

## API overview

The frontend uses JSON requests to `?api=suppliers`, `?api=items`, and `?api=orders`. Each supports GET, POST, and DELETE. Purchase orders also support the controlled `action=complete` transition.

PHP validates submitted values and recalculates all PO totals; browser-calculated totals are display-only.

## Storage and testing

Local records are saved in `data/` as JSON files. Missing or empty files safely read as empty collections, and writes use `LOCK_EX`. On Vercel, records are saved to the connected private Blob store instead.

Validate with `php -l index.php` and `node --check assets/app.js`. Test Supplier/Item CRUD, then create Draft and Pending POs, complete a Pending PO, and verify validation errors with invalid values.

## Deployment notes

The project is Vercel-ready:

- `index.html` and `assets/` are served as static files.
- `/api` is a PHP Vercel Function using the `vercel-php` community runtime.
- In Vercel, the API stores records in a private Vercel Blob store. Locally, it continues to use `data/*.json`.

### Deploy to Vercel

1. Import this folder or its GitHub repository into Vercel.
2. In the project’s **Storage** tab, create a **private Blob** store and connect it to the project. Vercel adds `BLOB_READ_WRITE_TOKEN` automatically.
3. Deploy. Open the deployment URL and add a supplier, item, and purchase order to verify that records persist after a refresh.

No token belongs in source control. `php -S localhost:8000` uses the local JSON data files; Vercel automatically provides the Blob token in the deployed project.


## Vercel Blob deployment note

The production API uses Vercel Blob when `VERCEL=1`. The current implementation supports the OIDC-connected Blob setup through `BLOB_STORE_ID` + `VERCEL_OIDC_TOKEN`, with `BLOB_READ_WRITE_TOKEN` kept as a legacy fallback. The existing JSON files under `data/` are used as seed data on the first Vercel deployment; once a record is created or edited, the updated data is stored in the connected Blob store.

The Blob store in this project is configured as **Public**, so the PHP API uses the public Blob hostname for reads and the Blob API for authenticated writes.
