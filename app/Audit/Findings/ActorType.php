<?php

namespace App\Audit\Findings;

use App\Models\Audit\FindingStatusHistory;

/**
 * Who performed a status transition. No User/RBAC domain exists yet
 * (Phase 10) — `System` covers every automated transition (finding
 * created, auto-resolved, auto-reopened) today; `User` is recorded via a
 * plain nullable `actor_identifier` string (see
 * {@see FindingStatusHistory}), not a foreign key to a
 * not-yet-designed permissions model.
 */
enum ActorType: string
{
    case System = 'system';
    case User = 'user';
}
