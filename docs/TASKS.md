# TASK TRACKER

---

## TASK 001 – Queue System

Status: DONE

Goal:
- Implement outbound message queue system

Scope:
- Single queue (outbound_messages table)
- Priority-based processing (CHAT, AUTO_REPLY, FOLLOW_UP, BLAST)
- Scheduled messages support
- Status flow (pending → queued → sending → sent/failed)

Result:
- Completed
- SMS send flow now uses `SmsSenderInterface` abstraction with driver-based transport adapters
- Sender path hardened with full trace metadata and provider-response validation
- Structured sender observability logs added for attempt/success/failure/exception

---

## TASK 002 – SIM Assignment System

Status: DONE

Goal:
- Implement sticky customer-to-SIM assignment

Scope:
- Assign SIM per customer
- Reuse same SIM for outbound messages
- Respect company isolation
- Support safe migration logic

Result:
- Completed

---

## TASK 003 – SIM Worker (Single SIM)

Status: DONE

Goal:
- Implement worker loop for processing messages per SIM

Scope:
- Fetch eligible messages
- Send SMS via transport layer (SmsSenderInterface)
- Update message status
- Update SIM last_sent_at

Result:
- Completed

---

## TASK 004 – Rate Limiting (Burst + Cooldown)

Status: DONE

Goal:
- Implement SIM sending rules

Scope:
- Burst mode (2–3 sec interval)
- Normal mode (5–8 sec interval)
- Burst limit tracking
- Cooldown logic (60–120 sec)
- Daily send limit enforcement

Result:
- Completed

---

## TASK 005 – Inbound Message Handling

Status: DONE

Goal:
- Receive and store inbound SMS

Scope:
- Save inbound_messages record
- Update customer_sim_assignments (has_replied)
- Trigger relay job

Result:
- Completed
- Hardened with duplicate inbound guard (short recent-window dedupe)

---

## TASK 006 – Inbound Relay to Chat App

Status: DONE

Goal:
- Forward inbound messages to Chat App

Scope:
- HTTP webhook call
- Retry on failure
- Track relay status

Result:
- Webhook relay + relay status tracking completed
- Relay dispatch moved to async queue job for non-blocking inbound ACK
- Retry-on-failure completed with backoff scheduling, capped attempts, and final-failed handling
- Retry dedupe hardened: single command-driven due-dispatch path with claim protection to avoid duplicate retry execution

---

## TASK 007 – Reliability & Failover System

Status: DONE

Goal:
- Handle failed outbound messages

Scope:
- Retry logic with limits
- Reassign SIM if needed
- Failure tracking

Result:
- Outbound retry/backoff scheduling implemented with capped attempts and final-failed handling
- Stale outbound lock recovery implemented (`gateway:recover-outbound`)
- Failover reassignment implemented via `SimFailoverService`
- Commands added: `gateway:failover-sim` and `gateway:scan-failover`
- Failover safety hardened with row-level message reassignment locks and status-preserving assignment updates
- Outbound success path hardened to clear stale retry scheduling residue (`scheduled_at`)

---

## TASK 008 – SIM Health Monitoring

Status: TODO

Goal:
- Monitor SIM and modem status

Scope:
- Signal strength logging
- Error tracking
- Online/offline detection

---

## TASK 009 – Multi-SIM Support

Status: DONE (Core Implementation)

Goal:
- Support multiple SIMs per company

Scope:
- SIM selection logic
- Load distribution
- Respect rate limits per SIM

Result:
- Core implementation completed:
- Company-isolated SIM selection with load-aware ordering
- Availability-aware selection (active, cooldown, daily limit checks)
- Worker and failover flows integrated with multi-SIM-safe selection/reassignment behavior

---

## TASK 010 – Admin APIs

Status: TODO

Goal:
- Provide operational control endpoints

Scope:
- SIM status
- Assignment lookup
- Rebalance customers
- Message status tracking

---

## TASK 011 – Multi-Tenant Security Layer

Status: DONE

Goal:
- Secure tenant isolation and authentication

Scope:
- API key authentication using api_clients
- Resolve company_id from API key (NOT request input)
- Middleware-based tenant resolution
- Prevent cross-tenant access
- Request validation + isolation

Result:
- API client authentication middleware implemented (`X-API-KEY`, `X-API-SECRET`) with active-status enforcement
- Tenant resolution middleware implemented (`tenant_company_id` from authenticated api_client)
- Tenant mismatch blocking implemented for request-supplied `company_id`
- Tenant-authenticated API route group protected with middleware
- Internal inbound modem route trust path documented separately
- API secret handling hardened (`Hash::make` on persist, `Hash::check` on auth)
- Tenant context now available globally via container bindings (`tenant.company_id`, `tenant.api_client`)

---

## TASK 012 – Python SMS Execution Layer  ← ✅ ADDED

Status: TODO

Goal:
- Connect SMS Gateway to real USB modems via Python execution layer

Scope:
- Python API server with `/send` endpoint
- Map `sim_id` → modem port (`ttyUSB`)
- Send SMS via AT commands (`AT+CMGS`)
- Support multiple USB modems (USB hub)
- Handle modem errors (timeout, SIM failure, signal issues)
- Return standardized response to Laravel

Result:
- Pending

---

## NOTE

This task list is for SMS Gateway only.

Do NOT add:
- AI logic
- RAG systems
- automation flows
- conversation management

All intelligence is handled by the Chat App.