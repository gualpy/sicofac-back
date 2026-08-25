# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

SICOFAC: multi-tenant Laravel 12 API for Ecuadorian electronic invoicing (facturación electrónica SRI). This is the `back/` half of the `sicofac/` monorepo layout — `front/` (sibling directory) is a separate React project with its own git repo consuming this API over Sanctum bearer tokens. It is a rewrite of a legacy Java desktop app (HSQLDB-backed) — see `docs/mapeo-java-laravel-livewire.md` for the full legacy-to-Laravel data/process mapping and `docs/backup-restore.md` for backup/restore procedures. The legacy desktop artifact itself (`ComprobantesElectronicosOffline/`, `comprobantesElectronicosOffline.jar`, `.desktop` launchers) lives one level up at the `sicofac/` root, outside this git repo — kept for reference/offline use, not part of the Laravel app.

## Commands

```bash
composer setup          # install deps, copy .env, key:generate, migrate, npm install+build
composer dev             # run serve + queue:listen + pail (logs) + vite concurrently
composer test            # config:clear then php artisan test
php artisan test --filter=TestName   # run a single test
php artisan test tests/Feature/ProductImportTest.php  # run a single file
vendor/bin/pint          # code style (Laravel Pint)
```

Tests run against in-memory sqlite with sync queue (see `phpunit.xml`), so queued jobs execute inline during tests unless a test explicitly fakes the queue.

## Architecture

### Tenancy and authorization
Everything is scoped to a `Company` (tenant). Users relate to companies via a `company_user` pivot carrying `role` (`owner`/`admin`/`seller`, `App\Enums\CompanyMembershipRole`) and `status` (`App\Enums\CompanyMembershipStatus`). Authorization is Policy-based (`app/Policies/*`), and every policy checks membership/role through `User::belongsToCompany()` / `User::hasCompanyRole()` — there is no other tenant-isolation layer, so any new resource controller must go through a policy that re-derives its `company_id` from the model, not trust request input. Routes are nested under `companies/{company}` and use `scopeBindings()` (see `routes/api.php`) so child models must actually belong to the route's company. Auth is Sanctum token-based (`auth:sanctum`); throttle groups `login` (5/min by IP) and `critical` (30/min by user/IP) are defined in `AppServiceProvider::boot()`.

### Invoice emission pipeline (core domain flow)
An invoice moves through `App\Enums\InvoiceStatus` (`draft → processing → xml_built → signed → sent_reception → authorized|rejected|failed`) via a chain of queued jobs in `app/Jobs/Billing/`, each dispatching the next on success:

`InvoiceEmissionService::dispatch()` (locks the invoice row, validates it's `Draft`, computes totals via `InvoiceTotalsCalculator`, flips to `Processing`) → `BuildXmlJob` (uses `XmlBuilderInterface`, writes `generated.xml`, flips to `XmlBuilt`) → `SignXadesJob` (uses `XadesSignerInterface` + active `CompanyCertificate`, writes `signed.xml`, flips to `Signed`) → `SendReceptionJob` (uses `SriClientInterface::sendToReception`, flips to `SentReception` or `Rejected`) → `CheckAuthorizationJob` (uses `SriClientInterface::checkAuthorization`, flips to `Authorized` or `Rejected`).

Every job re-checks the invoice is still in the expected status before acting (idempotency guard against retries/races) and routes failures through `InvoiceStateService::setStatus()`, which also timestamps the transition and appends an `InvoiceEvent`. Generated/signed/authorized XML paths are tracked per-invoice on `InvoiceDocument`, stored under `invoices/{company_id}/{invoice_id}/` on the `local` disk.

### SRI integration is swappable via contracts
`app/Integrations/Sri/Contracts/{XmlBuilderInterface,XadesSignerInterface,SriClientInterface}` are bound in `AppServiceProvider::register()`. `XmlBuilderInterface`/`XadesSignerInterface` are hard-bound to `Dummy` implementations; `SriClientInterface` switches between `Dummy` and `Real` based on `config('sri.driver')` (env `SRI_DRIVER`, default `dummy`). When working on SRI integration, real behavior only shows up when `SRI_DRIVER=real`, and `Real*` implementations under `app/Integrations/Sri/Real/` are where actual SOAP/XAdES logic belongs — the rest of the codebase should only ever depend on the interfaces.

### Products import/export
Bulk product import: `ProductImportService::startImport()` stores the uploaded file, creates a `ProductImport` record (status tracking + `valid_count`/`invalid_count`/`errors_json`), and dispatches `ImportProductsJob`, which runs `App\Imports\ProductsImport` (maatwebsite/excel) and reports row-level results back onto the `ProductImport` record. Export goes through `App\Exports\CompanyProductsExport` / `ProductExportService`.

### Cross-cutting
- `AuditLogger` (`app/Services/Audit/AuditLogger.php`) records before/after diffs for sensitive mutations (e.g. invoice issuance) into `AuditLog`.
- `RequestContextMiddleware` attaches a `request_id` (from `X-Request-Id` header or generated) and `company_id` to the log context for every request, and echoes `X-Request-Id` back in the response — useful for correlating logs across the async job chain.
- DTOs (`app/DTOs/**`) are plain readonly-style data objects used to pass structured results between services/jobs without leaking Eloquent models into responses.
