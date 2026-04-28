# Local Docker Setup — SMS Gateway Core

This document covers the local Docker setup for `sms-gateway-core` on a MacBook with Docker Desktop. This is not the NUC/server deployment. The web app is bound to `127.0.0.1`, so it is reachable only from the local machine by default.

Do not use this compose file as the NUC deployment file unless you intentionally change the port bindings and environment for that server. The NUC should keep its own Docker setup.

For local end-to-end testing with the Mac ChatApp stack, this gateway joins the external Docker network `smschatapp_mac_smschatapp_mac_net` and relays inbound SMS to:

```text
http://smschatapp_mac_app:8000/api/infotxt/inbox
```

Override `CHAT_APP_INBOUND_URL` if your ChatApp container name or network differs.

The default local relay credentials are:

```env
CHAT_APP_TENANT_KEY=669
CHAT_APP_INBOUND_SECRET=postman-dev-secret
```

Inbound relays are sent as `application/x-www-form-urlencoded` with `ID`, `MOBILE`, `SMS`, `RECEIVED`, and `TENANT_KEY`. The gateway also sends:

```text
X-Gateway-Timestamp=<unix timestamp>
X-Gateway-Signature=HMAC_SHA256(timestamp + "." + raw_form_body, CHAT_APP_INBOUND_SECRET)
```

---

## Services

| Service name   | Role                        | Exposed port              |
|----------------|-----------------------------|---------------------------|
| `sms-app`      | Laravel app (PHP built-in server) | `127.0.0.1:8081->8000/tcp` |
| `sms-db`       | MySQL 8.0                   | `127.0.0.1:3307->3306/tcp` (localhost only) |
| `sms-redis`    | Redis 7                     | internal only (`6379/tcp`) |
| `sms-scheduler`| Laravel scheduler           | internal only             |
| `sms-worker`   | Laravel queue worker        | internal only             |

Containers reach each other by **service name**, not `127.0.0.1`. `sms-app` connects to MySQL as `sms-db:3306` and to Redis as `sms-redis:6379`.

---

## Start The Local Stack

From the repository root:

```bash
docker compose up -d --build
docker compose exec sms-app php artisan migrate --seed
```

Open:

```text
http://127.0.0.1:8081
```

Stop the app without deleting local Docker data:

```bash
docker compose down
```

Reset the local Docker database and Redis data:

```bash
docker compose down -v
docker compose up -d --build
docker compose exec sms-app php artisan migrate --seed
```

---

## Environment Requirements

The compose file injects the container-safe values into Laravel. Inside Docker these values must resolve to Docker service names:

```env
DB_HOST=sms-db
DB_PORT=3306

REDIS_HOST=sms-redis
REDIS_PORT=6379
```

**Never use `127.0.0.1` or `localhost` for these.** Those resolve to the container itself, not the sibling services.

### Quick check inside Docker
```bash
docker compose exec sms-app php artisan tinker --execute="dump([
    'db' => config('database.connections.mysql.host'),
    'redis' => config('database.redis.default.host'),
]);"
```

Expected values are `sms-db` and `sms-redis`.

For host-side Artisan commands on macOS, use the exposed MySQL port: `127.0.0.1:3307`.

The app container intentionally runs `php -S 0.0.0.0:8000 -t public server.php` instead of `php artisan serve`. Laravel's `serve` command can pass values from the root `.env` into the web server process, which is not what we want for Docker when `.env` is configured for host-side commands.

### Python Runtime Wiring (Mac Local)

For local ChatApp-to-Gateway status and outbound flow, `sms-app`/`sms-worker` must reach the Python engine and use the same shared token:

```env
SMS_PYTHON_API_URL=http://host.docker.internal:9000
SMS_PYTHON_API_TOKEN=<same token configured in python-sms-engine>
```

Important:
- `SMS_PYTHON_API_URL` and `SMS_PYTHON_API_TOKEN` must be injected into Docker services (app/worker/scheduler/supervisor).  
- After editing `.env`, recreate services so env is reloaded:

```bash
docker compose up -d --force-recreate sms-app sms-worker sms-scheduler sms-sim-supervisor
```

Quick validation from container:

```bash
docker compose exec -T sms-app sh -lc 'curl -i -m 5 -sS "$SMS_PYTHON_API_URL/health" | head -n 8'
```

Expected:
- `HTTP/1.1 200 OK`
- JSON body with `"service":"python_sms_engine"` and `"status":"ok"`

If this fails, outbound rows can remain `queued` and ChatApp status polling will continue to return `status=0` for affected `smsid`.

---

## Port Binding

`sms-app` is intentionally bound to localhost only:

```yaml
ports:
  - "127.0.0.1:8081:8000"
```

Use `http://127.0.0.1:8081` or `http://localhost:8081` from the MacBook browser.

---

## Verifying the Stack

### 1. Check all containers are up
```bash
docker compose ps
```
All services should show `Up`; MySQL and Redis should show healthy.

### 2. Verify Laravel can connect to MySQL
```bash
docker compose exec sms-app php artisan migrate:status
```

### 3. Smoke-test the API
```bash
curl -i http://127.0.0.1:8081/api/sims \
  -H "X-API-KEY: <key>" \
  -H "X-API-SECRET: <plaintext-secret>"
```
Expected: `HTTP/1.1 200 OK` with JSON.

---

## Bootstrap API Client

The database is seeded with a bootstrap API client for initial testing. If you need to reset its secret:

```bash
docker compose exec sms-app php artisan tinker --execute="
\$client = \App\Models\ApiClient::where('api_key', 'bootstrap-api-key')->first();
\$secret = \Illuminate\Support\Str::random(40);
\$client->api_secret = bcrypt(\$secret);
\$client->save();
echo \$secret;
"
```

The printed value is the **plaintext secret** — use it in `X-API-SECRET`. The hash stored in the DB cannot be used directly as the header value.

---

## Common 500 Errors and Fixes

| Error message in log | Root cause | Fix |
|----------------------|------------|-----|
| `PDOException: mysql:host=127.0.0.1 Connection refused` inside container | Container config is not using Compose environment | Run `docker compose exec sms-app php artisan config:clear`, then restart |
| `RedisException: Connection refused` inside container | Container config is not using Compose environment | Run `docker compose exec sms-app php artisan config:clear`, then restart |
| `401 {"ok":false,"error":"unauthorized"}` | Passing the bcrypt hash as `X-API-SECRET` instead of the plaintext | Generate a new secret with tinker (see above) |
| Can't reach `127.0.0.1:8081` from browser | App container is not running or port 8081 is already taken | Run `docker compose ps` and `docker compose logs sms-app` |

---

## Debug Log Route

A temporary browser-readable log viewer is available **in local environment only**:

```
GET /_debug/log
```

This route is guarded by `app()->isLocal()` and must be removed before any non-local deployment. It lives in `routes/web.php`.
