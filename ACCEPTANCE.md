# ACCEPTANCE.md

Checklist a reviewing agent can run literally. A package that fails any of these does not get tagged.

## Extraction (as 1)

- [x] No file in `src/` mentions `Wps\Framework` — enforced by `PackageIntegrityTest`.
- [x] Models extend `Illuminate\Database\Eloquent\Model`, not a host base model.
- [x] `paused_by` is polymorphic; the package never names a user model.
- [x] Table names come from `config('apex.table_names.*')`, including inside the migrations.
- [x] Model classes come from `config('apex.models.*')` and resolve through `Apex`.
- [x] Queue suspension goes through `QueueSuspensionSource`; the default suspends nothing.
- [x] Bench scenarios that need host domain models are gone; `ScenarioRegistry::register()` lets the host add its own.

## Redis-optional (as 2)

- [x] `ApexStore` has a Redis and a database implementation, both green against the same contract test.
- [x] The store is derived from the queue *driver* when unset, never from the connection name.
- [x] `QueueDepthProbe` follows the queue driver independently of the store, so redis-queue-plus-database-store works.
- [x] The store tables are created regardless of which store is active.
- [x] Tick intervals fall back to a per-store default; `apex:config:show` reports the provenance of each.
- [x] `apex:start` refuses only queue drivers it cannot read backlog from, and skips the Redis preflight when the queue is not on Redis.
- [x] Verified end to end: 8 jobs through a database queue with a database store, dashboard API returns them, no Redis process involved.

## Configuration

- [x] `config/apex.php` contains no closures.
- [x] `env()` is called only inside `config/`.
- [x] An invalid configured model throws `InvalidConfiguration` with an actionable message.

## Security

- [x] The control API denies access outside `local` unless `Apex::auth()` or a `viewApex` gate is defined.
- [x] `apex.dashboard.enabled = false` registers no routes at all.
- [x] The package ships no screen, so it does not depend on Inertia.

## Quality gate

```bash
composer lint && composer analyse && composer test && composer validate --strict
```

- [x] All four pass. 147 tests, 309 assertions.
- [ ] CI runs the test matrix across PHP 8.3/8.4/8.5, including a `--prefer-lowest` pass.
- [ ] Formatting and static analysis run once, on the newest PHP.
- [ ] `composer audit` reports nothing.

## Known debt, must clear before 1.0

- [ ] **`phpstan-baseline.neon` holds 159 inherited findings.** Burn down to zero; do not add to it. One entry turned out to be a fatal bug and five more described dead code, so treat the rest as unreviewed.
- [x] The master loop has a test: `MasterLoopTest` covers a failing snapshot, the shutdown check staying reachable, the kill escalation and cleanup on an escaping throw.
- [ ] No test drives a real worker process end to end. `WorkerPoolTest` exercises the pop and pause decisions against a stand-in connection; nothing spawns an actual `apex:work` and watches it pick up a job.
- [ ] The database store has not been measured under load. It is correct, not benchmarked: the per-store tick defaults are reasoned, not derived from numbers.
- [ ] `UPGRADE.md` has no entries yet because nothing has shipped.
- [ ] The WPS side still needs its bridge: a `QueueSuspensionSource` reading `modules.is_enabled`, its own `config/apex.php` with `wps_apex__*` table names, and the four bench scenarios re-registered.
