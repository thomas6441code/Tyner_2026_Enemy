# Add Swagger / OpenAPI docs across all three services

## Goal
Expose interactive Swagger/OpenAPI documentation for every endpoint in `ai-service`,
`bio-service`, and the Laravel `yner_main` REST API.

## Decisions
- **Laravel package:** `dedoc/scramble` (zero-annotation, auto-generates the spec from
  controllers, inline `validate()` rules and return types). Chosen over `darkaonline/l5-swagger`
  to avoid maintaining `@OA` PHPDoc annotations.
- **Laravel scope:** REST/JSON endpoints under `/api` only (`/api/biometric/ingest`,
  `/api/biometric/devices`). The ~26 Inertia web routes return React pages, not JSON, so they are
  intentionally excluded.
- **FastAPI services:** Swagger is built in — no package needed. Work was enriching the existing
  auto-generated docs (titles, descriptions, tags, operation summaries, health response models,
  documented 401).

## What was done

### ai-service (FastAPI)
- `main.py` — added rich `description`, `openapi_tags`, contact/license, explicit
  `docs_url`/`redoc_url`/`openapi_url`.
- `app/routers/health.py` — typed `HealthResponse`, summary/description.
- `app/routers/analysis.py` — per-operation `summary`s and a shared documented `401` for the
  `X-Internal-Secret` guard.

### bio-service (FastAPI)
- `main.py` — same metadata enrichment; description explains the push-oriented poll→ingest flow.
- `app/routers/health.py` — typed `HealthResponse`, summary/description.

### yner_main (Laravel)
- `composer require dedoc/scramble`.
- Published `config/scramble.php`; set API `info.description`, version `1.0.0`, UI title.
- `app/Providers/AppServiceProvider@boot` — `Scramble::configure()` adds a global
  `X-Internal-Secret` apiKey security scheme via `$openApi->secure(...)`.

## Docs URLs
- ai-service:  http://localhost:8001/docs  (ReDoc `/redoc`, spec `/openapi.json`)
- bio-service: http://localhost:8002/docs  (ReDoc `/redoc`, spec `/openapi.json`)
- yner_main:   http://localhost:8000/docs/api  (spec `/docs/api.json`)

## Notes / verification
- Scramble's `docs/api` UI is open in `local`; in other envs it is gated by
  `RestrictedDocsAccess` (`viewApiDocs` gate) — define that gate before exposing docs in prod.
- Verified: `php artisan scramble:export` emits both paths + security scheme; both FastAPI apps
  generate valid OpenAPI; `pytest` 44 passed; docs routes registered in `route:list`.
