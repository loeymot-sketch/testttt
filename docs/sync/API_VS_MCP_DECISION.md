# API vs MCP Runtime Decision — Version A

Status: canonical VA-SYS-09 decision.

## Decision

FoodKing runtime surfaces use Laravel API + branch-scoped WebSocket/outbox + fallback polling.

MCP is not the production runtime bus for POS, Kiosk, KDS, OSS, stock, catalogue, or payment state.

## Why API/outbox stays the runtime contract

| Requirement | API/outbox fit | MCP issue for runtime |
| --- | --- | --- |
| POS/Kiosk device independence | Browser calls Laravel API directly | Requires local/remote agent infrastructure |
| Branch isolation | Laravel auth, policies, `routes/channels.php` | Must be rebuilt or proxied |
| Fiscal and audit trace | DB transactions, audit logs, domain_events | MCP calls alone are not fiscal event storage |
| Replay/retry | `domain_events`, rescue, retry-failed | MCP is not an outbox queue by default |
| Offline/fallback | REST polling and local snapshots | MCP availability would be another dependency |
| Existing tests | PHPUnit/Vitest/Playwright target API/events | MCP would need a parallel contract |

## Allowed MCP usage

MCP can be used for:

- developer/agent memory through Graphiti;
- local automation and audits;
- imports/admin tooling behind explicit operators;
- external system adapters if they ultimately call the same API contracts.

MCP must not bypass:

- backend pricing SSOT;
- branch authorization;
- fiscal sequence rules;
- outbox persistence for runtime sync;
- order/payment state machines.

## Current runtime protocols

| Flow | Protocol |
| --- | --- |
| Dashboard CRUD | Laravel admin API |
| Kiosk menu/order | Laravel kiosk/frontend API + machine token |
| POS order/payment | Laravel admin/POS API |
| KDS sync | Laravel API + branch private channel + fallback sync |
| OSS | Laravel API + branch private channel |
| Realtime | `domain_events` outbox -> `DispatchDomainEventsJob` -> broadcaster |
| Recovery | `foodking:outbox:rescue`, `foodking:outbox:retry-failed` |

## Gate for future MCP runtime adapter

Any future MCP adapter must prove:

1. It calls the same backend services or public APIs.
2. It cannot submit client-side prices as authority.
3. It cannot read/write cross-branch data.
4. It persists auditable events in the same domain/outbox path.
5. It has tests equivalent to current API/E2E suites.

Until then, MCP is an orchestration/devtool layer, not a replacement for API.

