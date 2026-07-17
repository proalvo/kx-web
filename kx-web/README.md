# KX-Web — endpoint skeleton

Public results website for Kayak Cross competitions (companion to KX-Results).
Dependency-free PHP 8.1+ / PDO_MySQL — no composer, no Node. Runs on basic shared hosting.

## Layout
```
public/           document root (point vhost/subdomain here)
  index.php       front controller + route table
  .htaccess       rewrite everything to index.php, pass Authorization header
src/
  autoload.php    minimal PSR-4 autoloader (KxWeb\)
  Http.php        Request / Response / HttpException
  Router.php      tiny router with {param} placeholders
  Db.php          PDO singleton + UUIDv4 generator
  Controller/     SyncController, PublicApiController, PageController, BaseController
  Model/          CompetitionModel, EventModel, PhaseModel, SyncLogModel(+RateLimitModel)
config/
  config.php      defaults; copy to config.local.php on the server (DB credentials etc.)
sql/schema.sql    MariaDB schema
bin/create-competition.php   CLI helper: creates org+competition, prints API key once
```

## Install (shared hosting)
1. Upload files; point the (sub)domain document root to `public/`
   (or upload everything and adjust the rewrite in `.htaccess`).
2. Create a MariaDB database and import `sql/schema.sql`.
3. Create `config/config.local.php`:
   ```php
   <?php return ['db' => ['dsn' => 'mysql:host=localhost;dbname=XXX;charset=utf8mb4',
                          'user' => 'XXX', 'password' => 'XXX']];
   ```
4. Register the organization ONCE and get its provisioning key:
   `php bin/create-organization.php "Melonta- ja soutuliitto" FIN office@example.fi`
5. Enter the printed ORGANIZATION key into kx-server settings.
   From now on kx-server creates competitions itself
   (`POST /api/v1/competitions`) and receives each competition's
   API key automatically — no website admin needed per competition.

## Smoke test
```bash
# push events
curl -X POST https://results.example.fi/api/v1/competition \
  -H "Authorization: Bearer <api_key>" -H "Content-Type: application/json" \
  -d '{"competition_id":"<uuid>","name":"SM Koskicross 2026","country":"FIN",
       "start_date":"2026-08-01","end_date":"2026-08-02",
       "events":[{"event_id":"<uuid>","event_code":"K1M","event_name":"Kayak Cross Men","gates":4}]}'

# push a phase snapshot
curl -X POST https://results.example.fi/api/v1/phase \
  -H "Authorization: Bearer <api_key>" -H "Content-Type: application/json" \
  -d '{"event_code":"K1M","phase":"QUALIFICATION","status":"live",
       "entries":[{"grp":1,"slot_no":1,"bib":12,"first_name":"Matti","last_name":"Meikäläinen",
                   "club":"Koskimelojat","country":"FIN","gates":[0,0,null,null]}]}'

# read it back
curl https://results.example.fi/api/v1/public/competitions/sm-koskicross-2026/events/K1M/phases/QUALIFICATION
```

## Key formats
Two key levels, both `Bearer` tokens, both stored hashed, both shown once:
- Organization key `org.{org_id}.{secret}` — issued once per organization
  (club/federation); authorizes creating competitions.
- Competition key `{competition_id}.{secret}` — returned by
  `POST /api/v1/competitions`; authorizes syncing that one competition.
The id part enables an indexed lookup before the bcrypt verify. If a
competition key is lost it cannot be retrieved — regenerate on the website.

## Install variant B: subdirectory of an existing website

Use this when the hosting's webroot (e.g. `public_html/`) already serves
other content and KX-Web should live at `https://example.fi/kx-results/`.

Layout on the host:
```
/home/USER/
  kx-web-app/                <- NOT web-accessible
    src/
    config/
      config.php
      config.local.php       <- DB credentials + base_path
    sql/schema.sql
    bin/create-competition.php
  public_html/               <- existing site stays untouched
    (existing site files...)
    kx-results/              <- only these two files from public/
      index.php
      .htaccess
```

Steps:
1. Upload `src/`, `config/`, `sql/`, `bin/` to a folder **outside** the
   webroot, e.g. `/home/USER/kx-web-app/`.
2. Copy the *contents* of `public/` (index.php, .htaccess) into
   `public_html/kx-results/`.
3. In `public_html/kx-results/index.php`, set the app root:
   `$appRoot = getenv('KX_APP_ROOT') ?: '/home/USER/kx-web-app';`
   (or set the `KX_APP_ROOT` env var in the hosting panel / .htaccess).
4. In `config/config.local.php` add:
   ```php
   <?php return [
     'base_path' => '/kx-results',
     'db' => [ /* ... */ ],
   ];
   ```
5. In `public_html/kx-results/.htaccess`, uncomment
   `RewriteBase /kx-results`.
6. Import `sql/schema.sql`, create a competition with
   `php bin/create-competition.php ...` — the API base URL for kx-server
   is then `https://example.fi/kx-results`.

The same codebase works for any mount point — only `base_path`,
`RewriteBase`, and `$appRoot` change per site, so one release can be
installed on many different websites.
