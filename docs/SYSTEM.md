# SYSTEM ARCHITECTURE

Last Updated: 2026-03-25

---

## OVERVIEW

This system is a multi-tenant SMS Gateway platform with:

- SMS Gateway (modem-based)
- Queue system
- SIM management
- API integration with Chat App (external system)
- External SMS Execution Layer (Python Modem Engine)

Note:
AI, RAG, automation, and chat logic are handled by a separate Chat App.

---

## CORE COMPONENTS

### 1. SMS GATEWAY
- Handles sending/receiving SMS
- Manages queue, assignment, retry, and failover
- Transport-only system (no intelligence)

---

### 2. BACKEND (Laravel)
- Multi-tenant system
- API + logic layer
- Queue processing
- SIM assignment + failover logic
- Calls SmsSenderInterface (transport abstraction)

---

### 3. MESSAGE TRANSPORT TYPES

- CHAT
- AUTO_REPLY
- FOLLOW_UP
- BLAST

Note:
These are transport labels only.
They are provided by the Chat App and used for queue prioritization.

---

### 4. SMS EXECUTION LAYER (Python Modem Engine)

- Receives HTTP requests from Laravel Gateway
- Maps sim_id → modem port (ttyUSB)
- Sends SMS via AT commands
- Returns standardized response

Responsibilities:
- AT command execution
- Modem communication
- SIM routing
- Hardware-level error handling

IMPORTANT:
- This layer handles ALL modem communication
- Laravel MUST NOT interact with modem directly

---

## MESSAGE FLOW

### Incoming SMS

USB Modem (SIM)
→ Python SMS Engine
→ Gateway inbound API (/gateway/inbound)
→ Store inbound message
→ Relay to Chat App via webhook

---

### Outgoing SMS

Chat App
→ Gateway API (/messages/send)
→ Store outbound message
→ Queue message
→ Assign SIM (sticky)
→ Worker processes message
→ SmsSenderInterface
→ Python SMS Engine
→ USB Modem (ttyUSB)
→ SIM Card
→ Customer

---

## QUEUE SYSTEM

Queue:

- Single outbound queue (outbound_messages)
- Messages prioritized using numeric priority values

Priority (example):
- CHAT = highest
- AUTO_REPLY = high
- FOLLOW_UP = medium
- BLAST = lowest

---

## RULES (CRITICAL)

- Higher priority messages are processed first
- Message type affects scheduling (burst vs normal)
- Always return 200 OK in webhook
- Deduplicate messages

- Gateway does NOT interpret message meaning
- Gateway does NOT execute business logic
- Gateway only handles transport and delivery

---

## DO NOT BREAK

- Message type separation (labels only)
- Queue priority system
- Multi-tenant isolation
- Transport abstraction (SmsSenderInterface)

---

## ARCHITECTURE BOUNDARY (CRITICAL)

This system is a transport layer only.

---

### IT DOES NOT:

- generate AI responses
- perform automation
- manage conversations
- process business logic
- store AI memory or RAG data
- communicate directly with hardware (modems)

---

### IT ONLY:

- sends SMS (via abstraction layer)
- receives SMS
- assigns SIMs
- enforces rate limits
- processes queue
- tracks delivery

---

### EXTERNAL SYSTEMS:

#### 1. Chat App (separate project)
Handles:
- AI
- RAG
- automation
- conversation logic

#### 2. Python SMS Engine
Handles:
- modem communication
- AT command execution
- SIM-to-port mapping
- hardware-level delivery