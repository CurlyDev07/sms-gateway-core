# DECISIONS LOG

---

## Decision: Use Message Type Labels with Priority

Date: 2026-03-25

Rule:
- Messages have types: CHAT, AUTO_REPLY, FOLLOW_UP, BLAST
- Each type maps to a numeric priority

Reason:
- Enables flexible scheduling
- Avoids multiple queue complexity
- Supports scaling and future adjustments

Impact:
- Single queue system
- Priority-based processing required

---

## Decision: Single Queue with Priority-Based Processing

Date: 2026-03-25

Rule:
- All outbound messages are stored in a single queue
- Messages are processed based on priority (higher first)

Reason:
- Simpler architecture
- Easier scaling and maintenance
- Avoids queue fragmentation

Impact:
- Requires proper indexing
- Requires efficient priority selection query

---

## Decision: Message Types Affect Scheduling Only

Date: 2026-03-25

Rule:
- Message types affect:
  - priority
  - sending interval (burst vs normal)

Message types DO NOT affect:
- business logic
- AI behavior
- automation decisions

Reason:
- Maintain clean separation between gateway and Chat App
- Keep gateway as transport-only system

Impact:
- Gateway remains stateless in terms of logic
- Chat App handles all intelligence

---

## Decision: Always ACK Webhook

Date: 2026-03-25

Rule:
- Always return HTTP 200 for inbound requests

Reason:
- Prevent SMS provider retry loops
- Ensure stable message ingestion

Impact:
- Errors must be handled internally
- Logging and retry must be implemented separately

---

## Decision: Gateway as Transport Layer Only

Date: 2026-03-25

Rule:
- SMS Gateway handles only message transport

Gateway MUST NOT:
- generate AI responses
- perform business logic
- manage conversations
- execute automation
- interpret message meaning

Reason:
- Clean architecture separation
- Easier scaling and debugging
- Prevents logic duplication

Impact:
- Requires Chat App as separate system
- Gateway focuses on performance and reliability only

---

## Decision: Sticky Customer-to-SIM Assignment

Date: 2026-03-25

Rule:
- Each customer is assigned to one SIM
- Same SIM is reused for future messages
- Reassignment allowed only if:
  - SIM is unavailable
  - customer is marked safe_to_migrate

Reason:
- Maintain message consistency
- Improve deliverability
- Reduce carrier suspicion

Impact:
- Requires assignment tracking table
- Requires migration controls

---

## Decision: Rate Limiting via Burst + Cooldown

Date: 2026-03-25

Rule:
- Messages sent using:
  - Burst mode (fast interval)
  - Normal mode (slower interval)
  - Cooldown period after burst

Reason:
- Prevent SIM bans
- Mimic human-like sending behavior
- Maintain delivery success rate

Impact:
- Requires SIM state tracking
- Requires timing control in workers

---

## Decision: Retry Metadata Stored In Existing Message Tables

Date: 2026-03-25

Rule:
- Outbound retry control uses existing `outbound_messages` fields (`retry_count`, `scheduled_at`, `status`, `failure_reason`, `failed_at`, `locked_at`)
- Inbound relay retry control stores minimal metadata directly in `inbound_messages`:
  - `relay_retry_count`
  - `relay_next_attempt_at`
  - `relay_failed_at`
  - `relay_locked_at`
- Retry scheduling uses exponential backoff with configured caps
- Final-failed state remains `failed` with preserved error details

Reason:
- Keep reliability logic transport-layer focused and lightweight
- Avoid introducing extra retry tables or separate dead-letter subsystem
- Ensure recoverability of stuck/failed work with minimal schema expansion

Impact:
- Adds operational commands for recovery/retry dispatch
- Improves resilience for outbound send failures, inbound relay failures, and stale locks
- Keeps architecture aligned with SMS gateway transport responsibilities

---

## Decision: Company-Isolated Failover With Sticky Assignment Controls

Date: 2026-03-25

Rule:
- SIM failover replacement must stay within the same company
- Only pending outbound messages are reassigned during failover
- Active `sending` messages are not moved by failover (handled by stale lock recovery)
- Customer sticky assignments can be moved on SIM unavailability but must respect `migration_locked`

Reason:
- Prevent cross-tenant contamination
- Preserve transport safety and deterministic reassignment behavior
- Keep sticky assignment default while allowing controlled failover recovery

Impact:
- Introduces dedicated failover orchestration service and operator commands
- Supports controlled reassignment without altering business logic boundaries

---

## Decision: Tenant Identity Must Come From ApiClient Authentication

Date: 2026-03-25

Rule:
- Tenant-scoped API requests authenticate using `api_clients` credentials (`api_key`, `api_secret`)
- Resolved tenant/company is derived from authenticated `api_client.company_id`
- Request payload `company_id` is not trusted for tenant identity
- Tenant mismatch attempts are rejected

Reason:
- Prevent cross-tenant data access by request tampering
- Keep tenant isolation enforced centrally through middleware
- Align gateway API security with multi-tenant SaaS safety requirements

Impact:
- Adds dedicated API client auth and tenant resolution middleware
- Tenant-facing routes require middleware-based authentication and tenant context
- Internal modem ingestion routes remain explicitly separated by trust model

---

## Decision: SMS Sending Uses Driver-Based Transport Abstraction

Date: 2026-03-25

Rule:
- Laravel worker and services use `SmsSenderInterface` for outbound send calls
- Sender implementations are swappable by config driver (`python`, `queue`)
- Sender returns standardized `SmsSendResult` and does not throw transport exceptions

Reason:
- Decouple gateway queue/worker logic from specific SMS transport implementation
- Support current Python API bridge and future queue bridge without worker rewrites
- Centralize SMS transport safety behavior (timeouts, retries, normalized errors)

Impact:
- No direct modem/transport calls in worker orchestration logic
- Safer production rollouts through config-based driver switching

---

## Decision: Separate SMS Execution Layer (Python Modem Engine)  ← ✅ ADDED

Date: 2026-03-25

Rule:
- Laravel Gateway MUST NOT communicate with USB modems directly
- All modem communication is handled by an external Python service
- Gateway interacts via HTTP through `SmsSenderInterface`

Reason:
- Isolate hardware-level operations from application layer
- Prevent blocking IO inside Laravel workers
- Allow independent scaling of modem layer
- Enable easier replacement (Python → queue → distributed system)

Impact:
- Requires Python API service for SMS execution
- Requires mapping of `sim_id` → `ttyUSB` ports
- Adds network hop but improves architecture stability and flexibility