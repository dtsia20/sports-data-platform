# Web Development Improvement — Architecture Learning Pipeline

**Updated:** 2026-10-10  
**Reference project:** [dtsia20/sports-data-platform](https://github.com/dtsia20/sports-data-platform)  
**Purpose:** Shared cross-chat source of truth for what we have learned, what we are building, and what comes next. Always verify the actual GitHub branch and working tree before describing any item as finished.

## 1. Learning contract

Goal: progress from senior WordPress/PHP developer toward broader backend/full-stack engineering (PHP, Laravel, API integrations, architectural design, testing, performance, reliability, DevOps, cloud) with a defensible public GitHub portfolio. Favor architecture depth and decisions over rapidly generating large volumes of code.

Each focused lesson follows:
1. **Review:** inspect the relevant current branch, files, Git status, previous tests/CI, and any unfinished work.
2. **Define the problem:** state the real-world scenario, constraints, expected behavior and tradeoffs.
3. **Design:** explain responsibilities, layer boundaries, data model, failure modes and alternatives. Ask the learner to reason about important choices rather than providing code without context.
4. **Implement incrementally:** small purposeful changes on a feature branch, with explanations of why each piece belongs where it does.
5. **Test:** happy path, idempotency, invalid cases, rollback, concurrency, and boundary conditions when relevant. Use PostgreSQL-backed Laravel tests for DB behavior.
6. **Review:** examine code quality, simplification, performance/security implications; run Pint and the full test suite.
7. **Document:** ALWAYS update the root `README.md` when a topic is complete; record meaningful decisions in `docs/adr/` and session notes where useful. A topic is not complete until docs accurately reflect implemented behavior and known limitations.
8. **Deliver:** commit, push, PR, GitHub Actions CI, review and merge to `main` after checks pass. Never claim work was run, deployed or merged without confirmation.
9. **Next:** pick one next milestone based on architectural dependencies and portfolio value.

Coding guidelines: PHP PSR-12 and Laravel Pint; PHP classes and methods camelCase; external JSON camelCase; avoid premature interfaces/layers. Prefer Docker container PHP for PHP 8.5 / Laravel 13 rather than the host PHP. Preserve a stable `main` and short-lived feature branches.

## 2. Architecture overview

```text
External sports-data providers
           ↓
Provider adapters (translate external payloads)
           ↓
Normalized DTOs (TeamData / CompetitionData / MatchData)
           ↓
Application services (sync / resolution)
           ↓
Canonical entities (Competition / Team / SportsMatch)
           ↓
PostgreSQL + provider-reference mapping tables
           ↓
Versioned REST API (resources + filters + pagination)
```

Guiding invariant: provider-specific IDs never live inside canonical team/competition/match entities. References map `(provider_id, external_id)` to canonical IDs. Team identity and match identity are separate concerns. Unknown identities should be captured, not blindly matched by name.

## 3. Learning progress — verified versus local work

| # | Module | Learned / delivered | Status |
|---|---|---|---|
| 01 | Project foundation | Laravel 13, PHP 8.5, Composer, PostgreSQL 17, Docker Compose, Adminer | Complete |
| 02 | Canonical domain modeling | Provider, competition, team and match models; schema and relationships | Complete |
| 03 | Provider-independent identities | External ID reference tables and database constraints | Complete |
| 04 | Provider adapters and DTOs | Provider contract, normalized DTOs, fake provider | Complete |
| 05 | Application synchronization | `SyncMatchesService`, create/update behavior and idempotency | Complete |
| 06 | Transaction safety | Per-match persistence transactions, rollback testing | Complete |
| 07 | Quality gates | PHPUnit, Pint, GitHub Actions with PostgreSQL 17 | Complete |
| 08 | REST API v1 | Filters, resource serialization, pagination, validation | Merged in PR #8 |
| 09 | Entity-resolution foundation | Resolvers, unresolved provider entities, recording and transaction boundary | Merged in PR #9 (GitHub verified) |
| 10 | Match-level fault isolation | `MatchSyncReport`, skip expected missing mapping, allow later matches to process | Merged in PR #9; user reported tests passing |
| 11A | Laravel database queue | `SyncProviderMatchesJob`, DI binding, reporting and DB worker | Merged in PR #10; verified from `origin/main` |
| 11B | Retry and failure lifecycle | Transient recovery and all 10 persistent-failure attempts, ending in `failed_jobs` | Implemented and tested on `feat/queue-reliability`; PR review and merge pending |
| 11C | Overlap protection | Provider/date middleware, real PostgreSQL lock contention, independent keys and stale-lock expiry | Base implementation merged in PR #10; integration tests on feature branch |
| 11D | Scheduler and production worker | Scheduled dispatch, process supervision and deployment | Next lesson; not implemented |

**GitHub state verified October 10, 2026:** fetched `origin/main` at `6a0db949c16d798e552b62b4fe76ed8364df7f5b`, including PR #10. The cloud checkout now uses `feat/queue-reliability` based on that commit. Earlier authorized system-font changes are preserved. This lesson's new tests and documentation belong to `feat/queue-reliability`; remote CI and merge must be verified separately.

**Open entity-resolution refinements:** manual mapping/conflict review, candidate ranking and atomic concurrent persistence remain future work. Resolvers currently use existing provider mappings and record unknown entities; they do not implement automatic contextual matching.

## 4. Current active milestone — review and deliver queue reliability

**Working branch:** `feat/queue-reliability`.

The job's policy is 10 attempts, a 60-second timeout, backoff of 10/30/60 seconds (60 repeated afterward), a provider/date overlap lock with 10-second release delay and 90-second expiry, and a default database queue `retry_after` of 90 seconds. Middleware releases count toward the same attempt budget. Expected mapping failures are unresolved results; unexpected exceptions propagate.

### Implemented in this lesson

- [x] Updated the cloud checkout to include merged PR #10 while preserving font changes.
- [x] Tested a job blocked by an actual database cache lock, with other providers/dates still proceeding.
- [x] Verified a released job consumes an attempt and cannot run before its delay expires.
- [x] Tested stale-lock expiry and release after successful/exceptional execution.
- [x] Tested transient provider failure followed by recovery without duplicate match records.
- [x] Tested all 10 persistent-failure attempts, actual backoff availability timestamps and a `failed_jobs` record.
- [x] Tested missing mappings being recorded and logged without retries.
- [x] Checked that timeout is shorter than queue reservation and lock expiry.
- [x] Updated README architecture, queue commands, test database guidance, limitations and next milestone.

These tests use Laravel's worker with a PostgreSQL database queue and database cache, in one process. They use controlled clock advancement rather than waiting in real time. They establish actual database lock contention, not simultaneous OS-worker execution or process timeout/termination behavior. The current Docker image lacks `pcntl`; production timeout enforcement and worker supervision remain follow-up work.

### Before delivery

- [x] Reviewed tests and ran Pint and the full PostgreSQL suite: **26 tests passed, 183 assertions**. Queue-only suite: **9 tests passed, 102 assertions**. GitHub CI for these local changes remains unverified.
- [ ] Open a PR for `feat/queue-reliability`, confirm CI, review and merge. Do not mark branch work as merged.

### Next focused lesson: scheduled dispatch

Design a scheduled sync for enabled providers. Decide frequency, target date and timezone, and duplicate-dispatch policy before writing code. Keep scheduler dispatch, queue execution locks and ingestion transactions as separate responsibilities. Test eligible-provider selection and dispatched provider/date values. Discuss `onOneServer` and scheduler `withoutOverlapping` without claiming either replaces the job lock. Keep production supervision as its own follow-up.

## 5. Next modules — dependency order

| Phase | Topic | Build outcome / tests | Portfolio value |
|---|---|---|---|
| 10 | Fault isolation and ingestion results | Completed basic `MatchSyncReport` and expected-mapping skip behavior; extend reporting when needed | Production ingestion resilience |
| 11 | Laravel queues and scheduling | **Active:** base job merged in PR #10; review/deliver reliability tests, then scheduler | Asynchronous processing |
| 12 | Real provider integration | HTTP client, timeout/retry policies, rate limiting, fixture-based adapter tests, secret management | External API engineering |
| 13 | Contextual entity resolution | Candidate ranking, aliases, competition/country context, confidence thresholds, review policy and audit history | Identity reconciliation |
| 14 | Redis and caching | Cache keys, TTL/invalidation, hot API reads, stale/fresh behavior, query impact measurements | Performance engineering |
| 15 | Database performance | Index analysis with EXPLAIN ANALYZE, efficient filtering/pagination, N+1 checks, realistic load test | Scalability evidence |
| 16 | API hardening | Stable contracts, documented errors, pagination guarantees, OpenAPI documentation, optional authentication/rate limits | Consumer-ready API |
| 17 | Deployment and infrastructure | Container release image, environment/secrets, managed PostgreSQL, AWS staging, health checks, migration strategy, rollback | Cloud/DevOps delivery |
| 18 | Observability | Structured logs, correlation IDs, metrics, error tracking, dashboards, alerting, ingestion success/failure visibility | Operability |
| 19 | Modern frontend | TypeScript frontend/dashboard or Web Component consuming our own API; loading/error states and tests | Full-stack proof |
| 20 | Real-time / advanced design | SSE/WebSockets for live scores, load and resilience scenarios, asynchronous architecture tradeoffs | Advanced system design |

Ordering may adapt to actual needs; do not introduce Redis, cloud costs, abstractions or queues without a concrete scenario. Favor one coherent pull request per milestone.

## 6. Repository and operations reference

Repository: https://github.com/dtsia20/sports-data-platform

Local dev:
```bash
docker compose up -d --build
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint --test
```

App: http://localhost:8000  
Adminer: http://localhost:8080  
API: http://localhost:8000/api/v1/matches

Git workflow:
```bash
git switch main
git pull origin main
git switch -c feat/<feature>
# code + tests + docs
git status
docker compose exec app ./vendor/bin/pint --test
docker compose exec app php artisan test
git add <specific paths>
git commit -m "..."
git push -u origin feat/<feature>
# PR → CI → review → merge
```

CI uses PHP 8.5 + PostgreSQL 17 on GitHub Actions. Compare branch and current files before changing code; branch may be behind `main`.

## 7. Definition of done for EVERY topic

- [ ] Behavior implemented and explained in terms of responsibility and tradeoffs.
- [ ] Relevant feature/unit/integration tests pass; known limits disclosed.
- [ ] Pint and full test suite pass; PostgreSQL integration where appropriate.
- [ ] Failure, idempotency, data integrity and concurrency considered.
- [ ] README updated **at topic completion** (current features/status, architecture, commands, next milestone).
- [ ] ADR/session notes updated for significant decisions.
- [ ] Feature branch PR reviewed; CI green and merge verified.
- [ ] The next lesson is written down with a concrete starting task.

## 8. Instructions for future ChatGPT sessions in this Project

Start by reading this roadmap and checking repo's latest `main` and feature branch, recent PR/CI status, and relevant current files. Do not assume a downloadable patch has been applied or merged. Continue from the remaining delivery tasks in Section 4, then the scheduled-dispatch lesson. Explain one meaningful architecture decision and its alternatives, implement incrementally with the user, test, and review. At the conclusion of each topic **update the root README** and refresh the statuses in this pipeline. Distinguish verified from planned work. Keep content accurate, practical and portfolio-oriented. Do not invent tool executions or successful deployments.
