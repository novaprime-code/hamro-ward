# Hamro Ward — operations

Addendum to your server operations doc. Everything general — daily checks, disk, certificates, Docker cleanup — stays there. This covers what's specific to Hamro Ward: many databases, a queue that carries a municipality with each job, and content that citizens submit.

**Stack:** `hamroward` — `hamroward-app`, `hamroward-queue`, `hamroward-scheduler`, `hamroward-redis`, `hamroward-web`
**Databases:** `hamroward` (central) + one `hw_t_<8 hex>` per municipality + `template_hamroward`
**Hostnames:** `hw.jayshyampatel.com.np` (public), `admin.hw.jayshyampatel.com.np` (staff)
**Scripts:** `~/stacks/hamroward/deploy.sh`, `~/stacks/hamroward/rollback.sh`, `~/scripts/hamroward-restore-db.sh`

---

## Daily, one minute

```bash
docker exec hamroward-app php artisan hw:doctor
```

Green all the way down means the application, both database roles, Redis, storage and every municipality's schema are fine. Red lines say what to do. Add it to whatever morning check you already run.

Once citizens can report issues (v0.2), add:

```bash
docker exec hamroward-app php artisan hw:tenant:list
```

and glance at the moderation queue in the staff dashboard. Unreviewed reports are the one backlog that hurts people rather than servers.

## Deploying

```bash
cd ~/stacks/hamroward
./deploy.sh                      # latest
./deploy.sh sha-a1b2c3d          # a specific API build
```

The script dumps the central database **and every municipality database**, pulls, restarts, waits for health, runs `hw:doctor`, then checks the public site and the health endpoint through the proxy. If any step fails it puts the previous image tags back automatically and tells you where the dumps are.

Through Portainer instead: **Update the stack** with **Re-pull image** ticked — but then take the dumps yourself first with `~/scripts/backup.sh`.

Where migrations run on boot (staging), the app container also queues a refresh of every cached public page. Where they don't (production), finish with `docker exec <stack>-app php artisan hw:revalidate`. If pages look stale after a deploy, that is the command to run; if it reports a 401 or 503, `REVALIDATE_SECRET` differs between the app and web containers or is still the placeholder.

## Rolling back

```bash
cd ~/stacks/hamroward
./rollback.sh sha-a1b2c3d
```

**The schema is not rolled back.** Migrations are written so the previous release still works against the new schema — add columns first, remove them a release later. When that isn't true for a particular release, also restore the matching dump:

```bash
~/scripts/hamroward-restore-db.sh ~/backups/predeploy-<stamp>/hamroward.dump hamroward
```

## Restoring one municipality

The reason each municipality has its own database: a bad import or a mistaken bulk edit in one place is undone without touching anywhere else.

```bash
ls ~/backups | grep hw_t_
~/scripts/hamroward-restore-db.sh ~/backups/pg-hw_t_9c1e4a77_20260922.sql.gz hw_t_9c1e4a77
```

The script dumps the current contents first, stops the application containers, drops and recreates the database, restores, then starts everything and runs `hw:tenant:migrate` so the restored database catches up to the current schema.

## Onboarding a municipality

```bash
docker exec -it hamroward-app php artisan hw:tenant:create koshi/sunsari/<slug>
```

It asks for the `hw_provisioner` password, which is deliberately not in the stack's environment. This creates and prepares the database but **publishes nothing** — the municipality appears publicly only after its units are published, which happens after the data is checked by a second person.

## The first operator admin

Staff are invite-only, so the first account is made on the server:

```sh
docker exec -it <stack>-app php artisan hw:staff:create you@example.org --operator-admin
```

It asks for the password. The first sign-in, on the admin host, forces
two-factor enrolment and shows eight recovery codes once: keep them somewhere
that is not the phone. A locked account (five failures) unlocks by itself
after fifteen minutes.

## Taking a municipality offline

```bash
docker exec hamroward-app php artisan hw:tenant:status 9c1e4a77 suspended
docker exec hamroward-app php artisan hw:tenant:status 9c1e4a77 active
```

For a legal complaint, a data incident, or a municipality that asks. Other municipalities are unaffected. If the problem is moderation capacity rather than data, pause new reports instead — the kill switch in the staff settings (v0.2) — and leave existing pages readable.

---

## Things that can go wrong

### `hw:doctor` says a municipality is behind, or in maintenance

A tenant migration failed during a deploy. The container still started, on purpose: one broken municipality shouldn't take the site down.

```bash
docker exec hamroward-app php artisan hw:tenant:migrate
docker logs --tail 80 hamroward-app | grep -i tenant
```

If the same municipality keeps failing, restore its database from last night and migrate again. Its pages show a maintenance notice in the meantime; everything else works.

### Queue round trip fails in `hw:doctor --deep`

Jobs aren't being processed. In order:

```bash
docker logs --tail 50 hamroward-queue
docker exec hamroward-redis redis-cli ping
docker restart hamroward-queue
```

While the worker is down, issue reports are still accepted — photos just stay unprocessed and moderators see them late.

### A job fails with "Tenant … does not exist"

A job outlived its municipality — usually after a restore or an archive. Clear the failed jobs after checking what they were:

```bash
docker exec hamroward-app php artisan queue:failed
docker exec hamroward-app php artisan queue:forget <id>
```

### Central database is fine, a municipality database is missing

Someone dropped it, or a restore went sideways. The registry row still exists:

```bash
docker exec hamroward-app php artisan hw:tenant:list
docker exec postgres psql -U postgres -c "\l" | grep hw_t_
```

Restore from a dump. **Don't** delete the registry row to "clean up" — the municipality's issues, sources and audit trail live in that database, and the row is how they're found again.

### 502 from the proxy

```bash
docker ps --filter name=hamroward
docker logs --tail 50 hamroward-web
docker exec hamroward-app curl -sS -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/up
```

If the API answers but the site doesn't, it's the Next.js container; if neither answers, check the proxy is still attached to the `web` network.

### Photo uploads fail at around 1 MB (v0.2)

The proxy is rejecting them before Laravel sees them. Add to both proxy hosts, Advanced tab:

```
client_max_body_size 12m;
```

### Disk filling up

Uploaded photos live in the `hamroward_storage` volume and grow with use.

```bash
docker system df -v | grep hamroward
docker exec hamroward-app du -sh /var/www/html/storage/app
```

Originals are deleted after processing by design, so growth should be slow. When it isn't, that's the signal to move uploads to S3 or R2 — a change of environment variables, not code.

---

## What is never done by hand

* **Never `DROP DATABASE` a municipality** unless it has been archived through the application. Its audit trail is the record of who changed what.
* **Never edit `source_links` or `audit_events` directly.** They're append-only by grant; a direct edit as `postgres` bypasses the guarantee the whole platform rests on.
* **Never publish a municipality by setting `is_published` in SQL.** Use the application, so the check that every level above it is published still runs.
* **Never put the provisioner password into the stack's environment.** Creating and dropping databases is a deliberate command, not something a web container can do.

## Backups

Your nightly script needs no changes: it loops over every database on the server, so the central and municipality databases are dumped individually. What to confirm occasionally:

```bash
ls ~/backups | grep -E "hamroward|hw_t_" | tail -10
```

Once a month, restore the central database into a throwaway name and count rows, as the restore drill in the project docs requires. A backup nobody has restored isn't a backup yet.
