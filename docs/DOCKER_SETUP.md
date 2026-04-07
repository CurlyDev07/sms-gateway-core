# Docker Setup — SMS Gateway Core

This document covers the Docker deployment for `sms-gateway-core` running on **MadellaServer** (static LAN IP: `192.168.1.100`). Read this before debugging any Docker connectivity issue.

---

## Services

| Service name   | Role                        | Exposed port              |
|----------------|-----------------------------|---------------------------|
| `sms-app`      | Laravel app (`php artisan serve`) | `0.0.0.0:8081->8000/tcp` |
| `sms-db`       | MySQL 8.0                   | `127.0.0.1:3307->3306/tcp` (localhost only) |
| `sms-redis`    | Redis 7                     | internal only (`6379/tcp`) |
| `sms-scheduler`| Laravel scheduler           | internal only             |
| `sms-worker`   | Laravel queue worker        | internal only             |

Containers reach each other by **service name**, not `127.0.0.1`. `sms-app` connects to MySQL as `sms-db:3306` and to Redis as `sms-redis:6379`.

---

## .env Requirements

The `.env` file in the project root is bind-mounted into the containers. These values **must** match the Docker service names:

```env
DB_HOST=sms-db
DB_PORT=3306

REDIS_HOST=sms-redis
REDIS_PORT=6379
```

**Never use `127.0.0.1` or `localhost` for these.** Those resolve to the container itself, not the sibling services.

### Quick check
```bash
grep -E "DB_HOST|REDIS_HOST" .env
```

Expected output:
```
DB_HOST=sms-db
REDIS_HOST=sms-redis
```

### Quick fix (if wrong)
```bash
sed -i 's/DB_HOST=127\.0\.0\.1/DB_HOST=sms-db/' .env
sed -i 's/DB_HOST=localhost/DB_HOST=sms-db/' .env
sed -i 's/REDIS_HOST=127\.0\.0\.1/REDIS_HOST=sms-redis/' .env
sed -i 's/REDIS_HOST=localhost/REDIS_HOST=sms-redis/' .env
docker compose restart sms-app
```

---

## Port Binding

`sms-app` must be bound to all interfaces so it is reachable from the LAN:

```yaml
# docker-compose.yml — correct
ports:
  - "8081:8000"

# WRONG — binds to localhost only, unreachable from LAN
ports:
  - "127.0.0.1:8081:8000"
```

---

## Firewall (UFW)

Port 8081 must be open in UFW for LAN access:

```bash
sudo ufw allow 8081
sudo ufw status
```

---

## Verifying the Stack

### 1. Check all containers are up
```bash
docker compose ps
```
All services should show `Up`.

### 2. Verify DB host config
```bash
docker compose exec sms-app php artisan tinker --execute="echo config('database.connections.mysql.host');"
# Expected: sms-db
```

### 3. Smoke-test the API
```bash
curl -i http://192.168.1.100:8081/api/sims \
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
| `PDOException: mysql:host=127.0.0.1 Connection refused` | `.env` has `DB_HOST=127.0.0.1` | Set `DB_HOST=sms-db`, restart `sms-app` |
| `RedisException: Connection refused` (host `127.0.0.1`) | `.env` has `REDIS_HOST=127.0.0.1` | Set `REDIS_HOST=sms-redis`, restart `sms-app` |
| `401 {"ok":false,"error":"unauthorized"}` | Passing the bcrypt hash as `X-API-SECRET` instead of the plaintext | Generate a new secret with tinker (see above) |
| Can't reach `192.168.1.100:8081` from browser | Port bound to `127.0.0.1:8081` or UFW blocking | Fix port binding and/or run `sudo ufw allow 8081` |

---

## Debug Log Route

A temporary browser-readable log viewer is available **in local environment only**:

```
GET /_debug/log
```

This route is guarded by `app()->isLocal()` and must be removed before any non-local deployment. It lives in `routes/web.php`.
