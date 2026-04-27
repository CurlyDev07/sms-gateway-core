# Gateway <-> ChatApp Multi-Tenant Contract v1

Version: `v1`  
Date: `2026-04-26`  
Status: `Proposed for implementation (backward-compatible rollout)`

## 1) Goal

Define one stable integration contract so:

- `sms-gateway-core` remains tenant-safe.
- `SmsChatApp` can become fully multi-tenant without payload confusion.
- outbound, status polling, and inbound relay use explicit tenant identity.

This document is the source of truth for API fields and behavior between both systems.

## 2) Tenant Identity Rules

1. Tenant identity must come from credentials, not from user-provided company IDs.
2. For outbound:
   - `UserID` + `ApiKey` map to one `api_clients` record.
   - `api_clients.company_id` is the tenant owner.
3. For inbound relay to ChatApp:
   - ChatApp must resolve tenant from gateway-provided tenant key/credential mapping.
   - ChatApp must not use global inbox routing.

## 3) Outbound Contract (ChatApp -> Gateway)

Endpoint:

- `POST /api/v2/send.php`

Encoding:

- `application/x-www-form-urlencoded`

Required fields:

- `UserID` (tenant key; maps to `api_clients.api_key`)
- `ApiKey` (tenant secret; checked against hashed `api_clients.api_secret`)
- `Mobile`
- `SMS`

Optional fields:

- `Type` or `MessageType` (`CHAT|AUTO_REPLY|FOLLOW_UP|BLAST`, normalized by gateway)

Success response:

```json
{
  "status": "00",
  "smsid": "12345"
}
```

Error response:

```json
{
  "status": "99",
  "message": "validation_failed|unauthorized|forbidden|no_sim_available|sim_blocked|queue_enqueue_failed"
}
```

Notes:

- `smsid` is the canonical outbound message reference for later status polling.
- ChatApp must treat non-`00` as not accepted (usually no `smsid`).
- If gateway returns HTTP `429` (tenant rate limit), ChatApp should queue local retry instead of marking final failed.

## 4) Status Contract

### 4.1 Compatibility (existing)

Endpoint:

- `GET /v2/status.php?smsid={id}` (or `/api/v2/status.php`)

Response:

```json
{
  "status": "0|1|2",
  "smsid": "12345"
}
```

Mapping:

- `0` = queued/in-progress
- `1` = sent
- `2` = failed/cancelled/not_found

### 4.2 Tenant-safe recommended path (new ChatApp internal use)

Endpoint:

- `GET /api/messages/status?client_message_id={id}[&sim_id={id}]`

Auth:

- `X-API-KEY`, `X-API-SECRET` (tenant-bound)

Behavior:

- Gateway resolves tenant from credentials and returns only tenant-owned rows.

## 5) Inbound Relay Contract (Gateway -> ChatApp)

### 5.1 Current payload (already live)

Gateway sends form payload:

- `ID` (gateway inbound id)
- `MOBILE`
- `SMS`
- `RECEIVED`

### 5.2 Required v1 extension for multi-tenant ChatApp

Keep current fields and add:

- `TENANT_KEY` (recommended: same value as `api_clients.api_key` / outbound `UserID`)
- `RUNTIME_SIM_ID` (optional)
- `SIM_DB_ID` (optional)

Recommended signed headers:

- `X-Gateway-Key-Id`: key identifier (tenant or global integration key id)
- `X-Gateway-Timestamp`: unix seconds
- `X-Gateway-Signature`: `hex(hmac_sha256(secret, timestamp + "." + raw_body))`

ChatApp validation rules:

1. Reject when signature is invalid.
2. Reject when timestamp drift exceeds configured window (example: 300s).
3. Resolve tenant by `TENANT_KEY` (or key-id mapping), then store inbound row with that `company_id`.
4. Enforce idempotency using `(company_id, provider, provider_msg_id)` or equivalent inbound idempotency key.

Response to Gateway:

```json
{
  "ok": true
}
```

Any non-2xx or `{ "ok": false }` should trigger gateway inbound retry policy.

## 6) ChatApp Data Model Requirements

Must be tenant-scoped:

- `conversations.company_id` with unique `(company_id, mobile)`
- `messages.company_id` with indexes on tenant+conversation/time
- `tags.company_id` with unique `(company_id, name)`
- provider references and dedupe keys include `company_id`
- any mapping tables (`conversation_tag`, etc.) enforce tenant ownership consistency

Recommended uniqueness:

- `messages`: unique `(company_id, provider, provider_msg_id)` when provider id exists
- `messages`: unique `(company_id, smsid)` for gateway status compatibility

## 7) Realtime Channel Rules (ChatApp)

Do not use global channels for tenant data.

Use:

- `private-company.{company_id}.inbox`
- `presence-company.{company_id}.inbox-presence`
- `private-conversation.{conversation_id}` with company ownership check

Channel auth must verify authenticated user belongs to same `company_id`.

## 8) Rate Limit and Burst Behavior

Gateway has tenant-aware limiter for `/api/v2/send.php` (`infotxt-send`).

- Keyed by `UserID` (fallback `IP` only when `UserID` missing).
- Tunable via gateway setting: `infotxt_send_rate_limit_per_minute` (default `1000`).

ChatApp behavior requirement:

- If outbound rejected (429 or `status=99`), keep local retry queue.
- Do not mark final failed immediately on transient intake rejection.

## 9) Rollout Plan (No Big-Bang)

1. Keep existing outbound and status compatibility endpoints.
2. Implement ChatApp tenant tables/scopes/channels.
3. Add inbound `TENANT_KEY` + signed headers in gateway relay and verify in ChatApp.
4. Move ChatApp status polling to tenant-auth endpoint (`/api/messages/status`) internally.
5. Keep `/v2/status.php` for backward compatibility until all tenants are migrated.

## 10) Gateway Registration API Needed for ChatApp Tenant Approval

ChatApp owns public/company signup and the `pending` approval workflow. `sms-gateway-core` should not expose a public signup endpoint.

When a ChatApp company is approved by a platform admin, ChatApp needs a server-to-server API in `sms-gateway-core` to provision the gateway side of that tenant.

### 10.1 Required gateway behavior

On approval, gateway must be able to:

1. Create or find a `companies` row for the ChatApp company.
2. Create an active `api_clients` row for that company.
3. Return outbound credentials to ChatApp:
   - `UserID` = `api_clients.api_key`
   - `ApiKey` = generated plain secret, returned once only
4. Store ChatApp inbound relay settings per company:
   - ChatApp inbound URL
   - ChatApp tenant key
   - inbound HMAC secret
5. Support later credential rotation and company suspension.

The gateway must never trust a `company_id` sent by ChatApp for tenant resolution during normal outbound sends. Normal outbound tenant identity remains resolved from `UserID` + `ApiKey`.

### 10.2 Data model gap in gateway

Current gateway inbound relay config is environment-based:

- `CHAT_APP_INBOUND_URL`
- `CHAT_APP_TENANT_KEY`
- `CHAT_APP_INBOUND_SECRET`

That is enough for one local/dev tenant, but not enough for production multi-tenant routing.

Gateway needs a per-company ChatApp integration table, for example:

- `company_id`
- `chatapp_company_id`
- `chatapp_company_uuid` nullable
- `chatapp_inbound_url`
- `chatapp_tenant_key`
- `chatapp_inbound_secret_encrypted`
- `status` (`active|disabled`)
- timestamps

Inbound relay should prefer this company-level setting. The existing env values can remain as bootstrap/dev fallback only.

### 10.3 Proposed admin integration auth

Registration APIs are platform/server APIs, not tenant APIs.

Recommended auth:

- `X-Platform-Key`
- `X-Platform-Timestamp`
- `X-Platform-Signature = HMAC_SHA256(timestamp + "." + raw_body, platform_secret)`

Gateway should reject stale timestamps and invalid signatures. These endpoints should also be IP allowlist-capable before production.

### 10.4 Proposed endpoints

#### Provision approved ChatApp company

`POST /api/platform/chatapp/tenants`

Request:

```json
{
  "chatapp_company_id": 123,
  "chatapp_company_uuid": "optional-chatapp-uuid",
  "company_name": "Tenant Company Inc.",
  "company_code": "tenant-company-inc",
  "timezone": "Asia/Manila",
  "chatapp_inbound_url": "https://chat.example.com/api/infotxt/inbox",
  "chatapp_tenant_key": "669",
  "generate_outbound_client": true,
  "generate_inbound_secret": true
}
```

Response:

```json
{
  "ok": true,
  "gateway_company": {
    "id": 10,
    "uuid": "gateway-company-uuid",
    "code": "tenant-company-inc",
    "status": "active"
  },
  "outbound_credentials": {
    "user_id": "generated-api-key",
    "api_key": "generated-plain-secret-returned-once"
  },
  "inbound_credentials": {
    "tenant_key": "669",
    "secret": "generated-plain-secret-returned-once"
  }
}
```

Rules:

- Idempotent by `chatapp_company_id`.
- If already provisioned, do not silently rotate secrets.
- Plain secrets are returned only on first creation or explicit rotation.
- Generated `api_clients.api_secret` must be stored hashed, as it is today.
- Inbound HMAC secret must be stored encrypted, not hashed, because gateway needs it to sign outbound relay requests to ChatApp.

#### Lookup gateway tenant registration

`GET /api/platform/chatapp/tenants/{chatapp_company_id}`

Returns registration status and non-secret identifiers. It must not return stored secrets.

#### Rotate outbound credentials

`POST /api/platform/chatapp/tenants/{chatapp_company_id}/rotate-outbound`

Creates or replaces the active outbound API secret and returns the new plain `ApiKey` once. ChatApp must update its company provider settings immediately.

#### Rotate inbound signing secret

`POST /api/platform/chatapp/tenants/{chatapp_company_id}/rotate-inbound`

Returns the new plain inbound secret once. ChatApp must update its webhook verification secret immediately.

#### Suspend or reactivate gateway tenant

`PATCH /api/platform/chatapp/tenants/{chatapp_company_id}/status`

Request:

```json
{
  "status": "active|suspended|disabled"
}
```

Behavior:

- `suspended`: reject new outbound intake, keep history.
- `disabled`: reject new outbound intake and disable API clients.
- `active`: allow outbound intake again if at least one active API client exists.

### 10.5 What ChatApp stores after approval

ChatApp should store, per company:

- gateway base URL
- outbound `UserID`
- outbound `ApiKey` encrypted
- inbound `TENANT_KEY`
- inbound HMAC secret encrypted
- registration status
- last rotation timestamps

ChatApp employees/users stay ChatApp-owned. Gateway does not need user accounts for those employees.

## 11) Operational Acceptance Checks

1. Same mobile number in two tenants does not share conversation/thread.
2. Outbound for Tenant A cannot be fetched/seen by Tenant B.
3. Inbound event with wrong signature is rejected.
4. Inbound event with valid signature but wrong tenant key is rejected.
5. Burst sends over limit are retried by ChatApp and eventually accepted (with `smsid`).
6. Realtime events from Tenant A are not visible to Tenant B users.
7. ChatApp approval provisions exactly one gateway company and one active outbound client.
8. Credential rotation returns the new secret once and old credentials stop working after cutover.
