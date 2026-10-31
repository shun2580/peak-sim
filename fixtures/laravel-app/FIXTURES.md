# Fixture app: file ownership

This app is the answer key for peak-sim. Routes are split into four groups so that parallel workers never edit the same file.

- Routes: `routes/ok.php`, `ng.php`, `hidden.php`, `unknown.php` (prefix `/ok` etc.). Each file has one owner: its group.
- Controllers: `app/Http/Controllers/Ok|Ng|Hidden|Unknown/`. Anything else under `app/` goes in a group namespace too (e.g. `app/Ng/Services/`, `app/Hidden/Models/`), never in a shared place.
- Views / resources live under `resources/views/<group>/`. Tests live in `tests/Feature/Ok|Ng|Hidden/`.
- Shared base (do not edit from a group): `app/Models/*`, `database/*`, `bootstrap/app.php`, `phpunit.xml`. A group that needs a model variant (`$with`, `$appends`, ...) subclasses it in its own namespace.
- Never add Docker files here; the dev environment lives in `../../docker/`.
