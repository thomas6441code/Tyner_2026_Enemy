# Deploying to Coolify

This guide covers deploying EAPMS to a Coolify instance: a Coolify-managed
MySQL database, plus the three app services (`yner_main`, `ai-service`,
`bio-service`) as one Docker Compose resource. Only `yner_main` is exposed
publicly; `ai-service` and `bio-service` are reachable only from `yner_main`
over Coolify's internal Docker network.

## 1. Provision the MySQL database

1. In your Coolify project/environment, **Add Resource → Database → MySQL**
   (version 8.0 to match `docker-compose.yml`'s local dev image).
2. Set a database name, username, and password (or let Coolify generate them).
3. Deploy the database resource.
4. Open its **Configuration** tab and copy:
   - **Internal hostname** (e.g. `mysql-<uuid>` or similar — this is what
     other resources in the same Coolify network reach it by)
   - Database name, username, password, port (3306)

   You'll paste these into the app resource's environment variables in step 3.

## 2. Create the application resource

1. **Add Resource → Docker Compose**, point it at this Git repository.
2. Set the **Compose file** path to `docker-compose.coolify.yml` (not the
   root `docker-compose.yml`, which is for local dev only — it bundles its
   own MySQL container and bind-mounts source for hot reload, neither of
   which you want in production).

## 3. Set environment variables

On the application resource's **Environment Variables** tab, set:

| Variable | Value |
| --- | --- |
| `APP_URL` | `https://tyner.skyportcargo.co.tz` (already the default in `docker-compose.coolify.yml` — only set this if it changes) |
| `APP_KEY` | Generate once locally: `php artisan key:generate --show` (inside `yner_main`). Paste the `base64:...` output. **Do not regenerate this on every deploy** — it decrypts existing sessions/cookies/encrypted columns. |
| `DB_HOST` | Internal hostname from step 1 |
| `DB_PORT` | `3306` |
| `DB_DATABASE` | From step 1 |
| `DB_USERNAME` | From step 1 |
| `DB_PASSWORD` | From step 1 |
| `INTERNAL_API_SECRET` | A long random string (e.g. `openssl rand -hex 32`) — shared secret between `yner_main` and the two Python services |
| `LLM_API_KEY` | Only needed as a fallback for standalone `ai-service` use; normally the Admin sets provider/model/key at runtime from the AI Settings page (`/settings/ai`) once the app is deployed |

| `WEBAUTHN_RP_ID` | The public domain **without scheme or port** — e.g. `tyner.skyportcargo.co.tz`. Defaults to that value in `docker-compose.coolify.yml`; set it only if the domain changes. Passkeys are cryptographically bound to this, so changing it invalidates every registered device and employees must re-register their phones. |

`LLM_PROVIDER`, `LLM_BASE_URL`, `LLM_MODEL`, `POLL_INTERVAL_SECONDS`,
`WEBAUTHN_RP_NAME`, `WEBAUTHN_REQUIRE` have sane defaults baked into
`docker-compose.coolify.yml` — override only if needed.

> Mobile check-in needs HTTPS: `navigator.credentials` does not exist on a plain-HTTP origin.
> Coolify's Let's Encrypt certificate (step 4) is what makes the channel work at all, and
> `WEBAUTHN_RP_ID` must match the domain on that certificate exactly.

## 4. Expose yner_main publicly

On the `yner_main` service within the Compose resource, set its **Domain**
to your public domain/subdomain and the target port to `8000`. Coolify
provisions the Let's Encrypt certificate and proxies HTTPS traffic to the
container automatically. Leave `ai-service` and `bio-service` without a
domain — they stay internal-only.

## 5. Deploy

Trigger a deploy. On container start, `yner_main`'s entrypoint
(`yner_main/docker/entrypoint.sh`) automatically:

- waits for the database to become reachable,
- runs `php artisan migrate --force`,
- rebuilds `config`/`route`/`view` caches,
- then hands off to supervisord (nginx + php-fpm + queue worker + scheduler).

No manual SSH step is required for first deploy or subsequent ones — migrations
run idempotently on every boot.

## 6. Post-deploy

- Visit the domain, confirm the app loads and you can log in with a seeded
  account (if you seeded data) or create your first Admin via `php artisan db:seed`
  through Coolify's terminal/exec feature on the `yner_main` container.
- Configure the LLM provider/model/API key from **AI Settings**
  (`/settings/ai`) as the Admin user — this is stored in the database
  (`ai_settings` table), not in env vars.
- If biometric devices are involved, confirm `bio-service` can reach the
  physical device network from wherever Coolify's host/agent runs.

## Notes on persistence

- `yner_main_storage` is a named volume mounted at
  `/var/www/html/storage/app` so uploaded files (e.g. permission-request
  attachments) survive redeploys. Logs and framework caches are intentionally
  left ephemeral (regenerated on each boot).
- The MySQL database's own persistence/backups are managed by Coolify's
  Database resource (see Coolify's backup schedule settings on that resource).

## Local dev is unaffected

`docker-compose.yml` at the repo root (bundled MySQL, bind-mounted source,
host port `3306`/`8000` exposed) is untouched and still used for
`docker compose up --build` locally. `docker-compose.coolify.yml` is only
used by Coolify.
