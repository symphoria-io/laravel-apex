# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-09-07

First tagged release. On `0.x` the public API may still move between minors;
see the Public API section in the README for what is covered once 1.0 lands.

### Added

- Extracted from the WPS `Apex` module: master daemon, scaling decider with
  hysteresis, boot throttle, warm pools, heartbeats, control channel, metrics
  and per-minute history.
- `QueueSuspensionSource` contract so the host application can stop a queue for
  reasons the package knows nothing about. `NullSuspensionSource` is the
  default; `LaravelPauseSuspensionSource` maps Laravel's own `queue:pause` onto
  Apex scale-to-zero.
- `Apex` resolver with config-driven models and table names, plus a
  Horizon-style authorization gate that denies the control API outside `local`.
- JSON control API. The package ships no screen.
- `ApexStore` contract with `RedisStore` and `DatabaseStore` implementations, so
  Apex runs on installations without Redis. The store is derived from the queue
  driver unless `APEX_STORE` says otherwise.
- `QueueDepthProbe` contract with a Redis and a database reader. Deliberately
  separate from the store: it reads the jobs themselves, so it always follows
  the queue driver.
- Per-store defaults for tick intervals and heartbeat throttling, resolved as
  `apex.master.*` falling back to `apex.stores.{active}.*`. `apex:config:show`
  reports the active store and, per setting, whether the value was configured
  or inherited.
- Heartbeat write throttle. A state change always lands; repeats of the same
  state are collapsed to one write per interval. An idle worker used to write a
  heartbeat on every poll cycle.
- Wake key set on `JobQueued`, letting an idle master answer "did anything
  happen" with one read instead of a depth read per queue. A full read still
  runs every 30 seconds, for work that entered the queue elsewhere. Throttled
  per process, so `Bus::bulk()` costs one write rather than one per job.
- `apex:start` warns when `Queue::forward()` (Laravel 13.26+) redirects a queue
  Apex is watching, which would otherwise show as a queue that never has work.
- Master lock, so one installation runs one supervisor. Two masters cannot see
  each other's workers, so they scale the same queues independently and spawn
  past every configured maximum; the symptom looks like erratic scaling rather
  than like two processes. `apex:start` refuses and names the holder, and
  `--force` takes over. The lease is renewed while the loop turns and lapses
  after `apex.master.lock_ttl_seconds` (default 30), so a killed master frees
  it without help. A master that loses its lease stands down.
- `ApexStore::add()`, an atomic set-if-absent, which is what makes the lock a
  lock. Redis uses `SET NX EX`; the database store leans on the unique index
  over `(key, member)`.
- `ApexConfig::storeKey()` takes an optional default, so a key added after an
  installation published its config does not throw.

### Fixed

- Removed dead code: `ControlChannel::pausedQueues()`, `ScalingDecision::desiredTotal()`,
  `SystemLoad::cpuPercent()`, `ApexConfig::dashboard()`, `ApexConfig::profileNames()`
  and `ApexConfig::raw()` had no callers. `ProcessMemoryReader::rssMb()` is now
  `residentMb()`, because it returns PSS in preference to RSS and the old name
  said otherwise.
- `ApexWorker` called `queuePaused()`, which is not a method on Laravel's
  `Worker` in any supported version. Every pop on the BLMPOP path raised "call
  to undefined method" — on exactly the Redis 7 plus phpredis setup the fast
  path exists for. Replaced with the framework's batched `getPausedQueues()`,
  which now excludes paused queues rather than abandoning the whole pop. The
  call had a `phpstan-baseline.neon` entry, so static analysis had been
  reporting it all along and the baseline was hiding it; that entry is gone.
- `DatabaseQueueDepthReader` counted only unreserved rows, so a job whose
  reservation had outlived `retry_after` was invisible. Laravel hands such a
  job to the next worker that asks, but on a queue with `min_processes: 0`
  there was no next worker: a crashed worker's job could sit untouched. The
  reader now mirrors `DatabaseQueue::getNextAvailableJob`. The Redis reader was
  already correct, which is why the asymmetry went unnoticed.
- A worker that opted into BLMPOP stopped calling Laravel's `sleep()`. If the
  client then turned out not to support the command, the pop returned
  immediately and the loop spun at full speed against an empty queue. The queue
  now reports whether it actually blocked, and the worker restores the normal
  idle wait when it did not.
- Apex pause and suspension are keyed by queue *group*, but the worker compared
  those keys against its individual `--queue` names and stopped only when every
  one matched. For a group reading more than one queue that never happened. The
  worker now checks its own group, and additionally drops any queue belonging
  to a stopped group from its pop list, so pausing a queue also stops a flex
  pool that reads it.
- A failure while writing the metrics snapshot propagated out of the master
  loop, killing the supervisor and orphaning every worker it had started.
  Snapshot writing is now isolated, and the shutdown check sits in its own
  guard so a persistently failing store cannot make the master unstoppable
  through the control channel.
- Workers are shut down in a `finally`, so anything escaping the loop still
  cleans up instead of leaving orphans holding reserved jobs.
- `graceful_shutdown_seconds` bounded nothing: after the deadline the master
  called `proc_close()`, which blocks until the process ends. Survivors are now
  sent `SIGKILL` first.
- The wake listener was registered inside the `recent_jobs` block, so turning
  off a dashboard feature stopped an idle master from noticing dispatches.
  It is registered independently now.
- An idle master did not notice user activity or a resumed queue, because a
  skipped tick reads neither. Resuming marks the wake key, and the idle gate
  checks activity, so a warm pool starts when someone arrives rather than at
  the next periodic full tick.
- `LaravelPauseSuspensionSource` called `getPausedQueues()` without arguments,
  which the real signature does not accept. The resulting error was swallowed
  by the reconcile guard, so `queue:pause` silently never suspended anything.
  It now asks about the queues each Apex group reads, and suspends a group only
  when every one of them is paused. `queue:pause --all` suspends everything.

### Changed from the WPS module

- Models extend `Illuminate\Database\Eloquent\Model` instead of the WPS base
  model; `paused_by_admin_id` became a polymorphic `paused_by`.
- Config key `module` became `group`; `ApexConfig::queuesForModule()` became
  `queuesForGroup()`.
- Redis key `apex:control:module_disabled:` became `apex:control:suspended:`;
  the snapshot field `is_disabled_by_module` became `is_suspended`.
- Config key `redis_keys` became `store_keys` and `ApexConfig::redisKey()`
  became `storeKey()`, since the keys are no longer necessarily in Redis.
- `apex:start` no longer treats a non-Redis queue driver as a mistake. It
  refuses only drivers it cannot read backlog from, and the Redis version
  preflight is skipped unless the queue itself runs on Redis.
- `ModuleQueueObserver` and the four host-specific bench scenarios were removed;
  the host registers its own through `ScenarioRegistry::register()`.
- The Inertia dashboard route and controller moved out of the package.
