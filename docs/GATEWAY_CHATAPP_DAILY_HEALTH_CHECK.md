# Gateway ↔ ChatApp Daily Health Check (1 Minute)

Purpose:
- Quick daily verification that inbound relay from Gateway to ChatApp is healthy.
- This is a no-risk operator checklist for existing deployments.

## 0) Prerequisites
- Gateway stack path:
  - `~/Documents/WebDev/sms-gateway-core`
- ChatApp container name:
  - `smschatapp_nuc_app`
- Expected webhook URL pattern:
  - `http://smschatapp_nuc_app:8000/api/infotxt/inbox`

## 1) Core container health (10 seconds)
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose ps
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Networks}}' | grep -E 'smschatapp_nuc_app|sms-app|sms-worker|sms-scheduler'
```

Pass criteria:
- `sms-app`, `sms-worker`, `sms-scheduler`, and `smschatapp_nuc_app` are running.

## 2) Webhook reachability from worker (10 seconds)
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose exec -T sms-worker sh -lc \
'curl -sS -m 8 -o /dev/null -w "http=%{http_code}\n" http://smschatapp_nuc_app:8000/api/infotxt/inbox'
```

Pass criteria:
- Any non-`000` HTTP code (`200/204/405/422` are all acceptable reachability proofs).

Fail signal:
- `http=000` means network/DNS path is broken from worker to ChatApp.

## 3) Relay status summary (15 seconds)
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose exec -T sms-db sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
USE sms_gateway_core;
SELECT relay_status, COUNT(*) AS c
FROM inbound_messages
WHERE created_at >= NOW() - INTERVAL 1 DAY
GROUP BY relay_status;
"'
```

Pass criteria:
- New inbound rows are mostly `success`.
- `failed` should be low and investigated when present.

## 4) Inspect latest failures quickly (10 seconds)
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose exec -T sms-db sh -lc 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "
USE sms_gateway_core;
SELECT id, customer_phone, relay_status, relay_retry_count, relay_error, created_at
FROM inbound_messages
WHERE relay_status IN (\"pending\",\"failed\")
ORDER BY id DESC
LIMIT 10;
"'
```

Pass criteria:
- No recurring transport errors.

Common error hints:
- `Could not resolve host` -> Docker network/DNS issue.
- `HTTP 429` -> ChatApp endpoint throttling/rate limit.

## 5) Optional live probe (15 seconds)
```bash
cd ~/Documents/WebDev/sms-gateway-core
KEY="daily-health-$(date +%s)"

curl -sS -X POST http://127.0.0.1:8081/api/gateway/inbound \
  -H 'Content-Type: application/json' \
  -d "{
    \"sim_id\": 1,
    \"customer_phone\": \"+639178025973\",
    \"message\": \"daily health probe\",
    \"received_at\": \"$(date -Iseconds)\",
    \"idempotency_key\": \"${KEY}\"
  }"
echo

sleep 3

docker compose exec -T sms-app php artisan tinker --execute="
\$m=\App\Models\InboundMessage::query()
  ->where('idempotency_key', '${KEY}')
  ->first(['id','relay_status','relay_error','relayed_at']);
dump(\$m ? \$m->toArray() : null);
"
```

Pass criteria:
- Probe row exists with:
  - `relay_status = success`
  - `relay_error = null`
  - `relayed_at` populated

## 6) Logs snapshot (optional, for incidents)
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose logs --since=10m sms-worker sms-app 2>&1 | \
grep -Ei 'Inbound relay success|Inbound relay failure|Inbound relay exception|Inbound relay final failed' || true

docker logs --since 10m smschatapp_nuc_app 2>&1 | \
grep -Ei '/api/infotxt/inbox|inbound|webhook|post' || true
```

---

Operational note:
- This check does not restart or reconfigure services.
- For deep inbound verification (Python listener + DB idempotency proof), also see:
  - `docs/INBOUND_QUICK_VERIFY.md`

