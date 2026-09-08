<?php

namespace Fixture\EloquentUnboundedAll;

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
}
