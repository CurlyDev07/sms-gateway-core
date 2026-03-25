# 📡 SMS Gateway System – Final Design (v3.0)
## With Database, API, Laravel Migrations, Models, and Worker Structure

---

# 1. SYSTEM OVERVIEW

A multi-tenant, multi-SIM SMS gateway system that:

- Sends SMS via EC25 USB modems
- Integrates with AWS Chat App via API
- Supports message transport types:
  - CHAT
  - AUTO_REPLY
  - FOLLOW_UP
  - BLAST
      Note:
      - These are transport labels only
      - Gateway does NOT generate them
      - They are provided by the Chat App
- Uses:
  - Sticky customer-to-SIM assignment
  - Priority queueing
  - Burst + cooldown scheduling
  - Daily cap per SIM

---

# 2. HIGH-LEVEL ARCHITECTURE

```text
AWS Chat App (RAG + Automation)
        ↓ API
SMS Gateway Server (NUC / Ubuntu)
        ↓
Gateway App (Laravel or service layer)
        ↓
Modem Workers
        ↓
EC25 USB Modems
        ↓
SIM Cards
        ↓
Customer Phones
```

---

Note:

- Chat App = Brain (AI, RAG, Automation, Conversations)
- SMS Gateway = Transport Layer (this project)

The gateway does NOT execute business logic.

# 3. CORE RULES

## 3.1 Multi-tenant
- One gateway serves many companies

## 3.2 SIM assignment
- 1 company can have many SIMs
- 1 customer can only belong to 1 SIM
- once assigned, always use same SIM (unless SIM is unavailable or migration is allowed)
- optional rebalance only for safe customers

## 3.3 Message priorities
1. CHAT
2. AUTO_REPLY
3. FOLLOW_UP
4. BLAST

Important:

- Priority is used ONLY for queue scheduling
- Gateway does NOT interpret message meaning

Gateway does NOT:
- treat AUTO_REPLY as AI logic
- treat FOLLOW_UP as automation logic
- apply business decisions

All message types are labels only




## 3.4 Sending rules
- Burst interval: 2–3 sec
- Normal interval: 5–8 sec
- Burst limit: 30
- Cooldown: 60–120 sec
- Daily limit per SIM: 4000 max
- Recommended daily load per SIM: 2000–3000


## 3.5 Message Type Handling (Critical)

Message types affect ONLY:

- queue priority
- scheduling interval (burst vs normal)

Message types DO NOT affect:

- business logic
- AI processing
- conversation flow

Gateway behavior is purely mechanical.

---

# 4. DATABASE SCHEMA

## 4.1 companies

### Purpose
Tenant/company record.

### Fields
- id
- uuid
- name
- code
- status
- timezone
- created_at
- updated_at

### Suggested migration
```php
Schema::create('companies', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('name');
    $table->string('code')->nullable()->unique();
    $table->enum('status', ['active', 'suspended', 'disabled'])->default('active');
    $table->string('timezone')->default('Asia/Manila');
    $table->timestamps();
});
```

---

## 4.2 modems

### Purpose
Physical modem devices.

### Fields
- id
- uuid
- device_name
- vendor
- model
- chipset
- serial_number
- usb_path
- control_port
- status
- last_seen_at
- created_at
- updated_at

### Suggested migration
```php
Schema::create('modems', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('device_name')->nullable();
    $table->string('vendor')->nullable();
    $table->string('model')->nullable();
    $table->string('chipset')->nullable();
    $table->string('serial_number')->nullable()->index();
    $table->string('usb_path')->nullable();
    $table->string('control_port')->nullable()->index();
    $table->enum('status', ['online', 'offline', 'disabled', 'error'])->default('offline');
    $table->timestamp('last_seen_at')->nullable();
    $table->timestamps();
});
```

---

## 4.3 sims

### Purpose
Logical SIM records assigned to a company and a modem.

### Fields
- id
- uuid
- company_id
- modem_id
- slot_name
- phone_number
- carrier
- sim_label
- status
- mode
- daily_limit
- recommended_limit
- burst_limit
- burst_interval_min_seconds
- burst_interval_max_seconds
- normal_interval_min_seconds
- normal_interval_max_seconds
- cooldown_min_seconds
- cooldown_max_seconds
- burst_count
- cooldown_until
- last_sent_at
- last_received_at
- last_error_at
- notes
- created_at
- updated_at

### Suggested migration
```php
Schema::create('sims', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('company_id')->constrained('companies');
    $table->foreignId('modem_id')->nullable()->constrained('modems');
    $table->string('slot_name')->nullable();
    $table->string('phone_number', 30)->unique();
    $table->string('carrier', 50)->nullable();
    $table->string('sim_label')->nullable();
    $table->enum('status', ['active', 'cooldown', 'disabled', 'error', 'offline'])->default('active');
    $table->enum('mode', ['NORMAL', 'BURST', 'COOLDOWN'])->default('NORMAL');
    $table->unsignedInteger('daily_limit')->default(4000);
    $table->unsignedInteger('recommended_limit')->default(3000);
    $table->unsignedInteger('burst_limit')->default(30);
    $table->unsignedInteger('burst_interval_min_seconds')->default(2);
    $table->unsignedInteger('burst_interval_max_seconds')->default(3);
    $table->unsignedInteger('normal_interval_min_seconds')->default(5);
    $table->unsignedInteger('normal_interval_max_seconds')->default(8);
    $table->unsignedInteger('cooldown_min_seconds')->default(60);
    $table->unsignedInteger('cooldown_max_seconds')->default(120);
    $table->unsignedInteger('burst_count')->default(0);
    $table->timestamp('cooldown_until')->nullable();
    $table->timestamp('last_sent_at')->nullable();
    $table->timestamp('last_received_at')->nullable();
    $table->timestamp('last_error_at')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->index(['company_id', 'status']);
});
```

---

## 4.4 customer_sim_assignments

### Purpose
Sticky customer-to-SIM mapping.

### Fields
- id
- company_id
- customer_phone
- sim_id
- status
- assigned_at
- last_used_at
- last_inbound_at
- last_outbound_at
- has_replied
- safe_to_migrate
- migration_locked
- created_at
- updated_at

### Suggested migration
```php
Schema::create('customer_sim_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained('companies');
    $table->string('customer_phone', 30);
    $table->foreignId('sim_id')->constrained('sims');
    $table->enum('status', ['active', 'migrated', 'disabled'])->default('active');
    $table->timestamp('assigned_at');
    $table->timestamp('last_used_at')->nullable();
    $table->timestamp('last_inbound_at')->nullable();
    $table->timestamp('last_outbound_at')->nullable();
    $table->boolean('has_replied')->default(false);
    $table->boolean('safe_to_migrate')->default(false);
    $table->boolean('migration_locked')->default(false);
    $table->timestamps();

    $table->unique(['company_id', 'customer_phone']);
    $table->index(['company_id', 'sim_id']);
});
```

---

## 4.5 outbound_messages

### Purpose
Outbound queue and send logs.

### Fields
- id
- uuid
- company_id
- sim_id
- customer_phone
- message
- message_type
- priority
- status
- scheduled_at
- queued_at
- sent_at
- failed_at
- failure_reason
- retry_count
- client_message_id
- campaign_id
- conversation_ref
- metadata
- created_at
- updated_at

### Suggested migration
```php
Schema::create('outbound_messages', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('company_id')->constrained('companies');
    $table->foreignId('sim_id')->nullable()->constrained('sims');
    $table->string('customer_phone', 30)->index();
    $table->text('message');
    $table->enum('message_type', ['CHAT', 'AUTO_REPLY', 'FOLLOW_UP', 'BLAST']);
    $table->unsignedTinyInteger('priority')->default(10);
    $table->enum('status', ['pending', 'queued', 'sending', 'sent', 'failed', 'cancelled'])->default('pending');
    $table->timestamp('scheduled_at')->nullable();
    $table->timestamp('queued_at')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('failed_at')->nullable();
    $table->text('failure_reason')->nullable();
    $table->unsignedInteger('retry_count')->default(0);
    $table->string('client_message_id')->nullable()->index();
    $table->unsignedBigInteger('campaign_id')->nullable();
    $table->string('conversation_ref')->nullable();
    $table->json('metadata')->nullable();
    $table->timestamps();

    $table->index(['company_id', 'sim_id', 'status']);
    $table->index(['sim_id', 'priority', 'status', 'scheduled_at']);
});
```

Priority suggestion:
- CHAT = 100
- AUTO_REPLY = 90
- FOLLOW_UP = 50
- BLAST = 10
Numeric priority values are used internally for queue sorting.
Higher number = higher priority.

---

## 4.6 inbound_messages

### Purpose
Inbound message logs and relay status.

### Suggested migration
```php
Schema::create('inbound_messages', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('company_id')->constrained('companies');
    $table->foreignId('sim_id')->constrained('sims');
    $table->string('customer_phone', 30)->index();
    $table->text('message');
    $table->timestamp('received_at');
    $table->boolean('relayed_to_chat_app')->default(false);
    $table->timestamp('relayed_at')->nullable();
    $table->enum('relay_status', ['pending', 'success', 'failed'])->default('pending');
    $table->text('relay_error')->nullable();
    $table->json('metadata')->nullable();
    $table->timestamps();

    $table->index(['company_id', 'sim_id', 'received_at']);
});
```

---

## 4.7 sim_daily_stats

### Purpose
Daily SIM counters.

### Suggested migration
```php
Schema::create('sim_daily_stats', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sim_id')->constrained('sims');
    $table->date('stat_date');
    $table->unsignedInteger('sent_count')->default(0);
    $table->unsignedInteger('sent_chat_count')->default(0);
    $table->unsignedInteger('sent_auto_reply_count')->default(0);
    $table->unsignedInteger('sent_follow_up_count')->default(0);
    $table->unsignedInteger('sent_blast_count')->default(0);
    $table->unsignedInteger('failed_count')->default(0);
    $table->unsignedInteger('inbound_count')->default(0);
    $table->timestamps();

    $table->unique(['sim_id', 'stat_date']);
});
```

---

## 4.8 sim_health_logs

### Purpose
Signal, errors, and health monitoring.

### Suggested migration
```php
Schema::create('sim_health_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('sim_id')->constrained('sims');
    $table->enum('status', ['online', 'offline', 'error', 'cooldown', 'disabled']);
    $table->integer('signal_strength')->nullable();
    $table->string('network_name')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamp('logged_at');
    $table->timestamps();

    $table->index(['sim_id', 'logged_at']);
});
```

---

## 4.9 api_clients

### Purpose
Chat App authentication.

### Suggested migration
```php
Schema::create('api_clients', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->nullable()->constrained('companies');
    $table->string('name');
    $table->string('api_key')->unique();
    $table->string('api_secret');
    $table->enum('status', ['active', 'disabled'])->default('active');
    $table->text('allowed_ips')->nullable();
    $table->timestamps();
});
```

---

# 5. ELOQUENT MODEL LIST

Recommended models:

- Company
- Modem
- Sim
- CustomerSimAssignment
- OutboundMessage
- InboundMessage
- SimDailyStat
- SimHealthLog
- ApiClient

## Relationship examples

### Company
- hasMany(Sim::class)
- hasMany(CustomerSimAssignment::class)
- hasMany(OutboundMessage::class)
- hasMany(InboundMessage::class)

### Sim
- belongsTo(Company::class)
- belongsTo(Modem::class)
- hasMany(CustomerSimAssignment::class)
- hasMany(OutboundMessage::class)
- hasMany(InboundMessage::class)
- hasMany(SimDailyStat::class)

### CustomerSimAssignment
- belongsTo(Company::class)
- belongsTo(Sim::class)

### OutboundMessage
- belongsTo(Company::class)
- belongsTo(Sim::class)

### InboundMessage
- belongsTo(Company::class)
- belongsTo(Sim::class)

---

# 6. API ENDPOINTS

Base URL:
```text
https://sms-gateway.yourdomain.com/api
```

## 6.1 Send single SMS

### POST `/messages/send`

Request:
```json
{
  "company_id": 1,
  "customer_phone": "09171234567",
  "message": "Hello customer",
  "message_type": "CHAT",
  "scheduled_at": null,
  "client_message_id": "chatapp-msg-1001",
  "conversation_ref": "conv-8891"
}

// Note: "message_type": "CHAT",
// 	•	Gateway treats all fields as data only
// 	•	Gateway does NOT interpret conversation_ref
// 	•	Gateway does NOT process AI context
// 	•	Gateway does NOT execute automation logic
// 	•	conversation_ref is used by Chat App only

//     Note: "conversation_ref": "conv-8891"
//   - conversation_ref is used by Chat App only
//   - Gateway does NOT use this field internally

```

Response:
```json
{
  "success": true,
  "message_id": "uuid-here",
  "company_id": 1,
  "customer_phone": "09171234567",
  "assigned_sim_id": 12,
  "assigned_phone_number": "09170001111",
  "status": "queued"
}
```

## 6.2 Send bulk SMS

### POST `/messages/bulk`

Request:
```json
{
  "company_id": 1,
  "message_type": "BLAST",
  "items": [
    {
      "customer_phone": "09171234567",
      "message": "Promo 1",
      "client_message_id": "blast-1"
    },
    {
      "customer_phone": "09179999999",
      "message": "Promo 2",
      "client_message_id": "blast-2"
    }
  ]
}
```

Response:
```json
{
  "success": true,
  "queued_count": 2,
  "failed_count": 0
}
```

## 6.3 Inbound from modem worker

### POST `/gateway/inbound`

Request:
```json
{
  "sim_id": 12,
  "customer_phone": "09171234567",
  "message": "Yes order ko po",
  "received_at": "2026-03-15T19:30:00+08:00"
}
```

Response:
```json
{
  "success": true
}
```

## 6.4 Chat App inbound relay webhook

### POST to Chat App
```text
https://chatapp.domain.com/api/sms/inbound
```

Payload:
```json
{
  "company_id": 1,
  "sim_id": 12,
  "sim_phone_number": "09170001111",
  "customer_phone": "09171234567",
  "message": "Yes order ko po",
  "received_at": "2026-03-15T19:30:00+08:00"
}
```

## 6.5 Get SIM status

### GET `/sims?company_id=1`

Response:
```json
{
  "success": true,
  "data": [
    {
      "sim_id": 12,
      "company_id": 1,
      "phone_number": "09170001111",
      "carrier": "Globe",
      "status": "active",
      "mode": "NORMAL",
      "sent_today": 1240,
      "daily_limit": 4000,
      "cooldown_until": null
    }
  ]
}
```

## 6.6 Get customer assignment

### GET `/assignments?company_id=1&customer_phone=09171234567`

Response:
```json
{
  "success": true,
  "data": {
    "company_id": 1,
    "customer_phone": "09171234567",
    "sim_id": 12,
    "sim_phone_number": "09170001111",
    "has_replied": true,
    "safe_to_migrate": false
  }
}
```

## 6.7 Force assign customer to SIM

### POST `/assignments/set`

Request:
```json
{
  "company_id": 1,
  "customer_phone": "09171234567",
  "sim_id": 12
}
```

## 6.8 Mark safe to migrate

### POST `/assignments/mark-safe`

Request:
```json
{
  "company_id": 1,
  "customer_phone": "09171234567",
  "safe_to_migrate": true
}
```

## 6.9 Rebalance customers

### POST `/admin/rebalance`

Request:
```json
{
  "company_id": 1,
  "from_sim_id": 10,
  "to_sim_id": 12,
  "limit": 100
}
```

Only move safe customers.

## 6.10 Get message status

### GET `/messages/status?client_message_id=chatapp-msg-1001`

Response:
```json
{
  "success": true,
  "data": {
    "message_id": "uuid-here",
    "status": "sent",
    "sim_id": 12,
    "sent_at": "2026-03-15T19:35:00+08:00"
  }
}
```

## 6.11 Health check

### GET `/health`

Response:
```json
{
  "success": true,
  "gateway": "ok",
  "database": "ok",
  "modems_online": 5,
  "sims_active": 5
}
```

---

# 7. WORKER / SERVICE CLASS STRUCTURE

Recommended Laravel services/jobs:

## 7.1 Services
- `CustomerSimAssignmentService`
- `SimSelectionService`
- `OutboundMessageQueueService`
- `InboundRelayService`
- `SimDailyCounterService`
- `ModemCommandService`
- `WorkerStateService`

## 7.2 Jobs
- `QueueSingleMessageJob`
- `QueueBulkMessagesJob`
- `ProcessSimQueueJob`
- `RelayInboundMessageJob`
- `RefreshSimHealthJob`

## 7.3 Console commands
- `php artisan gateway:process-sim {simId}`
- `php artisan gateway:scan-inbound`
- `php artisan gateway:refresh-health`
- `php artisan gateway:reset-daily-stats`

---

# 8. MAIN LOOP SPEC

Per SIM worker:

```text
while true:
    if sim.status == disabled:
        sleep(5)
        continue

    if sent_today >= daily_limit:
        sleep(30)
        continue

    if mode == COOLDOWN and now < cooldown_until:
        sleep(6)
        continue

    next_message = fetch highest priority eligible message for this sim

    if none:
        sleep(2)
        continue

    if type in [CHAT, AUTO_REPLY]:
        mode = BURST
        send message
        burst_count += 1
        sleep(random 2..3 sec)

        if burst_count >= burst_limit:
            mode = COOLDOWN
            cooldown_until = now + random 60..120 sec
            burst_count = 0

      // CHAT and AUTO_REPLY use burst mode for responsiveness
      // FOLLOW_UP and BLAST use normal mode for safety


    else:
        mode = NORMAL
        send message
        sleep(random 5..8 sec)
```

---

# 9. MODEM FLOW

## Outbound
1. Chat App calls `/messages/send`
2. Gateway resolves sticky SIM
3. Gateway inserts outbound_messages row
4. Correct SIM worker picks row
5. Worker sends AT command through modem
6. Status updated to sent/failed

## Inbound
1. Modem receives SMS
2. Gateway detects inbound
3. Saves inbound_messages row
4. Updates assignment (`has_replied = true`)
5. Relays to Chat App webhook
Gateway does NOT process or interpret inbound message meaning.
It only relays data to the Chat App.

---

# 10. EXAMPLE CONTROLLER OUTLINE

## MessageController
- `send()`
- `bulk()`
- `status()`

## SimController
- `index()`
- `show()`

## AssignmentController
- `show()`
- `set()`
- `markSafe()`

## AdminController
- `rebalance()`

## GatewayInboundController
- `store()`

---

# 11. IMPLEMENTATION ORDER

## Phase 1
- migrations
- models
- single send API
- single SIM worker

## Phase 2
- sticky assignment
- multi-SIM support
- priorities
- inbound relay

## Phase 3
- health logs
- rebalance tools
- admin dashboards
- analytics

---

# 12. ARCHITECTURE BOUNDARY (CRITICAL)

The SMS Gateway is a transport layer only.

---

## IT DOES NOT:

- generate AI responses
- perform business logic
- manage conversations
- execute automation
- understand message meaning
- store AI memory or RAG data

---

## IT ONLY:

- sends messages
- receives messages
- assigns SIMs
- enforces rate limits
- schedules queue
- tracks delivery

---

## ALL INTELLIGENCE LIVES IN:

Chat App (Separate App / Project)

# 13. FINAL STATUS

```text
v3 DESIGN READY FOR BUILD ✅
```
