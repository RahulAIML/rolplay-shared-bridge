# Rolplay Multi-Tenant Analytics Bridge

One PHP container serving multiple tenants' analytics bridges. Each tenant's
query logic is ported **verbatim** from its original single-tenant bridge —
this repo only centralizes credential management (env vars instead of
hardcoded constants) and deployment (one image instead of one per tenant).

## Architecture

```
Browser (React)
    │  fetch /sanfer/bridge/?action=cert.stats   (unchanged URL)
    ▼
nginx  (location /sanfer/bridge/ → proxy_set_header X-Tenant sanfer)
    ▼
index.php  (front controller)
    │  resolves tenant from X-Tenant header
    │  defines that tenant's DB_* constants from env vars
    │  requires tenants/sanfer.php
    ▼
tenants/sanfer.php   (original business logic, unchanged)
    ▼
MySQL (3 DBs: roleplay_demorp6, rolplay_sanfer_robin, rolePlay_sanfer_v3)
```

Apotex works identically via `/apotex/bridge/` → `X-Tenant: apotex` →
`tenants/apotex.php` → 2 DBs (`rolplay_apotex_robin`, `roleplay_demorp6`).

## Adding a new tenant

1. Drop their existing bridge file into `tenants/<name>.php`.
2. Remove their hardcoded DB credential `define()`s — keep every query,
   caching, and business-rule line unchanged.
3. Add an entry to the `TENANTS` array in `index.php` mapping constant names
   to `<NAME>_*` env vars.
4. Add the env vars to `.env.example` and set them on the container.
5. Add an nginx `location` block with `proxy_set_header X-Tenant <name>;`.

No new container, no new PHP file structure — same deployable image.

## Deploy

```bash
docker build -t rolplay_bridge:latest .
docker run -d --name rolplay_bridge_container \
  --env-file .env \
  rolplay_bridge:latest
```

Then point the existing nginx `/sanfer/bridge/` and `/apotex/bridge/`
locations at this one container (adding the `X-Tenant` header per location)
instead of the two separate `sanfer_bridge_container` / `apotex_bridge_container`.

## Local testing

```bash
docker build -t rolplay_bridge:test .
docker run -d --name rolplay_bridge_test -p 8091:80 --env-file .env rolplay_bridge:test
curl -X POST http://localhost:8091/ -H 'X-Tenant: sanfer' -d '{"action":"ping"}'
curl -X POST http://localhost:8091/ -H 'X-Tenant: apotex' -d '{"action":"ping"}'
```
