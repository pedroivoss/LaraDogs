<?php

namespace Fixture\EloquentUnboundedAll;

use Illuminate\Support\Facades\DB;

// Fixture for laradogs.performance.eloquent.unbounded-all.
// Positive cases below must be flagged; negative/safe cases must not.

class User {}

class Controller
{
    // POSITIVE: loads the entire table with no pagination/limit.
    public function positiveAll()
    {
        return User::all();
    }

    // NEGATIVE: bounded via pagination.
    public function negativePaginate()
    {
        return User::paginate(15);
    }

    // NEGATIVE: bounded via an explicit limit.
    public function negativeLimit()
    {
        return User::query()->limit(50)->get();
    }

    // SAFE: streamed via cursor() rather than loading everything into
    // memory at once.
    public function safeCursor()
    {
        return User::cursor();
    }

    // Phase 6.1 real-world regression (allimaPanel, 2026-09-08):
    // Collection::all() (converts an already-bounded collection to a plain
    // array) must NOT be confused with Eloquent's Model::all(). Before the
    // fix, $MODEL could bind to this entire preceding chain — whose first
    // character happens to be uppercase ("S" in SomeService) — because the
    // old regex only checked the first character, not the whole match.
    public function safeCollectionAllFluentChain()
    {
        return SomeService::query()->get()->map(fn ($r) => $r)->values()->all();
    }

    // Phase 6.1 real-world regression: same Collection::all() confusion,
    // via a DB::table(...)->pluck(...)->all() chain — the second real
    // false-positive shape found in allimaPanel.
    public function safeDbTablePluckAll()
    {
        return DB::table('store_clients')->pluck('id_store')->unique()->all();
    }
}
