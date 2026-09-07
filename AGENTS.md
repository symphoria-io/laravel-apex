# AGENTS.md — symphoria/laravel-apex

Read `../AGENTS.md` first for the rules that apply to every Symphoria package, including the English-only rule.

Extracted from the WPS `Apex` module in August 2026. The extraction plan and its reasoning live in `wps-research/apex.md`.

## What this package owns, and what it does not

| Owns | Does not own |
|---|---|
| how many workers run, and when they start or stop | storing, claiming and retrying jobs — that is Laravel's queue driver |
| heartbeats, control channel, metrics | the dashboard screen; the package ships JSON, the host owns the UI |
| the scaling policy and its profiles | the reason a queue must stop; that comes from `QueueSuspensionSource` |

## Rules that are easy to get wrong

**Backlog means "what Laravel would hand out now", not "what is unreserved".** A reservation that has outlived `retry_after` is work again, and the depth readers have to agree with `DatabaseQueue::getNextAvailableJob` about that. The Redis reader gets this for free because the `:reserved` zset is scored by expiry; the database reader needs the `orWhere` and had it missing, so a crashed worker's job stayed invisible to a queue that had scaled to zero.

**A worker that stops sleeping owns the wait.** `ApexWorker` skips Laravel's `sleep()` only while BLMPOP is genuinely blocking. Anything that makes a pop return early — an unsupported client, a connection error, every queue filtered out — must set `blmpopActive` back to false, or the loop spins at full speed against an empty queue and looks perfectly healthy while doing it.

**Pause and suspension are keyed by group, never by queue name.** `--name` is the group; `--queue` is the list of Laravel queues it reads. Only the first has control keys written for it. A worker also drops queues owned by *other* stopped groups from its pop list, so pausing a queue stops the flex pool that reads it too.

**Never add an unbatched per-queue or per-worker store call to `tick()` or `writeSnapshot()`.** The master's per-tick reads dominate the cost of an otherwise idle installation: 4 ticks/sec × 12 queues × 19 installations, whether or not anything is queued. Extend the existing bulk readers instead — `QueueDepthProbe::prefetch`, `HeartbeatStore::many`, `ControlChannel::statesFor`, `MetricsStore::queueSummaries`, `ApexStore::many`. This matters more on the database store, where every unbatched call is a query.

**The store and the queue driver are two questions, not one.** `ApexStore` is where Apex keeps its own state (`APEX_STORE`, derived from the queue driver when unset). `QueueDepthProbe` reads the jobs themselves and therefore always follows the queue driver. Both combinations occur; do not collapse them into one setting. Anything Redis-specific — the BLMPOP preflight, the version check — belongs to the queue side.

**Derive the store from the queue *driver*, never from the connection name.** A connection called `redis` backed by the `sync` driver is legal, and name-based derivation would pick a store that is not there.

**Migrations must never be conditional on config.** The store tables are created whichever store is active. Gating them on `APEX_STORE` gives you a schema that differs per environment and turns changing a setting into a deploy step.

**`QueueSuspensionSource::suspendedQueues()` returns everything in one call** for that same reason. An `isSuspended(string $queue)` shape would turn the reconcile into N round trips. Do not add one.

**Anything on the `JobQueued` path runs once per dispatched job.** `Bus::bulk()`
is a single call that dispatches thousands, so a per-event write there is a
thousand writes — and on the database store, a thousand queries. `WakeSignal`
throttles itself per process for exactly this reason. Hold new listeners to the
same standard.

**Apex finds work by queue *name*.** `Queue::forward()` and `Queue::route()`
(Laravel 13.0/13.26) change where dispatches land without touching any job
class, so a queue Apex watches can quietly stop receiving work while reporting
zero depth forever. `apex:start` warns about forwards; routes to another
*connection* are invisible to the probe and remain the host's responsibility.

**One master per installation, enforced by a lease.** `MasterLock` holds `apex:master:lock` for `lock_ttl_seconds` and renews it every tick; losing it means another master took over and this one stands down. Two masters is not a degraded mode: neither can see the other's workers, so both scale the same queues and spawn past every maximum, and it reads as erratic scaling rather than as a second process. The lease has to expire on its own, because nothing releases it when a master is killed outright.

**Reporting may fail; supervising may not.** Everything the loop does after `tick()` is wrapped, because a store that cannot answer must not take down the process that owns the workers. The shutdown check gets its own guard rather than sharing one with the snapshot: if a failing snapshot could skip it, the control channel would stop being able to stop the master. `shutdown()` runs in a `finally`, and kills whatever outlives the grace period, because `proc_close()` waits for the process and otherwise bounds nothing.

**An idle master is blind by design, so anything that creates work must say so.** The skipped tick reads one wake key and nothing else. Dispatching sets it; so does resuming a queue; user activity is checked separately because a warm pool exists for people, not for backlog. A new reason for work to appear needs a matching signal, or it waits up to `FULL_TICK_INTERVAL_SECONDS`. The wake listener is registered independently of `recent_jobs`: a dashboard setting must never change scaling behaviour.

**A slow tick is not a slow queue.** The master's tick decides how many workers to run; it is not in the pickup path, because a warm worker takes its next job off the queue itself. That is why the database store can tick at 1s without hurting latency, and why idle back-off can be as aggressive as it is. Reason about tick cost and pickup latency separately.

**Asking for the oldest job's age on an empty queue is not free.** `zrangebyscore` + `lindex` over empty structures was ~31% of all Redis traffic before `oldestWaitSeconds()` learned to return early when depth is 0.

**`apex:work` must mirror `WorkCommand`'s signature verbatim.** A missing `--delay` or `--daemon` makes Symfony Console exit 1 with "The 'delay' option does not exist", the master sees a dead worker, and you get a respawn loop within milliseconds.

**`ApexWorkCommand` cannot rely on container auto-wiring.** `Worker::__construct` takes a `callable $isDownForMaintenance` the container cannot resolve, so the provider constructs it explicitly with `queue.worker` + `cache.store`.

**One owner per worker's death.** A worker that can time itself out owns that decision; the master only retires workers that cannot. Floor workers get `--idle-timeout=0` and therefore must be master-retired when the effective minimum drops.

**Scale up immediately, scale down only after `scale_down_debounce_seconds`.** Flex coverage dips for a second whenever a flex worker recycles, and reacting to that dip is what produced a spawn/kill oscillation.

**Activity means a human, not a browser.** `apex.activity.ignore_route_prefixes` must keep the `apex.` prefix: without it the dashboard's own polling keeps every activity-driven queue staffed, so the monitoring screen manufactures the load it displays.

**Clear the config cache after editing `config/apex.php`.** The master spawns workers as subprocesses; a stale `bootstrap/cache/config.php` gives them the old settings.

**On Windows, `proc_open` needs array-args plus `bypass_shell => true`.** The concatenated string form goes through cmd.exe and double-escapes Laravel's quoted arguments.

## Deliberate deviations

**PHPStan has a baseline of 159 findings.** They are inherited from the WPS module, which was never analysed at level 8. The baseline stops new ones from slipping in. Burning it down is a prerequisite for 1.0 and is tracked in `ACCEPTANCE.md`. Treat an entry as a possible unreported bug rather than noise: the baseline was suppressing a call to a `Worker::queuePaused()` that does not exist, which made every BLMPOP pop fatal, and five more entries turned out to be describing methods nothing called.

**No `file` store, and there will not be one.** It means writing your own locking, which fails differently on Windows and silently on network shares. SQLite through `APEX_DB_CONNECTION` is the "one file, no daemon" answer.

**No screen in the package.** B8 in the research allows one operational screen, but shipping an Inertia page would make the package depend on Inertia even when the dashboard is off. Shipping JSON keeps that dependency out and lets the host own its own UI.

**`ApexRedisQueue` ships with the package.** It looks like a queue backend but is not: it is a `RedisQueue` subclass that adds BLMPOP multi-queue pickup, which is a supervisor concern. It falls back to per-queue polling on Redis < 7.

**`RedisStore::add()` goes through `command()` rather than the connection's `set()`.** phpredis wants the modifiers as an options array and predis wants them as trailing arguments, and Laravel's wrapper signature only fits one of them. Branch on the client and pass raw arguments; anything else is either non-portable or not atomic, and a lock that is not atomic is not a lock.

## Testing

`tests/Unit` holds plain PHPUnit classes — pure logic, no Laravel app, no database. `tests/Feature` uses the Testbench `TestCase`. `Pest.php` deliberately binds the TestCase to `Feature` only.

`tests/Feature/ModelOverrideTest.php` is the mandatory proof that model overriding works. `tests/Feature/SuspensionTest.php` proves the extraction: the application decides which queues stop, and Apex never learns why.

The `Clock` interface exists so `BootThrottle` can be tested deterministically with a fake.
