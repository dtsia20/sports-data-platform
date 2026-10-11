# ADR-003: Separate scheduled dispatch from supervised queue execution

Status: Implemented on the background-processing feature branch; merge pending.

## Context

Provider synchronization must run periodically without placing HTTP ingestion inside the scheduler. A timed-out PHP worker can die while a database job remains reserved and its execution lock remains held. A graceful restart exits successfully and also needs a replacement process.

## Decision

Schedule `sports:sync-matches` every five minutes in UTC. It selects enabled providers and queues their sync jobs. Scheduler `onOneServer` and dispatch overlap locks use shared cache. The learner chose to allow duplicate pending jobs; the provider/date job lock controls simultaneous execution, not deduplication.

Enable PHP `pcntl` and use long-lived daemon workers. Keep the 60-second sync timeout shorter than the 90-second database reservation and execution-lock expiry. Timeout kills leave recovery to expiry and a replacement worker. Ordinary exception backoff and timeout recovery are different paths.

Provide opt-in Compose worker and scheduler services for development, sharing the application build. Use `restart: unless-stopped`, because cache-based `queue:restart` exits successfully and `on-failure` would not restart it. Allow 75 seconds for shutdown, forward signals with an init process, and recycle workers between jobs after one hour. Production releases and their scheduling platform remain a separate decision.

## Alternatives and consequences

Manual terminal processes remain useful for lessons, but need manual replacement. Systemd, Supervisor, or a managed container platform can supervise production workers; Compose is the smallest reproducible development option here. Production may invoke `schedule:run` every minute instead of keeping `schedule:work` running.

Tests run real subprocesses and PostgreSQL-backed queue/cache operations with short test-only time budgets. They verify timeout death, retry exhaustion, healthy replacement work and graceful restarts. They do not establish external-provider side-effect safety, multi-host behavior or production readiness.
