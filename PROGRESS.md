# Implementation Progress

Durable state for multi-session work. Read this + `CHANGELOG.md` to resume.

---

## Release strategy

**Phases 1, 2, and 3 ship as a single tagged release.** CHANGELOG uses `[Unreleased]` as a placeholder; Phase 2 + 3 entries are appended before tagging. Decision date: 2026-04-25.

---

## Current state

| Phase | Status | Notes |
|---|---|---|
| Phase 0 — Foundation | ✅ Complete | Laravel 13 + PHP 8.1 floor, sibling demo app, Testbench scaffold |
| Phase 1 — Critical bug fixes | ✅ Complete + QA close-out | 29 tests green, 8 fixes + side fixes |
| Phase 2 — Security & hygiene | ⏭️ Next | Not started |
| Phase 3 — Supervisor health inspection | 🔜 After Phase 2 | Not started |
| Phase 4 — Orphaned reserved jobs | ⏳ Later | |
| Phase 5 — Queue depth view | ⏳ Later | |
| Phase 6 — Operational tools | ⏳ Later | |
| Phase 7 — Polish | ⏳ Later | |

---

## Phase 0 — Foundation (done)

- `composer.json`: PHP ^8.1, `illuminate/*` ^9–^13, Testbench ^7–^11, PHPUnit up to ^12. `composer test` script added.
- Sibling demo app at `../laravel-horizon-running-jobs-demo` — fresh Laravel 13, consumes package via path-symlink repo.
- Horizon installed + configured in demo with `gethostname()` as supervisor key.
- 6 dummy jobs (`FastJob`, `MediumJob`, `SlowReportJob`, `StuckJob`, `FlakyJob`, `MemoryHogJob`).
- 2 demo commands (`demo:dispatch-workload`, `demo:simulate-orphan`).
- Test scaffold: `phpunit.xml.dist`, `tests/TestCase.php`, `tests/Unit/`, `tests/Feature/`.
- Demo README with usage scenarios.
- Widget page wired at `/running-jobs` in the demo.

**No CI workflow** — removed by user decision. Tests run locally via `vendor/bin/phpunit`.

---

## Phase 1 — Critical bug fixes (done)

### 8 fixes shipped (all tests green)

| # | Fix | File |
|---|---|---|
| 1 | `clearCache()` via epoch versioning | `src/RunningJobsManager.php` |
| 2 | Controller defaults to `getServerIdentifier()` not `gethostname()` | `src/Controllers/RunningJobsController.php` |
| 3 | Zombies surface with `status: "zombie"` instead of being dropped | `src/RunningJobsManager.php` |
| 4 | Regex-based `supervisor_id` extraction (no unserialize) | `src/RunningJobsManager.php` |
| 5 | Malformed jobs logged + counted (`dropped_count` in response) | `src/RunningJobsManager.php` |
| 6 | Redis connection auto-detect from `config('horizon.use')` | `src/RunningJobsManager.php` |
| 7 | CLI `--limit` / JSON output shows `Showing X of Y` + `truncated` flag | `src/Commands/ListRunningJobsCommand.php` |
| 8 | Duration math adjusts for `retry_after` (reserved score is expiry) | `src/RunningJobsManager.php` |

### Side fixes made along the way

- Dot-in-hostname bug in `getDefaultQueues` (was using `config('...' . gethostname())` which broke when hostname contained dots).
- Controller–manager queue detection deduped (controller delegates to `RunningJobsManager::getDefaultQueues()`).
- `getDefaultQueues` elevated `protected` → `public`.

### QA close-out deliverables

- `tests/Unit/RunningJobsManagerTest.php::test_malformed_reserved_jobs_are_logged_and_counted_as_dropped` — proves `Log::warning` fires with correct context.
- `tests/Unit/RunningJobsManagerTest.php::test_clear_cache_persists_incremented_epoch` — proves `Cache::forever` persists the new epoch.
- `tests/Unit/RunningJobsManagerTest.php::test_get_default_queues_finds_hostname_keyed_supervisor` — regression guard for dot-in-hostname.
- CLI table gained a **`Status`** column.
- `getStats()` extended with `by_status` + `dropped_count`.
- CLI `--stats` shows `By Status` + `Dropped (malformed)` lines.
- CHANGELOG `[Unreleased]` section — breaking changes, new fields, Laravel 13 note.
- README — response-fields table, `retry_after` config documented, `status` semantics explained.
- Demo `.env.example` updated to `QUEUE_CONNECTION=redis`.

### Test count

**29 tests, 39 assertions, all green.** Run: `vendor/bin/phpunit`.

### Known carry-overs from Phase 1 (intentional, not blockers)

- **Controller `dropped_count` response field** has no dedicated feature test. Field is surfaced, verified in live demo. Manager-level coverage is strong. Low-value to add another passthrough test.
- **Integration tests with real Redis: zero.** All tests are unit-level against pure methods or mocked facades. The demo app is the integration environment.
- **Widget UI** not automated-tested. Smoke-verified via curl (HTTP 200, correct content-type, init code present).

---

## Phase 2 — Security & Hygiene (next, not started)

### Scope

| Item | Rationale |
|---|---|
| Default middleware → `['api', 'auth']` (with opt-out) | Current default is `['api']`, fully open. Security-by-default. |
| Default `throttle:60,1` on routes | Prevents Redis thundering herd from polling dashboards. |
| Validate `?queues=` param (regex, max count, non-empty) | Controller accepts arbitrary strings including empty queue names → Redis calls for `queues::reserved`. |
| Feature test for controller `dropped_count` passthrough | Close the amber from Phase 1 close-out. |
| Document middleware opt-out in README + CHANGELOG | Security-breaking defaults need migration notes. |

### Things already done from Phase 2 during Phase 1

- Controller–manager queue dedup ✓
- `getDefaultQueues` made public ✓

### Estimated effort: ~1 day

---

## Phase 3 — Supervisor health inspection (after Phase 2, not started)

### Scope

New class `src/SupervisorInspector.php` reading from:
- `horizon:supervisors` (set of supervisor names)
- `horizon:supervisor:{name}` (hash: pid, status, processes, queues, last heartbeat)
- `horizon:masters` / `horizon:master:{name}`

### Deliverables

- `php artisan horizon:supervisors` — table: status / pid / workers / queues / heartbeat-age / stale flag
- `GET /api/horizon/supervisors` — JSON equivalent
- `supervisors.stale_after_seconds` config (default 30)
- Demo scenario: `docker-compose stop horizon-worker-2` → STALE within 30s

### Estimated effort: 2–3 days

---

## Phases 4–7 (later)

- **Phase 4** — orphaned reserved jobs (`--orphaned`, `demo:simulate-orphan` pipeline)
- **Phase 5** — queue depth view (`horizon:queues` — pending / running / delayed)
- **Phase 6** — operational tools (release/retry from CLI, per-worker detail, `horizon:diagnose`, duration histogram, `--watch` mode)
- **Phase 7** — polish (publishable Blade view, Horizon sidebar, Vue build, docs pass)

---

## Technical context (for tomorrow)

### Paths

- Package: `/Users/ashiqfardus/Developer/Laravel Applications/laravel-horizon-running-jobs`
- Demo: `/Users/ashiqfardus/Developer/Laravel Applications/laravel-horizon-running-jobs-demo`

### Toolchain

- PHP 8.4.19, Composer 2.9.5, Redis 7, phpredis extension
- Laravel Herd provides the `laravel` installer

### Commands

```bash
# Run package tests
cd "/Users/ashiqfardus/Developer/Laravel Applications/laravel-horizon-running-jobs"
vendor/bin/phpunit

# Start the demo
cd "/Users/ashiqfardus/Developer/Laravel Applications/laravel-horizon-running-jobs-demo"
php artisan horizon                                # terminal 1
php artisan demo:dispatch-workload --slow=3        # terminal 2
php artisan horizon:running-jobs --stats           # terminal 2
php artisan serve --port=8765                      # terminal 3 (optional)
open http://localhost:8765/running-jobs            # visual widget
```

### Key files touched in Phase 1

```
composer.json                              — L13 support, Testbench 11, PHPUnit 12, composer test script
config/horizon-running-jobs.php            — retry_after config key added
src/RunningJobsManager.php                 — ~170 lines of changes
src/Controllers/RunningJobsController.php  — +16 lines
src/Commands/ListRunningJobsCommand.php    — +17 lines (Status column, --stats)
README.md                                  — response fields, retry_after docs
CHANGELOG.md                               — Unreleased section
phpunit.xml.dist                           — new
tests/TestCase.php                         — new
tests/Unit/RunningJobsManagerTest.php      — new, 27 tests
tests/Feature/RunningJobsControllerTest.php — new, 2 tests
```

---

## How to resume tomorrow

1. **Read** this file + `CHANGELOG.md`.
2. **Verify** baseline: `vendor/bin/phpunit` should show 29 green.
3. **Start Phase 2** — begin with the middleware default change (smallest, highest security impact). TDD workflow: write a failing feature test asserting a 401 on the unauthenticated API route, change the default, make it pass.
4. **Append to CHANGELOG** under `[Unreleased]` as you go.
5. **Do not tag a release** until Phases 1 + 2 + 3 are all complete.

---

## Three-hat review (2026-04-25)

### Product Owner

**User value delivered (Phase 1):** Package went from "mostly broken" to "shippable."
- Ruststucked durations fixed (was negative).
- Zombies surfaced for first time — that's a net-new operational capability.
- Widget works end-to-end.

**Concerns:**
- We've been heavy on engineering correctness and light on positioning. No competitive analysis against alternatives (e.g. Laravel Telescope, Horizon Watcher).
- README still buries the value prop under installation instructions. A 30-second "why you want this" section is missing.
- v1.0.0 was released 2026-01-07. The coming release will introduce breaking response-shape changes only three months later. Semver says minor bump is fine (additive fields), but the `parseJobData` throw + timeout-filter removal are behavior changes. CHANGELOG flags them; release notes should too.

**Verdict:** The bundle is on track for a credible v1.1.0 when Phases 2+3 are done. Don't skip Phase 2's auth-default — opening default-open endpoints would be a headline bug.

---

### Senior Engineer

**Architecture health:** Good for a package this size. Single-file manager (`RunningJobsManager`) is getting long (~440 lines) but still coherent — pure functions dominate. When Phase 3 adds supervisor inspection, **split it**. Candidate structure:

```
src/
├── RunningJobsManager.php       (stays — reserved-set parsing)
├── SupervisorInspector.php      (new — horizon:supervisors reading)
└── Support/
    ├── RedisReader.php          (shared Redis connection resolution)
    └── RetryAfterResolver.php   (share between both)
```

**Code quality issues I'd fix in Phase 2:**
- `RunningJobsManager::fetchRunningJobs` is now 40+ lines with mixed concerns (fetch, sort, warn, slice). Extract the warning-assembly into its own method.
- `parseJobData` has 6 parameters — positional-argument trap. At some point make it take a `ReservedJobContext` DTO.
- The inline `SpyRunningJobsManager` class in `tests/Feature/RunningJobsControllerTest.php` should move to `tests/Fakes/`.
- `Mockery::close()` cleanup in `tearDown()` is copy-paste-friendly, should live in `TestCase`.

**Safety audit:**
- `unserialize` removed ✓ (huge win)
- No SQL, no shell-out, no direct file I/O — low attack surface
- `Log::warning` with queue name only (no user input) — safe

**What I'd worry about:**
- The `retry_after` assumption is fragile. Horizon queues with non-default retry_after (common in production) will report wrong durations. The config override exists but isn't discoverable.
- `calculateRunningForSeconds` uses `max(0, ...)` to clamp negatives. That hides clock-skew bugs rather than flagging them. Consider a log when clamping.
- Demo-app Horizon config uses `gethostname()` as the supervisor key — works for single-machine, but the package's own README recommends explicit names in distributed setups. Demo should show both patterns.

---

### Senior QA

**Test suite audit.** 29 tests sounds fine until you map them to risk:

| Risk area | Coverage |
|---|---|
| Pure math / classification | ★★★★★ (`calculateRunningForSeconds`, `resolveJobStatus`, `formatDuration`, `resolveRetryAfter`) |
| Payload parsing | ★★★★☆ (`parseJobData` 5 tests across tag / regex / malformed / filter paths) |
| Cache mechanism | ★★★☆☆ (epoch logic proven; actual Redis cache put/get cycle untested) |
| Redis pipeline | ★☆☆☆☆ (one mocked test; no integration) |
| HTTP controller | ★★★☆☆ (hostname default covered; `dropped_count`, `queues_monitored`, `warnings` passthroughs untested) |
| CLI output | ★☆☆☆☆ (no automated CLI tests; all verification is manual demo) |
| Error handling / exception paths | ★★★★☆ (parseJobData throws, logging asserted) |

**What escapes:**
- Controller regression where a future refactor drops the `dropped_count` field. No guard.
- CLI Status column rendering. A typo in the ternary would ship.
- `buildCacheKey` format is asserted via `::v{N}:` substring but the full key contract (prefix, hostname, scope, queue-hash) isn't. Change any of those and tests still pass.
- Demo `.env.example` hasn't been installed into a fresh clone + tested. I fixed the line but didn't verify `laravel new ...-demo-copy && composer install` produces a working setup.

**What I'd require before tag (Phase 2/3 punchlist):**
1. One HTTP-level feature test per controller field (`queues_monitored`, `running_jobs_count`, `total_count`, `dropped_count`) — small spy-based asserts.
2. One CLI test using `$this->artisan('horizon:running-jobs')->expectsOutputToContain('Status')` — requires mocking `isHorizonRunning`.
3. One end-to-end integration test using a real Redis connection + hand-crafted reserved entries. Can be gated behind an env check so it's skipped in CI-without-Redis.

**Not blockers, but I'd log:**
- `composer.lock` is committed. For a library, convention is to ignore it. Downstream users' resolution happens via their own lockfile. This isn't wrong — it just pins dev tools — but it can cause "works on my machine" divergence in tests.
- `storage/logs/laravel.log` in the demo app is the only place malformed-job warnings land. A production user needs to know where to look; should be called out in the README scenario for Fix #5.

**Sign-off status:** Phase 1 is **QA-passable** as a slice. The bundled release (Phase 1+2+3) will need the feature tests above before I'd green-light a tag. Right now we're at ~70% of what I'd call "production-ready test coverage" for a public package.

---

## TL;DR for tomorrow

- ✅ Phase 0 + Phase 1 done, 29 tests green.
- ⏭️ Phase 2 is next — start with default auth middleware.
- 🚫 Don't tag. Release = 1 + 2 + 3 bundled.
- 📋 QA punchlist for pre-tag: controller feature tests, CLI `artisan` tests, integration test with real Redis.
