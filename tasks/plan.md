# Implementation Plan: Scheduled Orders and Events

## Overview

Add scheduled-order data and family confirmation without changing the existing order or payment state machines. The backend remains the source of truth, assignment is blocked until an order is actually ready, reminder offsets are controlled by administration, and React/Flutter consume one additive API contract.

## Architecture Decisions

- `orders.status` remains the operational order status. Scheduling and the family's schedule decision live in a one-to-one `order_schedules` record.
- The API adds an optional `schedule` object; ordinary orders and existing clients remain compatible.
- Assignment services enforce `READY` after locking the order. UI checks are advisory only.
- Reminder defaults are 48, 24, and 5 hours, stored in administration controls and claimed idempotently in the database before notifications are created.
- Moyasar, Apple Pay, payment routes, production configuration, and deployment are frozen.
- `Mbappe-10/laravel` is excluded until access proves that it is a distinct active backend.

## Task List

### Phase 1: Backend safety and contract

- [ ] Task 1: Reject assignment before readiness
  - Acceptance: direct and automatic assignment reject every order that is not `READY`; ready orders keep working.
  - Verify: focused PHPUnit tests for `DeliveryOperationsService`.
  - Files: assignment service and focused test only.

- [ ] Task 2: Persist an independent order schedule
  - Acceptance: an optional schedule stores delivery time, occasion details, and a pending family decision without changing `orders.status`.
  - Verify: migration rollback and model/relationship tests.
  - Files: migration, schedule model, order relation, test.

- [ ] Task 3: Create and expose scheduled orders
  - Acceptance: `POST /v1/app/orders` validates an optional future schedule; customer/family/admin order payloads expose the same additive `schedule` shape.
  - Verify: request and response feature tests; ordinary-order regression test.
  - Files: order controller/request pattern, serializers/helpers, tests.

- [ ] Task 4: Record the family's schedule decision
  - Acceptance: the assigned family can confirm or decline a schedule through a dedicated endpoint; the decision never transitions `orders.status`.
  - Verify: authorization, confirm, decline, and idempotency feature tests.
  - Files: family controller, route, decision service or model method, tests.

### Checkpoint: Backend contract

- [ ] Focused tests pass.
- [ ] Existing ordinary-order behavior remains unchanged.
- [ ] No payment file appears in the diff.

### Phase 2: Administration and reminders

- [ ] Task 5: Add reminder controls
  - Acceptance: administration can save unique positive reminder offsets; defaults are `[48, 24, 5]`.
  - Verify: control-center validation and persistence tests.
  - Files: control defaults, validation request, tests.

- [ ] Task 6: Claim due reminders idempotently
  - Acceptance: each schedule/offset/audience is claimed once even when the scheduler overlaps or retries.
  - Verify: due, not-due, duplicate, declined, and cancelled cases.
  - Files: migration, reminder model, service/command, tests.

- [ ] Task 7: Dispatch notifications from the scheduler
  - Acceptance: due family and administration reminders create notifications and deep-link to the order; failures remain retryable without duplicates.
  - Verify: notification records and scheduler registration tests.
  - Files: reminder dispatch service, command/schedule registration, tests.

### Checkpoint: Backend complete

- [ ] `composer test` passes.
- [ ] Scheduler command is safe to rerun.
- [ ] Assignment cannot start before readiness.

### Phase 3: React administration

- [ ] Task 8: Add the scheduled-orders tab
  - Acceptance: the existing orders page filters scheduled orders, shows delivery time/family decision/urgency, and keeps the journey view intact.
  - Verify: type check, lint, production build, and manual responsive check.

- [ ] Task 9: Add schedule reminder settings
  - Acceptance: administration can view and edit reminder offsets with validation and safe error states.
  - Verify: `npm run qa` and manual keyboard check.

### Phase 4: Flutter and PWA

- [ ] Task 10: Add the shared schedule contract and checkout input
  - Acceptance: an optional schedule is added to the order payload without changing payment selection or checkout execution.
  - Verify: parsing/payload widget or unit tests and frozen payment-file diff check.

- [ ] Task 11: Show schedules to customers
  - Acceptance: order list and tracking show the scheduled time, occasion, and family decision.
  - Verify: Flutter tests and analyzer.

- [ ] Task 12: Add family confirm/decline flow
  - Acceptance: family users see schedule details and use the dedicated decision endpoint; preparation/ready actions stay unavailable until confirmation.
  - Verify: service and widget tests, analyzer, Web build.

### Checkpoint: Integrated feature

- [ ] Backend, React, and Flutter checks pass.
- [ ] Contract fields match across all clients.
- [ ] No Moyasar or Apple Pay files changed.
- [ ] No repository was pushed and no production service was changed.

## Render Verification Gate

Before any push or deployment, record the live Render service name/id, repository, branch, root directory, build command, publish path, and auto-deploy setting for both API and `zad-sync-sa`. The checked-in Flutter `render.yaml` is not accepted as proof because its service names and publish paths do not match the live hostname.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Private repositories are not visible to the GitHub connector | High | Fetch from the authenticated local Git remotes into clean worktrees before editing. |
| Existing worktrees contain user changes | High | Never edit them; create clean feature worktrees/clones. |
| Auto-deploy on commit may publish unexpectedly | High | Do not push until Render is verified and the user approves deployment. |
| Reminder retries create duplicates | High | Claim with a database unique key before creating notifications. |
| Schedule UI accidentally changes payment | High | Freeze payment files and test the order payload separately. |

## Open Questions

- `Mbappe-10/laravel` is currently inaccessible and has no verified local clone; it is not part of the implementation until access is restored.
- The live Render service mapping remains unverified; deployment is blocked, not implementation.
