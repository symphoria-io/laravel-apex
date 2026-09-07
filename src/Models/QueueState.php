<?php

declare(strict_types=1);

namespace Symphoria\Apex\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Symphoria\Apex\Apex;

/**
 * Persistent operational state for an Apex queue.
 *
 * Tracks manual pause state set from the dashboard. Suspension state (from a
 * QueueSuspensionSource) is deliberately NOT mirrored here: it is derived from
 * the application and reconciled into Redis on master boot.
 *
 * Redis remains the runtime IPC channel; this table is the source of truth so
 * a Redis flush or master restart cannot silently un-pause a queue.
 *
 * @property int $id
 * @property string $queue_name
 * @property bool $is_paused_manually
 * @property string|null $paused_by_type
 * @property int|string|null $paused_by_id
 */
class QueueState extends Model
{
    protected $fillable = [
        'queue_name',
        'is_paused_manually',
        'paused_by_type',
        'paused_by_id',
        'paused_at',
        'pause_reason',
    ];

    protected $casts = [
        'is_paused_manually' => 'boolean',
        'paused_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return Apex::table('queue_states');
    }

    /**
     * Polymorphic so the package never has to know the host's user model.
     * Register a morph alias for whatever you store here — a raw FQCN in the
     * database breaks the first time somebody renames the class.
     */
    public function pausedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
