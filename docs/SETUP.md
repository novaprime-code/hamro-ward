# Server setup

Standing up a Hamro Ward environment on a server that already runs Docker,
Portainer, Nginx Proxy Manager and a shared PostgreSQL. Written from the staging
build; production is the same document with different names.

Order matters throughout. Each step says what success looks like, because the
failure modes here are quiet ones.

---

## 0. What has to exist first

| | |
|---|---|
| Docker + Portainer | Community Edition is fine |
| A reverse proxy | Nginx Proxy Manager, with certificates |
| Shared PostgreSQL 17 | the image with PostGIS **and** pgvector — `infra/postgres-image/` |
| Two networks | `db` and `web`, both external, both already created |
| GHCR credentials in Portainer | the images are private |

The PostgreSQL image is built by `.github/workflows/postgres-image.yml` and
pinned by a dated tag on the server, so a later rebuild never restarts your
database unexpectedly.

---

## 1. Roles, template and the first central database — once per server

```bash
cp infra/postgres/create-hamroward-db.sh  ~/scripts/
cp infra/postgres/verify-hamroward-db.sh  ~/scripts/
chmod +x ~/scripts/create-hamroward-db.sh ~/scripts/verify-hamroward-db.sh

~/scripts/create-hamroward-db.sh
~/scripts/verify-hamroward-db.sh
```

It prints three passwords **once** — `hw_owner`, `hw_app`, `hw_provisioner`.
Put them straight into a password manager; every one of them is needed below,
and the provisioner's is the one people forget.

What it creates:

| Object | Purpose |
|---|---|
| `hw_owner` | owns schemas, runs migrations |
| `hw_app` | application access at runtime |
| `hw_provisioner` | `CREATEDB` only; creates and drops municipality databases |
| `hamroward` | the first central database |
| `template_hamroward` | template with PostGIS, `pg_trgm`, `btree_gist`, `citext` and the grants |

Every municipality database is a copy of that template, which is why the
provisioner never needs superuser rights.

Expect `ok` on every line of the verify script, ending in "All checks passed."

---

## 2. A central database per environment

`create-hamroward-db.sh` runs once and makes **one** central database. Each
further environment needs its own:

```bash
cp infra/postgres/create-central-database.sh ~/scripts/
chmod +x ~/scripts/create-central-database.sh

~/scripts/create-central-database.sh hamroward_staging
# later:
~/scripts/create-central-database.sh hamroward_production
```

It copies `template_hamroward` — which is how the new database gets the
extensions and the default privileges that let `hw_app` write tables `hw_owner`
creates — then revokes `PUBLIC` and grants `CONNECT` explicitly.

**That revoke is not optional.** A database-level ACL is *not* copied from a
template, so a fresh database is readable by every role on the server,
including the one n8n connects with.

Nine `ok` lines and "All checks passed." Safe to run twice.

---

## 3. The stack

**Portainer → Stacks → Add stack**, named `hamroward-staging`. Paste
`infra/stacks/hamroward-staging/docker-compose.yml`, then paste the environment
from `infra/stacks/hamroward-staging/.env.example` and fill in every
`CHANGE_ME`.

The variables that are easy to get wrong:

| Variable | Note |
|---|---|
| `APP_ENV` | `staging` — this is what lets `hw:demo:seed` run here and refuse on production |
| `DB_DATABASE` | must match the database created in §2 |
| `TENANT_DB_PREFIX` | `hw_st_` on staging, different from production on purpose: tenant database names are random, and without separate prefixes you cannot tell from `\l` which environment a database belongs to |
| `TENANT_PROVISIONER_PASSWORD` | the third password from §1. Without it tenant creation fails with `fe_sendauth: no password supplied` |
| `HW_MIGRATE_ON_BOOT` | `true` on staging, `false` on production |
| `MAILPIT_UI_AUTH` | required, not optional — that inbox holds every password-reset link the system issues |
| `HW_THEME` | `himal` |

Deploy, then point the proxy at `hamroward-staging-web` (port 3000) and, if the
admin host is in use, `hamroward-staging-app` (port 8080).

---

## 4. First boot

```bash
APP=hamroward-staging-app

docker logs -f "$APP"
```

With `HW_MIGRATE_ON_BOOT=true` the entrypoint runs the central migrations, then
tenant migrations, then the reference sync. You want `[hamroward] central
migrations` followed by a table of `Ran` lines.

```bash
docker exec "$APP" php artisan migrate:status \
  --database=central_owner --path=database/migrations/central
```

---

## 5. Data

```bash
docker exec "$APP" php artisan db:seed --force      # source types + positions
docker exec -it "$APP" php artisan hw:demo:seed     # staging only
```

`db:seed` is reference data and belongs in every environment. `hw:demo:seed`
creates four invented municipalities with 63 wards and their tenant databases —
staging only, and the guard enforces it.

Confirm:

```bash
docker exec postgres psql -U postgres -At -c \
  "SELECT datname FROM pg_database WHERE datname LIKE 'hw_st_%' ORDER BY 1;"

docker exec "$APP" php -r \
  "echo substr(file_get_contents('http://127.0.0.1:8080/api/v1/local-levels'),0,400), PHP_EOL;"
```

Four databases, and JSON with four entries. If the API is right and a page is
blank, the problem is the web container — a different fix, and you know it
immediately.

```bash
docker restart hamroward-staging-web
```

Responses are cached at the fetch layer (300s picker, 120s municipality, 60s
ward), so a page hit before seeding stays empty for up to five minutes. The
restart clears it.

---

## 6. Deploying a change

```
push to staging  →  GitHub Actions builds  →  ghcr.io/…:staging + :sha-<commit>
```

Then **Portainer → Stacks → `hamroward-staging` → Update the stack**, with
**Re-pull image** ticked. The pipeline deliberately stops at building (`D-016`).

Rolling back is the same motion with a `sha-` tag from the job summary pasted
into `HAMROWARD_API_IMAGE` / `HAMROWARD_WEB_IMAGE`.

---

## 7. Production, when the host exists

Same document, with:

* `~/scripts/create-central-database.sh hamroward_production`
* `infra/stacks/hamroward-production/`
* `APP_ENV=production`, `HW_MIGRATE_ON_BOOT=false`, `TENANT_DB_PREFIX=hw_pr_`
* migrations as a deliberate step before rolling the app, not on boot:

```bash
docker exec hamroward-prod-app php artisan migrate --force \
    --database=central_owner --path=database/migrations/central
docker exec hamroward-prod-app php artisan hw:tenant:migrate
docker exec hamroward-prod-app php artisan hw:tenant:sync-reference
```

Both stacks can share one host: container names are literal and distinct, and
internal routing uses Compose service names, which are scoped per stack.

---

## 8. Troubleshooting

Each of these was hit for real during the staging build.

| Symptom | Cause | Fix |
|---|---|---|
| `env file /data/compose/8/.env not found` | Portainer substitutes variables into the compose file; it does not write a `.env` beside it | no `env_file:` — name every variable in an `environment:` block |
| `database "hamroward_staging" does not exist` | each environment needs its own central database | §2 |
| `fe_sendauth: no password supplied` (connection `provisioner`) | `TENANT_PROVISIONER_PASSWORD` unset | §3 |
| `permission denied to create database` | the provisioning connection points at a role without `CREATEDB` | `SELECT rolname, rolcreatedb FROM pg_roles WHERE rolname LIKE 'hw%';` |
| `COPY … not found` in the API image build | wrong build context | API builds from `apps/api`, web from the repository root |
| Stack deploys, pages 404 from the API | routes live in `routes/api_v1.php`; `routes/api.php` is not loaded | `php artisan route:list --path=api/v1` should show four routes |
| Tenant stuck in `maintenance` with no database | a failed `CreateTenant` keeps the row on purpose, so the evidence survives | inspect, then `DELETE FROM tenants WHERE status='maintenance';` and re-run the seed |
| Page shows stale or empty content | fetch-layer cache | `docker restart <stack>-web` |

### A clean-slate rebuild

Worth doing once while there is nothing to lose, because it is the only way to
find out whether the whole sequence works from zero:

```bash
docker stop hamroward-staging-{app,queue,scheduler}

docker exec postgres psql -U postgres -c 'DROP DATABASE "hw_st_xxxxxxxx" WITH (FORCE);'
docker exec postgres psql -U postgres -c 'DROP DATABASE hamroward_staging WITH (FORCE);'

~/scripts/create-central-database.sh hamroward_staging
docker start hamroward-staging-{app,queue,scheduler}
docker logs -f hamroward-staging-app

docker exec "$APP" php artisan db:seed --force
docker exec -it "$APP" php artisan hw:demo:seed --force
```

Substitute the real `hw_st_…` names from `\l`. About four minutes, and a clean
pass is the evidence that the production setup will be boring.

---

## 9. Backups

The existing nightly script loops over every database on the server, so
municipality databases are included without any change as they appear. Verify
after the first seed:

```bash
~/scripts/backup.sh
ls ~/backups | grep -E "hamroward|hw_st_"
```

A backup nobody has restored is a hypothesis. Restore one municipality database
into a scratch name and open it before treating this as done.
