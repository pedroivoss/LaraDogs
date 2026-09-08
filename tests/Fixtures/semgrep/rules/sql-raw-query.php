<?php

namespace Fixture\SqlRawQuery;

use Illuminate\Support\Facades\DB;

// Fixture for laradogs.security.sql.tainted-raw-query.
// Positive cases below must be flagged; negative/safe cases must not.

class Controller
{
    // POSITIVE: direct pass-through into orderByRaw().
    public function positiveDirect($request, $qb)
    {
        $sort = $request->input('sort');

        return $qb->orderByRaw($sort);
    }

    // POSITIVE: tainted value reaches DB::raw() through string concatenation.
    public function positiveConcat($request)
    {
        $sort = $request->input('sort');

        return DB::raw('created_at '.$sort);
    }

    // POSITIVE: tainted value reaches whereRaw() through string interpolation.
    public function positiveInterpolated($request, $qb)
    {
        $col = $request->get('col');

        return $qb->whereRaw("$col = 1");
    }

    // POSITIVE: selectRaw() with a superglobal source.
    public function positiveSuperglobal($qb)
    {
        return $qb->selectRaw($_GET['expr']);
    }

    // NEGATIVE: constant raw fragment, no tainted input anywhere.
    public function negativeConstant($qb)
    {
        return $qb->orderByRaw('created_at DESC');
    }

    // NEGATIVE: whereRaw with a fixed, hardcoded expression.
    public function negativeFixedWhere($qb)
    {
        return $qb->whereRaw('deleted_at IS NULL');
    }

    // SAFE: request input cast to int before reaching the raw sink.
    public function safeCastToInt($request, $qb)
    {
        $id = (int) $request->input('id');

        return $qb->whereRaw('id = '.$id);
    }

    // SAFE: request input passed through intval() before the raw sink.
    public function safeIntval($request)
    {
        $id = intval($request->get('id'));

        return DB::raw('id = '.$id);
    }

    // SAFE (near-miss): the request value is used to select from an
    // allowlist, and only the allowlisted constant ever reaches the sink.
    // NOT expected to be flagged (no taint reaches the sink at all — the
    // sink argument is a literal chosen via a ternary, not the raw input).
    public function safeAllowlistedTernary($request, $qb)
    {
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        return $qb->orderByRaw('created_at '.$direction);
    }
}
