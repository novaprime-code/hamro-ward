# Import templates

Header rows for `hw:import` (`docs/05` §13). Copy the ones you need, fill them
in, export as **CSV UTF-8**, and keep the header row exactly as it is — same
names, same order. A file with any other header is refused.

These files hold headers only. Never commit real data here.

## Workflow

```bash
# 1. Geography, on its own (central only)
php artisan hw:import ./sheet-geo --dry-run
php artisan hw:import ./sheet-geo

# 2. Onboard the municipality
php artisan hw:tenant:create koshi/sunsari/example

# 3. Its representatives, sources and offices
php artisan hw:import ./sheet-example --tenant=koshi/sunsari/example --dry-run
#    → a second person reads the report in storage/app/imports/ (D-002)
php artisan hw:import ./sheet-example --tenant=koshi/sunsari/example
```

Any file can be left out. Nothing is written unless every row of every file is
accepted, and running the same sheet again changes nothing.

## Rules that are easy to get wrong

* **Dates are AD, `YYYY-MM-DD`.** A BS date goes in `published_as_written`,
  exactly as printed on the document.
* **Paths are slugs from the province down:** `koshi`, `koshi/sunsari`,
  `koshi/sunsari/example`, `koshi/sunsari/example/4`. A province row has an
  empty `parent_path`.
* **`person_ref`, `party_ref` and `source_ref` are permanent and global.** The
  same ref in two municipalities' sheets is the same person. Use something that
  cannot collide, such as `sunsari-example-p012`. The report warns when a
  re-import renames somebody, which is what a reused ref looks like.
* **Where a source lives follows its type.** `local_level` and `ward_office`
  documents belong to the municipality and need `--tenant`. Every other type is
  national, and only national sources can back a person, party or place.
* **Citing is not verifying.** `source_ref` and `field_sources` attach a source;
  the seat still reads "not yet verified" until `verifications.csv` names two
  different people who checked it.
* **Nothing about a place goes public through an import.** Publishing a
  municipality is a separate operator step. People and parties are published
  once a verified source backs the record.

## `field_sources` (office_holdings.csv)

Which source backs which field, when it is not the row's own `source_ref`:

```
party_ref:src-ecn-2022;end_date:src-notice-19
```

Fields that take their own source: `party_ref`, `is_independent`, `start_date`,
`end_date`, `end_reason`, `term_label`.

## `subject_ref` (verifications.csv)

| Record | Form |
|---|---|
| person | `person:{person_ref}` |
| party | `party:{party_ref}` |
| place | `admin_unit:{path}` |
| ward office | `ward_office:{ward path}` |
| office holding | `office_holding:{person_ref}\|{position_key}\|{constituency_path}\|{seat_index}\|{start_date}` |
| vacancy | `vacancy:{position_key}\|{constituency_path}\|{seat_index}\|{vacant_from}` |

`field` is empty to verify the whole record, or one of the `field_sources`
names to verify that field's source. `verified_by_name` and `reviewed_by_name`
must be two different people.
