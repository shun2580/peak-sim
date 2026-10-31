# Fixture app: file ownership

This app is the answer key for peak-sim. Routes are split into four groups so that parallel workers never edit the same file.

- Routes: `routes/ok.php`, `ng.php`, `hidden.php`, `unknown.php` (prefix `/ok` etc.). Each file has one owner: its group.
- Controllers: `app/Http/Controllers/Ok|Ng|Hidden|Unknown/`. Anything else under `app/` goes in a group namespace too (e.g. `app/Ng/Services/`, `app/Hidden/Models/`), never in a shared place.
- Views / resources live under `resources/views/<group>/`. Tests live in `tests/Feature/Ok|Ng|Hidden/`.
- Shared base (do not edit from a group): `app/Models/*`, `database/*`, `bootstrap/app.php`, `phpunit.xml`. A group that needs a model variant (`$with`, `$appends`, ...) subclasses it in its own namespace.
- `/ng` routes are split over two files that `routes/ng.php` requires (inside its prefix): `routes/ng_basic.php` (worker 2) and `routes/ng_deep.php` (worker 3). `ng.php` itself is not edited after the plumbing commit.
- Service providers are listed in `bootstrap/providers.php` (edited only by the plumbing commit): `App\Ng\Providers\LayeredServiceProvider` belongs to worker 3, `App\Hidden\Providers\ConditionalBindingServiceProvider` to worker 4. Each owner fills in their own stub.
- `tests/Support/MeasuresMemory.php` is a shared test helper (peak memory per row count); use it, do not edit it.
- Never add Docker files here; the dev environment lives in `../../docker/`.
