# Vendored front-end libraries

Third-party CSS/JS served from this application's own origin instead of public
CDNs. Files are **copied verbatim** from the CDN at the pinned version — same
bytes, already minified. Nothing is bundled or rebuilt, so there is no build
step to run and nothing to break on deploy.

## Why these are not on a CDN

This is an internal tool used over the office network. Loading the entire UI
toolkit from five external hosts meant:

- the interface depended on working internet, even though the app itself does not;
- every cold visit paid a DNS lookup + TCP connect + TLS handshake **per host**
  (`code.jquery.com`, `cdn.jsdelivr.net`, `cdn.datatables.net`, `js.pusher.com`);
- shared-CDN caching no longer helps — browsers have partitioned their HTTP cache
  per top-level site since 2020, so a visitor never arrives with these already cached.

## Why not the Vite build

Bundling these through Vite would produce the same bytes (they are already
minified) while adding a hard build-step dependency: a deploy that forgets
`npm run build` would leave every page without JavaScript. The measured win here
is *fewer origins*, and serving the files from `public/` achieves that with
nothing to build.

Application code — `public/css/shell.css`, `public/js/shell-*.js` — is likewise
plain static files, versioned by modified time via `App\Support\ShellAsset`.

## Versions

| Library | Version | Files |
|---|---|---|
| jQuery | 3.7.1 | `js/jquery.min.js` |
| Bootstrap | 5.3.3 | `js/bootstrap.bundle.min.js`, `css/bootstrap.min.css` |
| Bootstrap Icons | 1.11.3 | `css/bootstrap-icons.css`, `fonts/*` |
| DataTables | 1.13.8 | `js/jquery.dataTables.min.js`, `js/dataTables.bootstrap5.min.js`, `css/dataTables.bootstrap5.min.css` |
| SweetAlert2 | 11 | `js/sweetalert2.min.js`, `css/sweetalert2.min.css` |
| Select2 | 4.1.0-rc.0 | `js/select2.min.js`, `css/select2.min.css` |
| Select2 BS5 theme | 1.3.0 | `css/select2-bootstrap-5-theme.min.css` |
| Pusher JS | 8.4.0 | `js/pusher.min.js` |
| Laravel Echo | 1.16.1 | `js/echo.iife.js` |
| Chart.js | 4.4.2 | `js/chart.umd.min.js` — loaded only by the three pages that draw charts |

`css/bootstrap-icons.css` has had its `./fonts/` URLs rewritten to `../fonts/`,
since it lives in `css/` here rather than beside the fonts.

## Upgrading

Download the new version to the same filename and hard-refresh once. The cache
key is the file's modified time, so the URL changes automatically.

Google Fonts (Inter) is still loaded externally — it is two small requests with
`preconnect` and `display=swap` already in place, and self-hosting it would mean
vendoring the font binaries for every weight.
