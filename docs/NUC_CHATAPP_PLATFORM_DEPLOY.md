# NUC Deploy Guide — ChatApp Platform + Local Callback Wiring

This runbook updates `sms-gateway-core` on NUC so ChatApp multi-tenant rollout can proceed.

Scope:
- pull latest gateway code
- apply migrations (no `migrate:fresh`)
- set local Docker-network ChatApp URLs
- set platform auth env
- recreate gateway containers
- verify platform routes + callback fields are live

## 1) Update Gateway Code On NUC

```bash
cd ~/Documents/WebDev/sms-gateway-core
git pull origin main
git log --oneline -n 3
```

You should include commit:
- `f3dd4c8 feat(gateway): add chatapp delivery callback prerequisites and mac runtime wiring docs`

## 2) Set Required `.env` Values (NUC)

Use Docker-local ChatApp container routes (not Cloudflare):

```env
CHAT_APP_INBOUND_URL=http://smschatapp_nuc_app:8000/api/infotxt/inbox
SMS_CHAT_APP_INBOUND_URL=http://smschatapp_nuc_app:8000/api/infotxt/inbox
CHAT_APP_DELIVERY_STATUS_URL=http://smschatapp_nuc_app:8000/api/gateway/delivery-status
```

Set platform auth:

```env
CHAT_APP_PLATFORM_KEY=<shared_platform_key>
CHAT_APP_PLATFORM_SECRET=<shared_platform_secret>
CHAT_APP_PLATFORM_TIMESTAMP_TOLERANCE_SECONDS=300
```

## 3) Apply DB + Recreate Services

```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose exec -T sms-app php artisan migrate
docker compose exec -T sms-app php artisan optimize:clear
docker compose up -d --force-recreate sms-app sms-worker sms-scheduler sms-sim-supervisor
```

## 4) Verify Platform APIs + Middleware Exist

```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose exec -T sms-app php artisan route:list | grep -E "platform/chatapp|v2/status.php|api/v2/send.php"
docker compose exec -T sms-app sh -lc 'grep -n "platform.client" app/Http/Kernel.php && grep -n "CHAT_APP_PLATFORM" config/services.php'
```

Expected platform routes:
- `POST /api/platform/chatapp/tenants`
- `GET /api/platform/chatapp/tenants/{chatapp_company_id}`
- `POST /api/platform/chatapp/tenants/{chatapp_company_id}/rotate-outbound`
- `POST /api/platform/chatapp/tenants/{chatapp_company_id}/rotate-inbound`
- `PATCH /api/platform/chatapp/tenants/{chatapp_company_id}/status`

## 5) Register/Upsert One Tenant (Signed Request)

This upserts tenant registration and stores:
- `chatapp_inbound_url`
- `chatapp_delivery_status_url`

```bash
cd ~/Documents/WebDev/sms-gateway-core

export PLATFORM_KEY='<shared_platform_key>'
export PLATFORM_SECRET='<shared_platform_secret>'
export TS="$(date +%s)"

export BODY='{"chatapp_company_id":279,"chatapp_company_uuid":"company-279","company_name":"COMPANY1","company_code":"company1","timezone":"Asia/Manila","chatapp_inbound_url":"http://smschatapp_nuc_app:8000/api/infotxt/inbox","chatapp_delivery_status_url":"http://smschatapp_nuc_app:8000/api/gateway/delivery-status","chatapp_tenant_key":"chatapp-company1","generate_outbound_client":true,"generate_inbound_secret":true}'

export SIG="$(php -r 'echo hash_hmac("sha256", getenv("TS").".".getenv("BODY"), getenv("PLATFORM_SECRET"));')"

curl -sS -X POST "http://127.0.0.1:8081/api/platform/chatapp/tenants" \
  -H "Content-Type: application/json" \
  -H "X-Platform-Key: $PLATFORM_KEY" \
  -H "X-Platform-Timestamp: $TS" \
  -H "X-Platform-Signature: $SIG" \
  -d "$BODY" | jq .
```

## 6) Confirm Tenant Callback URLs Were Stored

```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose exec -T sms-app php artisan tinker --execute='
dump(\App\Models\CompanyChatAppIntegration::query()->where("chatapp_company_id","279")->first([
  "company_id","chatapp_company_id","chatapp_inbound_url","chatapp_delivery_status_url","chatapp_tenant_key","status"
])?->toArray());
'
```

## 7) Callback Contract (Gateway -> ChatApp)

Delivery callback target:
- `http://smschatapp_nuc_app:8000/api/gateway/delivery-status`

Form fields include:
- `TENANT_KEY`, `EVENT_ID`, `SMSID`, `STATUS`, `RETRY_COUNT`, `COMPANY_ID`, `MESSAGE_TYPE`, `OCCURRED_AT`
- optional: `FROM_STATUS`, `FAILURE_REASON`, `SIM_ID`, `RUNTIME_SIM_ID`, `CLIENT_MESSAGE_ID`

Signed headers:
- `X-Gateway-Timestamp`
- `X-Gateway-Key-Id`
- `X-Gateway-Signature = HMAC_SHA256(timestamp + "." + raw_form_body, inbound_secret)`

## 8) Network Check

Both apps must share Docker network connectivity. On NUC this is typically `sms-gateway-core_default` plus ChatApp network attachment.

Quick check from gateway container:

```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose exec -T sms-app sh -lc 'curl -i -m 5 -sS "http://smschatapp_nuc_app:8000/api/infotxt/inbox" | head -n 5 || true'
```

(A non-200 is acceptable for this probe; the goal is DNS/connectivity to the container name.)
