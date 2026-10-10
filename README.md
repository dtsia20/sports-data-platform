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

Future iterations will introduce:
Scheduler
Queues
Workers
Redis
Caching
CI/CD
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
to the caller, allowing future background jobs to retry.

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

Each match synchronization runs inside a database transaction.
Conceptually:
begin transaction
     │
     ├── resolve mappings
     ├── update/create match
     ├── update/create provider reference
     │
commit

If synchronization fails, the transaction prevents partial match data from being persisted. Each match has its own transaction: earlier successful matches remain committed, and the exception stops processing subsequent matches.
This will become especially important when entity resolution and more complex ingestion logic are introduced.
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

The second command will eventually be used as part of CI.
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

Failure-path tests verify missing team and competition mappings are rejected before writes. A separate rollback test forces provider-reference creation to fail after the match is inserted and verifies that neither record remains.

The rollback test was also checked with the transaction temporarily removed: it failed because one match remained. Restoring the transaction makes it pass.
## Test Environment

Tests currently use:
SQLite in-memory

for fast execution.
Development uses:
PostgreSQL 17

This keeps the local test suite fast, but PostgreSQL-specific behavior will eventually also be tested in CI using a real PostgreSQL service.
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
Background jobs                Next
Queues                         Planned
Redis caching                  Planned
Performance testing            Planned
AWS deployment                 Planned
Observability                  Planned

## Roadmap

The next major milestones are:
Match synchronization tests
        ↓
CI / GitHub Actions
        ↓
GET /api/v1/matches
        ↓
Entity resolution
        ↓
Background jobs / queues
        ↓
Redis caching
        ↓
Performance testing
        ↓
AWS deployment
        ↓
Observability

## Next Milestone

The immediate next milestone is to establish CI with GitHub Actions.
The intended pipeline will eventually validate every pull request using:
Pull Request
     │
     ├── Composer install
     ├── PHP platform validation
     ├── Laravel Pint
     ├── PostgreSQL service
     ├── migrations
     └── automated tests
            │
            ▼
        merge allowed

After CI is established, development will continue with the first public API endpoint:
GET /api/v1/matches

## Status

This project is actively being developed as a production-oriented backend architecture and software-engineering portfolio project.
It is intentionally built incrementally so architectural decisions, trade-offs, tests, performance characteristics, and deployment decisions can be demonstrated throughout the repository history.
