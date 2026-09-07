# Upgrade guide

Write this file as instructions, not as a changelog. It is read by a model migrating a customer project, so every entry needs exact class and method names with old code next to new code.

Nothing has shipped yet, so there is nothing to upgrade from.

## If you published `config/apex.php` before the master lock landed

Two keys were added. Neither is required — the code falls back — but adding
them keeps the published file a complete picture of what Apex reads:

```php
// config/apex.php
'store_keys' => [
    // ...
    'master_lock' => 'apex:master:lock',
],

'master' => [
    // ...
    'lock_ttl_seconds' => (int) env('APEX_MASTER_LOCK_TTL_SECONDS', 30),
],
```

If you run `apex:start` from a supervisor that restarts it immediately, raise
nothing and change nothing: the lease is renewed every tick and released on a
clean stop. After a `kill -9` the replacement waits out `lock_ttl_seconds`
before it can start, so keep that value close to your restart interval.

## Migrating the WPS Apex module onto this package

Not a version upgrade, but the same shape of work.

### Table names stay as they are

```php
// config/apex.php
'table_names' => [
    'queue_states' => 'wps_apex__queue_states',
    'queue_metrics_history' => 'wps_apex__queue_metrics_history',
],
```

No data migration needed.

### `paused_by_admin_id` became polymorphic

```sql
ALTER TABLE wps_apex__queue_states
  ADD COLUMN paused_by_type VARCHAR(255) NULL,
  ADD COLUMN paused_by_id BIGINT UNSIGNED NULL;

UPDATE wps_apex__queue_states
   SET paused_by_type = 'admin', paused_by_id = paused_by_admin_id
 WHERE paused_by_admin_id IS NOT NULL;
```

Then register the alias, or a class rename silently orphans the rows:

```php
Relation::enforceMorphMap(['admin' => Admin::class]);
```

### `ModuleQueueObserver` moves to WPS

The package no longer knows what a module is.

```php
// Was: the package observed Module and wrote its own Redis key.
// Now: WPS implements the contract.
final class ModuleSuspensionSource implements QueueSuspensionSource
{
    public function __construct(private readonly ApexConfig $config) {}

    public function suspendedQueues(): array
    {
        return Module::query()->where('is_enabled', false)->pluck('slug')
            ->flatMap(fn (string $slug) => $this->config->queuesForGroup($slug))
            ->all();
    }
}

$this->app->bind(QueueSuspensionSource::class, ModuleSuspensionSource::class);
```

### Config key `module` became `group`

```php
// Was
'chat' => ['profile' => 'latency-critical', 'module' => 'chat'],
// Now
'chat' => ['profile' => 'latency-critical', 'group' => 'chat'],
```

### The Redis suspension key changed

`apex:control:module_disabled:{queue}` became `apex:control:suspended:{queue}`. The master rewrites both on boot, so no manual step is needed — but a queue disabled at the moment of deploy resumes for one tick before reconcile runs.

### `redis_keys` became `store_keys`

The keys are no longer necessarily in Redis, so the config section and the accessor were renamed:

```php
// Was
config('apex.redis_keys.control_pause_prefix');
$config->redisKey('workers_prefix');
// Now
config('apex.store_keys.control_pause_prefix');
$config->storeKey('workers_prefix');
```

The key *values* are unchanged, so nothing has to be migrated in Redis itself.

### `QueueDepthReader` became a contract

`Symphoria\Apex\Ipc\QueueDepthReader` is now `Symphoria\Apex\Contracts\QueueDepthProbe`, implemented by `Ipc\Depth\RedisQueueDepthReader` and `Ipc\Depth\DatabaseQueueDepthReader`. Resolve the contract; the provider picks the implementation from the queue driver.

### The snapshot payload renamed one field

`is_disabled_by_module` became `is_suspended`, and `module` became `group`. Update the dashboard component that reads them.

### The dashboard route is gone from the package

`ApexDashboardController` and the Inertia route stay in WPS. The package now only serves JSON under `apex.api.*`, `apex.failed.api.*`, `apex.recent.api.*`, `apex.workers.api.*` and `apex.history.api.*`. Permission enforcement moves from `->defaults('module', 'apex')` to a `viewApex` gate.
