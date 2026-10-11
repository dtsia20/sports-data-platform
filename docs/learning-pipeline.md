# Web Development Improvement — Architecture Learning Pipeline

**Updated:** 2026-10-11
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
| 11B | Retry and failure lifecycle | Transient recovery and all 10 persistent-failure attempts, ending in `failed_jobs` | Merged in PR #11; PostgreSQL integration suite verified |
| 11C | Overlap protection | Provider/date middleware, real PostgreSQL lock contention, independent keys and stale-lock expiry | Base implementation merged in PR #10; integration tests merged in PR #11 |
| 11D | Scheduled dispatch | Enabled-provider selection, today in UTC, five-minute schedule, scheduler locks | Implemented on `feat/scheduled-match-sync`; delivery pending |
| 11E | Worker lifecycle and production supervision | Process timeouts, restarts, supervision and deployment | Next lesson; not implemented |

**GitHub state verified October 11, 2026:** `origin/main` at `c686f52` includes merged PR #11. This lesson uses `feat/scheduled-match-sync` based on that commit. Queue reliability is delivered. Scheduler changes are on the feature branch; no scheduler PR, CI or merge is claimed yet.

**Open entity-resolution refinements:** manual mapping/conflict review, candidate ranking and atomic concurrent persistence remain future work. Resolvers currently use existing provider mappings and record unknown entities; they do not implement automatic contextual matching.

## 4. Current active milestone — scheduled match synchronization

**Working branch:** `feat/scheduled-match-sync`.

**Scenario:** every five minutes, enqueue today's UTC synchronization for each enabled provider. The learner chose to keep duplicate pending jobs allowed; queue deduplication is a separate future topic.

### Responsibilities and decisions

- Scheduler: timing, UTC, `onOneServer()` and `withoutOverlapping(5)` for dispatch.
- `sports:sync-matches`: compute one UTC date, select enabled providers in ID batches, dispatch jobs, report the count. No ingestion logic belongs here.
- `SyncProviderMatchesJob`: execute asynchronously with the existing provider/date lock and 10-attempt retry policy.
- `SyncMatchesService`: resolve identities, persist matches transactionally and report outcomes.
- Scheduler locks and job locks protect different operations. Neither deduplicates pending jobs. Shared database cache is required across scheduler/worker instances.
- The fake adapter has fixed fixture dates; today's requested date is orchestration data until real-provider integration exists.

### Implementation and validation

- [x] Verified PR #11 merged and created the scheduler feature branch.
- [x] Added the dispatch command and UTC five-minute schedule.
- [x] Added enabled/disabled selection, empty-selection, UTC-boundary and duplicate-dispatch tests.
- [x] Added actual database enqueueing and scheduled due/not-due tests, including scheduler lock configuration.
- [x] Updated README commands, architecture and operational limitations.
- [x] Scheduler suite: **6 tests passed, 28 assertions**. Full PostgreSQL suite: **32 tests passed, 211 assertions**. Pint passed across 63 PHP files.
- [x] `schedule:list` showed the five-minute event. `schedule:test --name=sports:sync-matches` invoked the registered event and persisted one development queue job; a real one-shot database worker completed it, leaving zero queued/failed jobs and one canonical fixture match.
- [ ] Commit, push, open PR, verify CI, review and merge before marking this lesson delivered.

Tests do not establish production cron operation, simultaneous multi-host scheduler behavior, or process timeout enforcement. The current PHP image lacks `pcntl`. No cron or production supervisor is installed by this lesson.

### Next focused lesson: worker process lifecycle and timeouts

Add and verify `pcntl` support, demonstrate an enforced job timeout and recovery, and discuss process supervision and worker restarts on deployment. Keep operational supervision separate from scheduler timing. Then move to real-provider HTTP timeouts, retry policies, rate limits and fixture-based adapter tests.

## 5. Next modules — dependency order

| Phase | Topic | Build outcome / tests | Portfolio value |
|---|---|---|---|
| 10 | Fault isolation and ingestion results | Completed basic `MatchSyncReport` and expected-mapping skip behavior; extend reporting when needed | Production ingestion resilience |
| 11 | Laravel queues and scheduling | **Active:** queue reliability merged in PR #11; review/deliver scheduled dispatch, then worker lifecycle | Asynchronous processing |
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

Start by reading this roadmap and checking repo's latest `main` and feature branch, recent PR/CI status, and relevant current files. Do not assume a downloadable patch has been applied or merged. Continue from the remaining delivery tasks in Section 4, then the worker-lifecycle lesson. Explain one meaningful architecture decision and its alternatives, implement incrementally with the user, test, and review. At the conclusion of each topic **update the root README** and refresh the statuses in this pipeline. Distinguish verified from planned work. Keep content accurate, practical and portfolio-oriented. Do not invent tool executions or successful deployments.
