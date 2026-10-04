We are continuing an architecture-focused portfolio project called sports-data-platform.

Repository:
dtsia20/sports-data-platform

Goal:
Build a production-oriented Laravel sports-data aggregation platform while using each feature as a software architecture lesson.

Please inspect the current repository before making changes.

Current stack:
- PHP 8.5
- Laravel 13
- PostgreSQL 17
- Docker Compose
- Adminer
- PHPUnit
- Laravel Pint

Architecture:
External Provider
→ Provider Adapter
→ Normalized DTOs
→ Application Services
→ Canonical Domain Model
→ PostgreSQL
→ API

Core principles:
- Canonical entities must be provider-independent.
- External provider schemas must stop at the adapter boundary.
- DTOs carry normalized data.
- Eloquent models represent persisted entities.
- Application services orchestrate use cases.
- Provider adapters translate external data.
- Do not introduce abstractions or infrastructure before there is a real need.
- Prefer small feature branches and PRs.
- Keep main stable.
- Explain architectural tradeoffs before large changes.

Current domain entities:
- SportsDataProvider
- Competition
- Team
- SportsMatch

Provider mapping models:
- ProviderCompetitionReference
- ProviderTeamReference
- ProviderMatchReference

Current normalization layer:
- TeamData
- CompetitionData
- MatchData
- SportsDataProviderInterface
- FakeProviderAAdapter

Current feature branch:
feat/match-sync

Current feature:
SyncMatchesService

SyncMatchesService currently:
- receives SportsDataProviderInterface
- gets MatchData[]
- resolves competition mapping
- resolves home/away team mappings
- creates a SportsMatch if provider match mapping does not exist
- updates an existing SportsMatch if it already exists
- creates ProviderMatchReference
- runs each match sync inside DB::transaction()

Tests currently passing (6 tests, 27 assertions):
1. mapped provider data creates a match
2. running sync twice does not duplicate a match
3. existing match is updated when provider data changes
4. missing team mapping throws and writes nothing
5. missing competition mapping throws and writes nothing
6. reference creation failure rolls back an already-inserted match

The rollback test uses a temporary model event listener and restores the original
event dispatcher in finally. Removing the service transaction makes this test
fail with one match remaining; restoring it makes the test pass.
Missing-mapping tests exercise validation before writes, not transaction rollback.
Transactions are per match; earlier successful matches remain committed on a later failure.

Laravel Boost is installed as a dev dependency and Codex guidelines are generated.
Use Docker for PHP/Composer: the host PHP is 8.3 but the app requires PHP 8.5.
Tests currently use SQLite in memory; PostgreSQL-specific testing remains for CI.

Next lesson / task:
Review the completed match-sync changes and prepare the PR.
Then implement CI with GitHub Actions, followed by GET /api/v1/matches.

Important teaching style:
Do not just generate large amounts of code.
First explain the architecture decision and tradeoffs.
Ask me to reason about important design choices when useful.
Then implement in small steps.
Review the result after each meaningful change.
Keep the project portfolio-oriented and production-minded.

Before changing anything, inspect:
- app/Application/Matches/Services/SyncMatchesService.php
- tests/Feature/Application/Matches/SyncMatchesServiceTest.php
- app/Domain/
- app/Infrastructure/SportsData/
- database/migrations/
- README.md
- docs/

Then summarize what you found and propose only the next small step.