# Patronr Async Processing Architecture

This document defines the intended async processing architecture for Patronr.

It exists to keep webhook ingestion, background execution, notifications, payments, and maintenance jobs aligned as the system grows.

This is a standalone design reference for:

- inbound webhook/API event handling
- queue abstraction
- Symfony Messenger usage
- worker topology
- notification gateway integration
- retry and idempotency rules
- scheduled and maintenance jobs

It should be read together with:

- [system-design.md](/C:/xampp/htdocs/angavu/docs/system-design.md)
- [architecture-rules.md](/C:/xampp/htdocs/angavu/docs/architecture-rules.md)

---

## 1. Design Goal

Patronr should process heavy and external-facing work asynchronously without leaking low-level queue logic into controllers or business services.

The system should support:

- SMS sending
- email sending
- payment processing follow-up
- receipts
- external syncs
- webhook-driven business actions
- retries and reconciliation
- scheduled operational jobs

The async architecture must remain:

- event-driven first
- branch-first where operationally relevant
- durable
- idempotent
- auditable
- operationally simple to run

---

## 2. Core Principle

The system has two separate roles:

- `patronrapis` is the minimal ingress app
- Patronr is the business-processing app

`patronrapis` should stay very minimal.

Its job is to:

- receive webhooks or external API callbacks
- validate/authenticate where required
- write a durable inbox record to the database
- return a fast response

It should not own Patronr business processing such as:

- loyalty execution
- customer messaging decisions
- branch operational follow-up
- downstream workflow orchestration

Those belong in Patronr.

---

## 3. Processing Model

The async model is split into two layers:

1. Inbox relay layer
2. Messenger execution layer

Flow:

1. External system sends webhook/callback to `patronrapis`
2. `patronrapis` records the event in a dedicated inbox table
3. Patronr inbox relay claims new inbox rows
4. Inbox relay dispatches typed Symfony Messenger messages
5. Messenger routes messages to the correct execution lane
6. Handlers call Patronr business services
7. Follow-up work dispatches additional messages where needed

This keeps the system event-driven while still allowing one intentional polling boundary between the minimal ingress app and the main business app.

---

## 4. Polling Policy

Polling is allowed only in narrow, explicit places.

Accepted polling:

- inbox relay polling for newly received webhook/API events
- retry sweeps for failed notifications
- payment reconciliation for missed or late callbacks
- cleanup and repair jobs

Rejected polling:

- scanning `sms` tables for unsent rows as the primary execution model
- scanning `email` tables for pending rows as the primary execution model
- scanning `mpesa_payments` or other business tables to infer the main work queue

Rule:

- business tables record business state
- inbox and failure/recovery processes drive intentional retry or handoff work

---

## 5. Worker Topology

Patronr uses five stable worker lanes:

- `patronr-inbox-relay`
- `patronr-messenger-payments`
- `patronr-messenger-notifications`
- `patronr-messenger-integrations`
- `patronr-messenger-maintenance`

These are the permanent runtime lanes. New async features should normally fit into one of them rather than adding a new Supervisor program.

### 5.1 `patronr-inbox-relay`

Responsibilities:

- poll only the dedicated inbox table
- claim pending rows safely
- convert rows into typed Messenger messages
- mark relay state in the inbox table

Non-responsibilities:

- no heavy business logic
- no provider calls
- no loyalty execution
- no customer messaging decisions

### 5.2 `patronr-messenger-payments`

Responsibilities:

- payment event processing
- payment callback handling inside Patronr
- payment reconciliation
- payment-linked loyalty triggers
- financially sensitive follow-up work

### 5.3 `patronr-messenger-notifications`

Responsibilities:

- SMS sending
- email sending
- receipts
- campaign sends
- customer-facing communication jobs

### 5.4 `patronr-messenger-integrations`

Responsibilities:

- outbound syncs
- exports/imports
- external partner connectors
- non-core third-party data exchange

### 5.5 `patronr-messenger-maintenance`

Responsibilities:

- cleanup jobs
- retry scheduling
- stale-state repair
- periodic maintenance
- low-priority housekeeping

---

## 6. Queue Abstraction Layer

Application code should not deal with raw transport names or Messenger routing details.

Patronr should expose one small async dispatch layer.

Recommended shape:

- `App\Async\QueueDispatcher`

It should support:

- `dispatch(object $message): void`
- optional convenience methods for common categories

Examples:

- `dispatchPayment(object $message): void`
- `dispatchNotification(object $message): void`
- `dispatchIntegration(object $message): void`

Rules:

- controllers should call business/application services, not queue transports directly
- business/application services may emit async messages through the dispatcher
- routing stays in infrastructure config, not in controllers
- the dispatcher must stay thin and must not become a god-service

---

## 7. Inbox Contract

Webhook/API ingress from `patronrapis` should use a dedicated table such as `external_event_inbox`.

Suggested fields:

- `id`
- `source_app`
- `provider`
- `event_type`
- `dedupe_key`
- `company_id` nullable
- `branch_id` nullable
- `payload_json`
- `headers_json` nullable
- `status`
- `attempt_count`
- `available_at`
- `claimed_at`
- `claimed_by`
- `processed_at`
- `last_error`
- `received_at`

Suggested statuses:

- `pending`
- `processing`
- `processed`
- `failed`
- `dead`

Rules:

- this table is the only general inbound polling source
- dedupe should happen here first
- payload must be retained for audit and replay where appropriate
- company and branch context should be stored when known

---

## 8. Inbox Relay Design

The inbox relay worker is a bridge, not a business engine.

Flow:

1. claim a small batch of pending inbox rows
2. mark them as claimed/processing
3. map each row to a typed message
4. dispatch to Messenger
5. mark relay success or leave retry state based on outcome

Operational rules:

- use small claim batches
- use atomic claiming to avoid duplicate processing across multiple relay workers
- if possible, use database locking patterns that support safe parallel claiming
- failed relay attempts should increase `attempt_count`
- permanently broken rows should move to `dead`

---

## 9. Message Design

Messages must be typed and explicit.

Use immutable message objects with IDs and context rather than large unstructured arrays.

Every message should carry enough context for auditability and safe processing.

Recommended fields:

- `messageId`
- `correlationId`
- `causationId`
- `companyId`
- `branchId`
- `idempotencyKey`
- `occurredAt`

Examples:

- `ProcessMpesaStkCallbackMessage`
- `ProcessMpesaC2bConfirmationMessage`
- `SendSmsNotificationMessage`
- `SendEmailNotificationMessage`
- `SendReceiptMessage`
- `RunPaymentReconciliationMessage`
- `DispatchDailyManagerBriefingsMessage`

Rules:

- message classes represent real domain actions or events
- handlers stay focused and orchestration-oriented
- domain rules live in Patronr services

---

## 10. Handler Design

Handlers should be thin.

Their job is to:

- load the necessary records/context
- enforce idempotency
- call the appropriate business service or gateway
- update processing state
- dispatch follow-up messages if needed

Handlers should not:

- duplicate complex domain logic already owned by services
- embed transport routing knowledge
- become all-in-one business coordinators

Rule:

- Messenger handlers orchestrate
- Patronr services decide business behavior

---

## 11. Transport and Routing Strategy

Use separate logical transports mapped to the five worker lanes.

Recommended lanes:

- `payments`
- `notifications`
- `integrations`
- `maintenance`
- `failed`

Inbox relay is a dedicated worker process, not just another domain transport.

Routing is by message class.

Rules:

- payment-sensitive messages go to `payments`
- customer-facing send jobs go to `notifications`
- external system sync work goes to `integrations`
- cleanup, retry, and housekeeping go to `maintenance`
- terminal failures go to `failed`

Do not create one transport per message class.

---

## 12. Notification Foundation

Notifications should be built on provider abstractions, not provider-specific code inside handlers.

Recommended interfaces:

- `SmsGatewayInterface`
- `EmailGatewayInterface`

Recommended coordination service:

- `NotificationService`

Potential provider implementations:

- SMS providers such as Africa's Talking, Twilio, Infobip
- email providers such as Symfony Mailer, SendGrid, Resend

Notification flow:

1. Patronr business logic decides a notification should be sent
2. Patronr dispatches `SendSmsNotificationMessage` or `SendEmailNotificationMessage`
3. notification handler resolves tenant/provider configuration
4. provider gateway sends the message
5. delivery result is written to a notification log table
6. transient failures are retried

Rules:

- notification tables are delivery history, not the main queue
- provider choice must be tenant-configurable where the product allows it
- failed delivery state must be recorded
- all notification sends should carry company and branch context where applicable

---

## 13. Payment Processing Boundary

Payment callbacks should be received by `patronrapis` and processed by Patronr.

This means:

- `patronrapis` records the inbound payment event
- Patronr processes the payment event through a payment handler
- Patronr updates `mpesa_payments`, `pos_transactions`, loyalty state, and customer messaging

Business reactions such as:

- loyalty auto-award
- customer confirmation messaging
- branch-level follow-up

must live in Patronr, not in the ingress app.

---

## 14. Retry Strategy

Retry policy should differ by lane.

### 14.1 Payments

- conservative retry count
- retry only for transient failures
- use reconciliation for anything uncertain or callback-dependent

### 14.2 Notifications

- exponential backoff
- retry transient provider/network failures
- avoid infinite retries on permanent validation errors

### 14.3 Integrations

- moderate retry strategy
- tolerate temporary partner outages

### 14.4 Maintenance

- light retry strategy
- failures should usually be inspectable and rerunnable

Rules:

- all terminal failures should end up in a failed transport or failed-job store
- retries must be visible and measurable
- retrying must not create duplicate external effects

---

## 15. Idempotency Rules

Idempotency is mandatory.

It must exist at two levels:

- inbound event level
- business operation level

Examples:

- payment callback dedupe by provider transaction ID, receipt ID, or checkout request ID
- SMS dedupe by business event + recipient + notification type
- email dedupe by business event + recipient + template
- receipt dedupe by transaction + channel + receipt type

Handler rule:

- if the underlying work already succeeded, the handler should exit safely

This is required because queue delivery and callback receipt may be at-least-once.

---

## 16. Scheduled Jobs

Scheduled jobs belong beside the queue, not outside it.

Pattern:

- scheduler/cron invokes a command
- command dispatches typed Messenger messages
- workers do the heavy work

Examples:

- payment reconciliation
- retry failed notification sweeps
- cleanup expired drafts
- daily manager briefings
- periodic repair tasks

Rules:

- scheduled commands should stay thin
- scheduled work should use the same async lanes and logging discipline as event-driven work

---

## 17. Audit and Observability

Async work must be traceable.

Every meaningful async flow should make it easy to answer:

- what happened
- when it happened
- which company it affected
- which branch it affected
- what worker/handler processed it
- whether it succeeded, failed, retried, or dead-lettered

Minimum observability expectations:

- message IDs
- correlation IDs
- idempotency keys
- failure reason capture
- timestamps for received, claimed, processed, failed

This is especially important for:

- payments
- loyalty side effects
- customer notifications
- reconciliation jobs

---

## 18. Development and Operations Guidance

### 18.1 Local development

Local development uses:

- Windows/XAMPP for browser preview
- WSL Ubuntu for Linux CLI/runtime parity
- `systemd` as the WSL service layer
- Supervisor as the Patronr worker manager

That means Patronr worker processes should be managed by Supervisor locally, not by ad hoc open terminals.

Rule:

- `systemd` starts the Linux service layer inside WSL
- Supervisor manages Patronr worker processes

### 18.2 Linux production

Production should use Supervisor for Patronr worker processes.

CyberPanel hosting does not change the application design. It mainly affects how process definitions are installed and managed on the server.

The important rule is:

- worker process management should be consistent between local WSL and production Linux

This means normal code deploys should not require large codebase changes. The main environment differences should stay limited to:

- project directory
- Linux user
- log file paths
- `APP_ENV`

---

## 19. Build Order

Recommended implementation order:

1. create the inbox table and relay contract
2. add the Patronr async dispatch layer
3. configure Messenger transports and routing for the five lanes
4. build the inbox relay worker
5. move M-Pesa callback business processing into Patronr handlers
6. build SMS gateway abstraction and first provider
7. build email gateway abstraction and first provider
8. add notification logging, retry handling, and idempotency checks
9. add payment reconciliation jobs
10. add maintenance jobs and cleanup flows
11. add operational visibility for async failures and retries

---

## 20. Final Rule

The async system should feel like one clean Patronr capability from the application point of view:

- business services emit messages
- workers process them in the right lane
- external ingress comes through the inbox boundary
- retries and reconciliation remain deliberate

The architecture is healthy if:

- controllers stay clean
- `patronrapis` stays minimal
- Patronr owns the business logic
- notification and payment side effects are durable and idempotent
- polling remains narrow and intentional
