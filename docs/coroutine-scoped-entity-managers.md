# Holistic redesign: Doctrine usage in WinterBoot under Swoole

Status: design proposal (not implemented).

## 1. Problem

Container-managed Doctrine `EntityManager`s in WinterBoot are singletons per
worker process. Under Swoole that produces two distinct defects with one root
cause (one mutable EM shared across units of work *and* across coroutines):

1. **Staleness (sequential).** The identity map outlives the unit of work.
   Entities loaded by request N stay pinned; request N+1 re-reads them even
   after another process updates the rows. Observed in production: the API
   served `REVIEW` trade orders hours after the worker had closed them in
   Postgres, while a fresh connection read `CLOSED`.
2. **Interleaving (concurrent).** Two coroutines on one worker share the same
   EM and UnitOfWork. Coroutine A loads and modifies (unflushed); coroutine B
   starts and detaches or overwrites A's state; A flushes and silently
   persists nothing (or persists a mix of both units' changes).

Per-entry-point fixes (an interceptor here, a per-message clear there, a tick
somewhere else) do not scale: HTTP, SQS, Kafka, daemons, schedulers, and
future paths would each need their own wiring, maintained forever. The fix
must sit where all paths converge, with zero adopter work.

## 2. Goals and constraints

- Fresh reads and isolated writes for every execution path, present and
  future, without adopter code changes.
- Lazy stays lazy: entry points that never touch Doctrine build nothing.
- Compatibility: bean names (`<ds>-doctrine-em`), `instanceof EntityManager`,
  and existing concrete type-hints keep working. No adopter rewrites.
- Never break requests: machinery failures degrade to logs, never 500s.
- Same guarantees for multi-tenant managers.
- Short-lived CLI behaves exactly as today.
- Ships with observability and a kill switch.

## 3. Core mechanism: scope the EM to the execution context

Keep every bean name and type. Change what the bean *is*: a
`WinterEntityManager extends EntityManager` façade delegating each operation
to the real EM bound to the current Swoole coroutine (`Co::getContext()`),
falling back to a single process-wide EM outside coroutines (CLI, non-Swoole).

Rejected alternatives and why:

- `__call` holder / interface decorator: `__call` never fires for methods
  that exist on the wrapper, and a non-subclass breaks `instanceof` plus all
  existing concrete type-hints.
- Per-method auto-`clear()` overrides: DQL/repository reads bypass `EM::find()`,
  so overrides miss the real read paths; clearing mid-request silently drops
  unflushed writes of the same unit of work.
- Global `HINT_READ_ONLY`: read-only entities are still identity-mapped
  (verified in ORM source) — saves dirty-checking, not staleness.
- Global `HINT_REFRESH`: overwrites unflushed in-request changes — unsafe.
- Per-entry-point boundary clears: correct but unbounded maintenance.

## 4. Lifecycle (automatic)

- First EM touch inside a coroutine lazily creates that coroutine's real EM
  **with its own DBAL connection**. Separate PDO per coroutine is
  non-negotiable: sharing a connection across coroutines merges their DB
  transactions.
- Coroutine end destroys the delegate via `Coroutine::defer()` (close +
  disconnect). No leaks, no accumulation, nothing for adopter code to do.
- Each unit of work starts with a virgin identity map (staleness structurally
  impossible — no clearing needed for correctness) and coroutines never share
  managed state (interleaving structurally impossible at the ORM layer).
  Genuine row conflicts surface where they belong: transactions and
  optimistic locking, which finally work because flushes can't be silently
  detached anymore.

## 5. Prerequisites to verify first

1. **`final` audit.** If the ORM marks key EM methods `final`, subclass
   delegation is dead and the fallback is the breaking interface-decorator
   path. Audit first; add a reflection parity test asserting every public EM
   method is overridden so ORM upgrades can't silently bypass the façade.
2. **Shared vs per-delegate state.** One shared `EventManager` (stateless
   dispatch); SQL filters stay per-delegate; façade `close()` closes only the
   current coroutine's delegate.
3. **Transaction managers ride free.** `EmTransactionManager` holds the bean,
   so `transactional()` routes to the right coroutine's EM with no changes.

## 6. Rollout and guardrails

- Flag-gated: `doctrine.coroutineScopedEntityManagers`, default on under
  Swoole, auto-off without it, documented kill switch.
- Connection bounds: one PDO per active coroutine, bounded by Swoole
  worker/coroutine config; reaped by `defer` plus `IdleCheckRegistry`
  (which gets its real Doctrine job at last: connection reaping).
- Observability: active delegate count and per-delegate created-at in debug
  logs.
- Daemon loops iterating inside a *single* long-lived coroutine still
  accumulate within it: framework offers a `runInFreshCoroutine()` helper;
  manual per-iteration `clear()` remains the documented fallback.
- The request-boundary interceptor becomes unnecessary for correctness (every
  request already gets a virgin map); keep or drop as a second mechanism at
  implementation time — one guarantee, one mechanism preferred.

## 7. Explicitly out of scope

Per-method auto-clear, `__call` wrappers, global query-hint defaults, killing
the ORM, per-entry-point wiring. Write-side lost-update protection beyond
transactions/optimistic locking is a separate concern.

## 8. Incident reference

2026-09-18: `signalforge-api` (Swoole) served `trade_orders` rows as `REVIEW`
with null settlement fields hours after `runEveningSettle` had closed them;
direct Postgres reads (fresh connections, including from inside the API pod)
showed `CLOSED` with full settlement data. Root cause: singleton EM identity
map pinned across requests. Evening jobs and cash ledger verified healthy;
no data loss, display-only staleness cleared by pod restart.
