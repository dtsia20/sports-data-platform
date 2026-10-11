# Sports Data Platform

A production-oriented sports data aggregation platform built with Laravel.

The project is being developed incrementally as a software architecture and backend engineering portfolio project.

Its goal is to demonstrate how multiple external sports-data providers can be normalized into a provider-independent domain model, synchronized into a canonical database, and eventually exposed through a consistent API.

---

## Project Goals

The project is intended to explore practical software-engineering topics including:

- Domain modeling
- Laravel application architecture
- External API integrations
- Adapter architecture
- Provider-independent entity mapping
- PostgreSQL data modeling
- Entity resolution
- Application services
- Idempotent synchronization
- Database transactions
- Automated testing
- Queues and background processing
- Redis caching
- REST API design
- CI/CD
- AWS deployment
- Observability
- Performance testing

Infrastructure and abstractions are introduced only when there is a concrete requirement for them.

---

## Architecture

The current architecture is:

```text
External Sports Providers
          │
          ▼
    Provider Adapters
          │
          ▼
     Normalized DTOs
          │
          ▼
   Application Services
          │
          ▼
   Canonical Domain Model
          │
          ▼
      PostgreSQL
          │
          ▼
          API
```

Database queue jobs and development workers are implemented. Future iterations will introduce:
Production worker supervision
Redis
Caching
AWS
Observability
WebSockets / SSE

## Core Design Principle

External provider schemas must not leak into the application.
For example, the same real-world team may have different identifiers across providers:
Canonical Team: Arsenal

Provider A → 100
Provider B → ARS
Provider C → arsenal-fc-44

The canonical Team entity therefore does not contain a provider-specific ID.
Instead, provider identifiers are stored separately through mapping tables:
provider_team_references
provider_competition_references
provider_match_references

This allows multiple external providers to map to the same canonical entity.
## Current Stack

- PHP 8.5
- Laravel 13
- PostgreSQL 17
- Docker Compose
- Adminer
- PHPUnit
- Laravel Pint
- Composer
## Project Structure

The application currently follows a lightweight layered architecture:
app/
├── Application/
│   └── Matches/
│       └── Services/
│           └── SyncMatchesService.php
│
├── Domain/
│   ├── Competitions/
│   │   ├── DTOs/
│   │   └── Models/
│   │
│   ├── Matches/
│   │   ├── Contracts/
│   │   ├── DTOs/
│   │   └── Models/
│   │
│   ├── Providers/
│   │   └── Models/
│   │
│   └── Teams/
│       ├── DTOs/
│       └── Models/
│
├── Infrastructure/
│   └── SportsData/
│       └── FakeProviderA/
│
└── Http/

The goal is not to implement a strict version of Clean Architecture, but to maintain clear boundaries between:
HTTP
Application
Domain
Infrastructure

## Canonical Domain Model

The platform currently contains the following canonical entities:
SportsDataProvider
Competition
Team
SportsMatch

Provider mappings are represented by:
ProviderCompetitionReference
ProviderTeamReference
ProviderMatchReference

## Match Model

A canonical match contains:
id
competition_id
home_team_id
away_team_id
starts_at
status
home_score
away_score
created_at
updated_at

Relationships:
SportsMatch
├── belongsTo Competition
├── belongsTo home Team
├── belongsTo away Team
└── hasMany ProviderMatchReference

## Provider Mapping

External IDs are isolated from canonical domain entities.
Example:
Team #12
Name: Arsenal

can be mapped as:
Provider A → 100
Provider B → ARS
Provider C → arsenal-fc

without modifying the canonical teams table.
The current mapping tables are:
provider_competition_references
provider_team_references
provider_match_references

Each mapping connects:
provider
+
external identifier
+
canonical entity

## Provider Normalization

External providers may expose completely different payload structures.
Each provider adapter converts its external format into normalized internal DTOs.
Current normalized DTOs:
TeamData
CompetitionData
MatchData

Example:
Provider JSON
     │
     ▼
FakeProviderAAdapter
     │
     ▼
MatchData
├── externalId
├── CompetitionData
├── home TeamData
├── away TeamData
├── startsAt
├── status
├── homeScore
└── awayScore

## Provider Contract

Every sports-data provider must implement:
interface SportsDataProviderInterface
{
    /**
     * @return array<MatchData>
     */
    public function getMatches(DateTimeImmutable $date): array;
}

The application therefore depends on the contract rather than a concrete provider.
Current implementation:
FakeProviderAAdapter

The fake provider exists to validate the architecture before introducing a real external API.
## Match Synchronization

The application layer now contains:
SyncMatchesService

Its responsibility is to take normalized provider data and synchronize it into the canonical database.
Current flow:
SportsDataProviderInterface
        │
        ▼
     MatchData[]
        │
        ▼
SyncMatchesService
        │
        ├── resolve competition mapping
        ├── resolve home-team mapping
        ├── resolve away-team mapping
        │
        ├── locate existing provider match reference
        │
        ├── create or update canonical match
        │
        └── create provider match reference

The service deliberately does not:
parse provider JSON
perform HTTP requests
guess entity identity
manage caching
expose HTTP endpoints

Those responsibilities belong to other layers.
## Match-Level Fault Isolation

The synchronization process handles expected data-resolution
failures independently for each match.

If a team or competition mapping is missing:

- The unknown provider entity is recorded for resolution.
- The affected match is skipped.
- Later matches continue processing.
- The synchronization result records the unresolved count.

SyncMatchesService returns a MatchSyncReport containing:

- processed: Total matches handled
- succeeded: Successfully synchronized matches
- unresolved: Matches skipped because mappings are missing

Unexpected failures are not silently ignored.

Database errors and other unexpected exceptions propagate
to the caller, allowing queued background jobs to retry.

Match persistence remains protected by database transactions.

This behavior is verified with mixed-batch integration tests.
## Idempotent Synchronization

Synchronization is designed to be idempotent.
Running:
sync
sync
sync

for the same provider match should still produce:
1 canonical match
1 provider match reference

Existing matches are located using:
provider_id + external_id

and updated rather than duplicated.
This is important because synchronization will eventually run repeatedly through scheduled jobs and background workers.
## Updating Live Data

Provider data may change over time.
For example:
First synchronization

Arsenal 1 - 1 Chelsea
status: live

Later:
Second synchronization

Arsenal 2 - 1 Chelsea
status: finished

The synchronization service updates the existing canonical match instead of creating a new record.
## Transaction Boundaries

Mappings are resolved before the match-persistence transaction. Unknown entities are recorded outside that transaction, so their resolution records survive a skipped match or a later persistence failure.

Each mapped match then uses its own transaction to create/update the canonical match and its provider reference. If reference persistence fails, the match write rolls back. Earlier successful matches remain committed. Expected missing mappings skip only that match; unexpected exceptions stop the batch and propagate to the queue worker.

## Entity Resolution

External entities must not be automatically matched using names alone.
For example:
Arsenal
Arsenal FC
ARSENAL
Arsenal F.C.

may represent the same entity, while other identical names could represent different teams.
Future entity resolution may consider:
existing provider mapping
competition
country
competition membership
known aliases
name similarity
event context

The expected resolution model is:
high confidence
    ↓
automatic mapping

medium confidence
    ↓
manual review

low confidence
    ↓
unresolved

Team identity and match identity are treated as separate problems.
## Local Development

Clone the repository:
git clone https://github.com/dtsia20/sports-data-platform.git
cd sports-data-platform

Create the environment file:
cp .env.example .env

Build and start Docker:
docker compose up -d --build

Install Composer dependencies if necessary:
docker compose run --rm app composer install

Generate the Laravel application key:
docker compose exec app php artisan key:generate

Run migrations:
docker compose exec app php artisan migrate

Seed development data:
docker compose exec app php artisan db:seed

Application
Laravel is available at:
http://localhost:8000

Adminer
Adminer is available at:
http://localhost:8080

Connection:
System: PostgreSQL
Server: postgres
Username: sports_data
Password: sports_data
Database: sports_data

## Development Commands

Laravel:
docker compose exec app php artisan ...

Composer:
docker compose exec app composer ...

PostgreSQL:
docker compose exec postgres ...

Verify PHP platform requirements:
docker compose exec app composer check-platform-reqs

## Code Style

The project uses Laravel Pint.
Format the codebase:
docker compose exec app ./vendor/bin/pint

Verify formatting without modifying files:
docker compose exec app ./vendor/bin/pint --test

The formatting check also runs in CI.
## Testing

Tests run with PHPUnit through Laravel:
docker compose exec app php artisan test

Match synchronization tests:
docker compose exec app php artisan test \
    tests/Feature/Application/Matches/SyncMatchesServiceTest.php

Current synchronization tests verify:
✓ mapped provider data creates a canonical match

✓ repeated synchronization does not create duplicates

✓ existing matches are updated when provider data changes

Failure-path tests verify missing team and competition mappings are recorded and the affected matches are skipped before match writes. A separate rollback test forces provider-reference creation to fail after the match is inserted and verifies that neither record remains.

The rollback test was also checked with the transaction temporarily removed: it failed because one match remained. Restoring the transaction makes it pass.
## Test Environment

Development and CI use PostgreSQL 17. Run tests against a separate disposable database such as `sports_data_test`; `RefreshDatabase` and the queue integration suite can rebuild schema. Never point tests at the development database.

```bash
docker compose exec postgres createdb -U sports_data sports_data_test # once
docker compose exec -e APP_ENV=testing -e DB_DATABASE=sports_data_test app php artisan test
```

Queue integration tests use the actual database queue, Laravel worker, and database cache locks. They rebuild migrations without wrapping each test in a transaction: PostgreSQL lock contention catches a unique-key violation, which would abort an enclosing test transaction. Other suites continue using `RefreshDatabase`.

## Background Synchronization

`SyncProviderMatchesJob` queues a provider ID and date. The worker resolves `SyncMatchesService` through dependency injection; ingestion and entity resolution stay in the application services. For this iteration, `SportsDataProviderInterface` is bound to `FakeProviderAAdapter`.

Use the repository's local defaults:

```dotenv
QUEUE_CONNECTION=database
CACHE_STORE=database
DB_QUEUE_RETRY_AFTER=90
```

Both workers must use the same queue database and shared database cache. Run migrations, seed the fake-provider mappings, and start a development worker:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
docker compose exec app php artisan queue:work database --sleep=1 --timeout=60
```

For an interactive development dispatch, use `php artisan tinker` inside the app container. Retrieve the fake provider and dispatch:

```php
$provider = App\Domain\Providers\Models\SportsDataProvider::where('slug', 'fake-provider-a')->firstOrFail();
App\Jobs\SyncProviderMatchesJob::dispatch($provider->id, '2026-10-04');
```

The job logs `processed`, `succeeded`, and `unresolved`. Missing mappings produce unresolved results and finish normally. Unexpected exceptions retry, with delays of 10 seconds, 30 seconds, then 60 seconds for subsequent retries. A persistent exception fails on attempt 10 and is stored in `failed_jobs`.

```bash
docker compose exec app php artisan queue:failed
docker compose exec app php artisan queue:retry <failed-job-uuid>
docker compose exec app php artisan test tests/Feature/Jobs
```

Overlap protection uses a lock for the job class plus `(providerId, date)`. A contending job is released for 10 seconds; unrelated providers and dates can run. Locks expire after 90 seconds to recover from interrupted workers. The 60-second job timeout is shorter than both lock expiry and the default 90-second queue reservation window. Keep these relationships valid when changing environment settings. Enforcing process timeouts requires PHP's `pcntl` extension, now installed by the Dockerfile and enabled in CI. The optional Compose background profile manages development worker processes as described below.

The 10-attempt budget includes releases caused by overlap contention. It gives retries some room, but cannot guarantee completion under sustained contention. Duplicate dispatch is allowed and serialized for the same provider/date; it is not deduplicated. Sequential synchronization is idempotent. Different dates can still refer to the same provider match, so this lock does not establish full concurrent persistence safety.

The integration suite verifies real lock contention, unrelated jobs proceeding, lock expiry, transient recovery without duplicate matches, the complete 10-attempt failure lifecycle, unresolved mappings without retries, and timeout configuration relationships. It advances Laravel's clock instead of sleeping. The queue reliability suite does not simulate process termination or simultaneous OS workers; the separate worker lifecycle suite verifies real timeout termination and replacement processes. Use a continuous worker to observe delayed retries manually; `--stop-when-empty` can exit before a delayed retry becomes available. Long-lived workers must be restarted after code changes (`php artisan queue:restart`). Scheduled dispatch is implemented below. Optional Compose supervision is implemented below; production deployment is not validated.

## Scheduled Synchronization

Every 30 minutes, Laravel runs `sports:sync-matches`. This command selects enabled providers and dispatches one `SyncProviderMatchesJob` for each, using today's date in UTC. The date is calculated once per invocation to keep the batch consistent across midnight. Providers are read in ID batches, and the command performs no external HTTP requests or match persistence. The configured queue connection must be `database` to execute asynchronously.

```text
Scheduler (every 30 minutes, UTC)
    → sports:sync-matches (select enabled providers, dispatch jobs)
    → database queue
    → SyncProviderMatchesJob (provider/date execution lock)
    → SyncMatchesService (resolve identities, persist matches, report)
```

Inspect the schedule or dispatch manually:

```bash
docker compose exec app php artisan schedule:list
docker compose exec app php artisan sports:sync-matches
# Run the registered event immediately, even outside its due time:
docker compose exec app php artisan schedule:test --name=sports:sync-matches
```

For development, run these in separate terminals after migrations and seeding:

```bash
docker compose exec app php artisan schedule:work
docker compose exec app php artisan queue:work database --sleep=1 --timeout=60
```

In production, configure the hosting platform to invoke `php artisan schedule:run` in the application directory every minute, and supervise the queue worker separately. This repository does not install cron or a production process supervisor. Stop development processes with Ctrl+C; queued jobs remain in the database. Running `schedule:run` manually outside a 30-minute boundary correctly does nothing.

The schedule uses `onOneServer()` to choose one dispatcher per due tick across scheduler instances, and `withoutOverlapping(5)` to avoid overlapping dispatch commands. Both rely on the shared cache; use `CACHE_STORE=database` and the same database/cache prefix across hosts. The five-minute dispatch lock expiry allows recovery after a crashed dispatcher, and assumes the dispatch command finishes within that window. These locks protect the dispatch command; the job's provider/date lock protects synchronization. Manual command calls do not acquire scheduler locks.

Duplicate pending jobs remain allowed by design. If a previous sync is delayed, the next tick can enqueue another. The job lock prevents simultaneous execution for the same provider/date, but does not deduplicate the queue or eliminate every persistence race. Partial dispatch before an exception can also leave some jobs queued; a later tick can dispatch them again. Observe queue backlog before deciding whether unique jobs or a different dispatch policy is needed.

The fake adapter still returns its fixed October 4 fixture regardless of requested date. Scheduling today's date proves orchestration; it does not establish a real provider's date-filtering behavior.

Tests cover enabled-provider selection, no enabled providers, UTC date boundaries, duplicate dispatch policy, actual database enqueueing, and 30-minute due/not-due boundaries with scheduler lock configuration:

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=sports_data_test app php artisan test tests/Feature/ScheduledMatchSyncTest.php
```

These tests do not claim multi-host scheduler contention or production cron/process supervision has been exercised.

## Worker Lifecycle and Process Supervision

The `background` Compose profile starts managed worker and scheduler containers alongside the application. They share the application's PHP build and source mount, but expose no HTTP ports. PHP includes `pcntl` for worker signals and timeout alarms.

```bash
docker compose --profile background up -d --build app postgres worker scheduler
docker compose logs -f worker scheduler
# After deploying code, finish the current job and replace the worker:
docker compose exec app php artisan queue:restart
# Explicitly stop the background services:
docker compose --profile background stop worker scheduler
```

`restart: unless-stopped` restarts a worker after both failure and a successful `queue:restart` exit. An `on-failure` policy alone would leave a gracefully exited worker stopped. Explicit Compose stop commands suppress automatic restart. `init: true` forwards signals and reaps subprocesses. The worker uses `--max-time=3600` to recycle its process after about an hour; Laravel checks this between jobs, not by interrupting the current job. Both services allow 75 seconds for graceful shutdown. The worker's 60-second job timeout is shorter than that grace period and the database's 90-second reservation window.

Three clocks govern failure recovery:

| Setting | Current value | Purpose |
|---|---|---|
| Job timeout | 60 seconds | Worker terminates execution that exceeds its budget |
| Database queue `retry_after` | 90 seconds | An abandoned reservation becomes eligible for another worker |
| Provider/date lock expiry | 90 seconds | Stale execution locks eventually allow recovery |

A timeout kills the worker process; it does not follow the ordinary exception backoff path. The abandoned job waits for reservation expiry, while its lock survives until lock expiry. A replacement worker can retry it. The sync job keeps its 10-attempt budget; persistent timeouts eventually fail the job. Cleanup in PHP `finally` blocks cannot be assumed after a hard process kill. External side effects and cross-date persistence races still need their own safety policy.

`queue:restart` writes a shared-cache marker. A long-lived worker finishes its current job, sees the marker, and exits successfully. Compose then starts a new PHP process that loads the current code. All workers and the restart command must use the same cache store and prefix. Source mounts and this profile are useful for development; a production release still needs immutable images, migration/deployment coordination, readiness checks, and a chosen platform scheduler. Avoid simultaneously running manual workers or `schedule:work` terminals unless you intend multiple instances.

The lifecycle test suite launches real PHP daemon processes against the disposable PostgreSQL test database. A test-only fixture uses a one-second timeout, two-second lock and three-second reservation window to verify process death, reservation/lock survival, replacement retries, terminal failure, and subsequent healthy work. Another test verifies graceful cache-based restart and a replacement worker. The fixture lives under tests and is never dispatched by the application. Unlike the clock-controlled queue tests, these checks wait a few real seconds and require `pcntl`. `queue:work --once` is not used for timeout testing because Laravel installs timeout alarms in its daemon loop.

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=sports_data_test app php artisan test tests/Feature/Jobs/WorkerLifecycleTest.php
```

The cloud runtime uses its existing local image/network override. The worker extension was compiled and verified there; the regular Compose configuration was also validated. Production deployment and multi-host recovery have not been exercised.

## Seed Data

The project includes development seed data.
Current example:
Provider:
Fake Provider A

Competition:
Premier League

Teams:
Arsenal
Chelsea

Match:
Arsenal 2 - 1 Chelsea

External IDs:
Competition: 55

Arsenal: 100
Chelsea: 101

Match: 14567

Seeders use updateOrCreate() so they can safely be executed multiple times.
## Docker Environment

Current Docker services:
app
postgres
adminer

PostgreSQL uses:
postgres:17-alpine

The project intentionally uses PostgreSQL 17 instead of PostgreSQL 18 for now to avoid unnecessary Docker volume-layout differences while the architecture is still evolving.
## Git Workflow

The repository uses main as the stable branch.
Development follows short-lived feature branches.
Example:
main
  │
  ├── feat/provider-normalization
  │
  ├── feat/match-sync
  │
  ├── feat/api-v1
  │
  └── feat/redis-cache

Workflow:
main
 ↓
feature branch
 ↓
implementation
 ↓
tests
 ↓
formatting
 ↓
push
 ↓
GitHub Pull Request
 ↓
review
 ↓
merge into main

main should always represent a stable state of the project.
## Documentation

Architecture and development notes are stored under:
docs/
├── README.md
├── sessions/
└── adr/

## Session Notes

Session notes record:
what was implemented
what decisions were made
what was learned
what comes next

This also makes it easier for another developer or coding agent to continue the project with the correct context.
## Architecture Decision Records

Important architectural decisions are documented as ADRs.
Current decisions include:
ADR-001
Provider-independent canonical entities

ADR-002
Contextual entity resolution

## Development Principles

The project follows several architectural principles:
1. Canonical entities remain provider-independent.
2. External provider schemas stop at the adapter boundary.
3. Interfaces describe behavior.
4. DTOs transport normalized data.
5. Eloquent models represent persisted entities.
6. Application services orchestrate use cases.
7. Provider adapters translate external representations.
8. Migrations are the source of truth for database schema.
9. Seeders and factories create development/test data.
10. Synchronization operations should be idempotent.
11. Database transactions protect multi-step writes.
12. Infrastructure should be introduced only when justified.
13. Feature branches should represent coherent milestones.
14. Meaningful changes should be merged through pull requests.
15. Important architecture decisions should be documented.
## Current Project Status

Laravel foundation             ✓
Docker environment             ✓
PostgreSQL                     ✓
Canonical domain model         ✓
Provider mappings              ✓
Provider normalization         ✓
Match synchronization          ✓
Idempotency tests              ✓
Match update tests             ✓

Failure-path tests             ✓
CI / GitHub Actions            ✓
API v1                         ✓
Date filtering                 ✓
Additional API filters         ✓
Entity resolution              In progress
Match-level fault isolation    ✓
Background jobs                ✓
Database queues / overlap lock ✓
Queue reliability tests        ✓ (merged in PR #11)
Scheduler                      Implemented on feature branch
Worker timeout / lifecycle     Implemented on feature branch
Compose background supervision Implemented on feature branch
Production deployment          Planned
Redis caching                  Planned
Performance testing            Planned
AWS deployment                 Planned
Observability                  Planned

## Roadmap

The remaining milestones are:

```text
Review and deliver scheduled dispatch
    → Real-provider HTTP integration
    → Contextual entity resolution and review
    → Redis caching and database performance
    → API hardening
    → Deployment and production supervision
    → Observability
```

## API-Football lesson: HTTP boundary

`ApiFootballAdapter` implements `SportsDataProviderInterface` and converts the `/fixtures` response into the existing domain DTOs. Provider IDs become strings, provider statuses become domain statuses, and nullable goals remain nullable. Invalid payloads and unknown statuses fail explicitly instead of silently losing fixtures.

Set `API_FOOTBALL_KEY` in your private `.env` for eventual live use; the committed `.env.example` contains only an empty placeholder. The adapter uses a 3-second connection timeout and a 10-second request timeout. Connection failures and HTTP 5xx responses get one retry (two total attempts); HTTP 401 and 429 are surfaced without immediate retries. HTTP 200 responses containing API errors also fail. HTTP timeouts remain below the job's 60-second timeout.

Run the lesson without an API key or quota usage:

```bash
docker compose exec app php artisan test --compact tests/Feature/Infrastructure/ApiFootballAdapterTest.php
```

The tests use `Http::fake()` and the saved 1,159-fixture response. `ProviderSyncServiceFactory` selects an adapter by provider slug; unsupported providers fail explicitly. Fake-provider tests and synchronization remain isolated from live API-Football calls.

### Try real data locally

After the normal dependency, database migration and seed setup, put the following settings in your private `.env`:

```dotenv
API_FOOTBALL_KEY=your-private-key
API_FOOTBALL_DAILY_LIMIT=90
API_FOOTBALL_SCHEDULED=false
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Import the saved sample's competition/team mappings, then synchronize a date supported by your account:

```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan sports:import-api-football-mappings tests/Fixtures/api-football/fixtures-2026-10-11.json
docker compose exec app php artisan sports:sync-api-football --date=2026-10-11
```

Check `http://localhost:8000/api/v1/matches?date=2026-10-11`. The command reports processed, succeeded and unresolved counts. It shares the queued job's provider/date overlap lock. Unmapped live entities are recorded and skipped; save another successful response and explicitly import its mappings if needed. The lesson importer uses stable slugs such as `api-football-team-126`, preserves existing references, and deliberately creates separate provider-scoped entities instead of guessing cross-provider identity from names. Canonical cross-provider reconciliation remains a later lesson.

### Enable the 30-minute schedule

After a manual sync succeeds, set `API_FOOTBALL_SCHEDULED=true` in `.env`, then run:

```bash
docker compose exec app php artisan config:clear
docker compose exec app php artisan sports:api-football-schedule on
docker compose --profile background up -d worker scheduler
docker compose exec app php artisan queue:restart
docker compose exec app php artisan schedule:list
docker compose logs --tail=50 worker
```

The schedule runs at minute 0 and 30 in UTC. One fixtures request per run means 48 normal requests per day. To disable further scheduled dispatch, run `php artisan sports:api-football-schedule off` inside the app container; already queued jobs remain executable. Manual sync is available even while scheduled dispatch is disabled.

A shared-cache lock serializes request-budget reservations. The default ceiling is 90 requests per UTC calendar day, capped at 90 even if configured higher, leaving nominal room for external testing. Every attempted HTTP request, including retries and failed connections, consumes a reservation. A 429 or an API-error body closes the local budget for the rest of that UTC day; missing keys, budget exhaustion and API-error bodies fail queued jobs without retries. Authentication/client HTTP errors also fail immediately; server/connection failures retain bounded HTTP retries and existing queue retries, all subject to the budget.

This counter tracks this application's attempts only. Postman calls, other deployments with separate cache prefixes, and the provider's actual reset window are outside that count. Keep one shared persistent database cache across app, worker and scheduler, do not clear it to bypass the budget, and verify your account's remaining quota/reset policy. Tests prove the local behavior; live requests require your private key and supported plan coverage.

## Entity resolution lesson: explicit review

Provider IDs identify records within a provider; canonical IDs identify records inside our platform. Two records called “United” are not enough evidence that they identify the same club. This lesson keeps missing mappings pending and records competition/country/season context from API-Football for a reviewer. A competition country is context, not proof of team nationality.

Apply the new audit-table migration, then inspect pending records:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan sports:unresolved --provider=api-football --limit=20
```

Use the existing `teams` or `competitions` table (for example through Adminer) to inspect the proposed canonical record and its existing provider references. Verify identity from provider IDs and trusted contextual evidence. Approve a specific pending ID and the canonical ID for its entity type:

```bash
docker compose exec app php artisan sports:resolve-entity 12 34 --reviewer="your-name" --reason="Verified the club using competition context and provider identity."
docker compose exec app php artisan sports:sync-api-football --date=2026-10-11
```

The IDs above are examples. The command does not create canonical records or fetch remote data. It creates the provider reference, marks the pending record resolved, and snapshots the reviewer label, reason, external name and context in `provider_entity_resolutions`, all within one transaction. Repeating the same target is idempotent and preserves the original decision. Missing targets, conflicting references and reassignment of an approved record are rejected. A canonical record can have references from multiple providers; the existing constraints permit one external ID per canonical entity within each provider.

Review uses a row lock on the pending record and database uniqueness constraints; conflicting writes roll back. Audit rows prevent deletion of their pending record through a foreign-key restriction. CLI reviewer labels are operator-supplied metadata, not authenticated identities. This is an approval workflow, not an automatic matcher: aliases, canonical country/season metadata, authenticated review, corrections and merging existing duplicate canonical records remain later work. Candidate ranking is described below. Existing sample-imported mappings are not rewritten.

Run the focused lesson tests:

```bash
docker compose exec app php artisan test --compact tests/Feature/ProviderEntityResolutionTest.php
```

### Candidate ranking exercise

After listing pending entities, use a pending ID to inspect suggestions:

```bash
docker compose exec app php artisan sports:entity-candidates 12 --limit=10
```

This command reads local records only and makes no HTTP requests or mapping changes. It shows canonical IDs, scores, confidence labels and the evidence behind each suggestion. Verify a candidate and then use `sports:resolve-entity` to approve it explicitly.

| Evidence | Points / behavior |
|---|---|
| Exact normalized name | 50 |
| Shared name tokens | Up to 50 using shared/total distinct tokens; names below 25 are omitted |
| Team appears in a match in the provider's mapped canonical competition | +40; historical observation, current season membership unverified |
| Country or season | No points: canonical records do not store this metadata |
| Canonical target already mapped by this provider | Excluded to respect reference uniqueness |

Names are lowercased, transliterated to ASCII and normalized for punctuation/whitespace. Club suffixes are not stripped and aliases are not guessed. Exact normalized names alone are weak suggestions. Scores of 65–89 are tentative and 90 is strong; these are deterministic review labels, not identity probabilities or approval thresholds. Equal scores are marked ambiguous, including ties outside the output limit. Tied results sort by canonical ID for stable presentation only.

The service reads canonical records in batches and retains only the requested best results (maximum 20). Competition-history checks are SQL existence checks included in each batch, avoiding a query per candidate. Ranking still scans eligible canonical records; measure and optimize that scan before using it at larger scale. Name normalization and historical match context can both mislead, so explicit review remains required. Competition candidates currently rely on names alone and cannot receive a strong confidence label.

The tests cover contextual ranking, accent/case/punctuation normalization, hidden ties, provider conflicts, weak partial matches, missing context and a command that performs no writes. Curated aliases and richer canonical context are the next exercise.

## Next Milestone

The background-processing milestone was merged in PR #12. API-Football adapter selection, manual sync, explicit mapping import, request budgets and opt-in 30-minute scheduling are implemented on the feature branch. PR #14 is merged, and the learner reports successful live synchronization locally. The next exercise implements contextual pending records and audited manual mapping approval on `feat/entity-resolution-review`. Candidate suggestions and evidence-based review labels are now implemented; curated aliases and richer canonical context follow; production deployment remains a later milestone.

The detailed lesson roadmap and verified delivery status are in [the learning pipeline](docs/learning-pipeline.md). The lifecycle design is recorded in [ADR-003](docs/adr/003-background-processing-lifecycle.md).

## Status

This project is actively being developed as a production-oriented backend architecture and software-engineering portfolio project.
It is intentionally built incrementally so architectural decisions, trade-offs, tests, performance characteristics, and deployment decisions can be demonstrated throughout the repository history.
