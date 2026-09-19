<?php

declare(strict_types=1);

namespace Symphoria\Apex\Ipc;

/**
 * What a lease renewal found. The master treats these very differently: a lock
 * that merely lapsed while nobody claimed it is a stalled loop, not a rival.
 */
enum LockRefresh
{
    /** This process never held the lock, or already gave it up. */
    case NotHeld;

    /** Too soon since the last renewal; nothing was read or written. */
    case Skipped;

    /** Still ours; the lease was extended. */
    case Held;

    /** The key had expired but nobody else took it, so we claimed it again. */
    case Reacquired;

    /** Another master holds the key now. This process must stand down. */
    case Lost;
}
