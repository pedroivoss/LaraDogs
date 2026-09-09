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

    // POSITIVE: tainted value reaches whereRaw() through concatenation —
    // the exact shape the user's Phase 6.1 spec required as a mandatory
    // regression (distinct from positiveConcat above, which uses DB::raw).
    public function positiveWhereRawConcat($request, $qb)
    {
        $name = $request->input('name');

        return $qb->whereRaw('name = '.$name);
    }

    // Phase 6.1 real-world regression (allimaPanel, 2026-09-08): a request
    // value used ONLY as a bound parameter (a `?` placeholder + bindings
    // array) must NOT be flagged — this is the exact SAFE pattern this
    // rule's own remediation text recommends. Before the fix, the sink
    // pattern's trailing `...` let taint anywhere in the bindings array
    // trigger the match even though the raw SQL string itself never
    // contains the tainted value.
    public function safeBoundWhereRaw($request, $qb)
    {
        $period = $request->get('period');

        return $qb->whereRaw('DATE_FORMAT(schedule_date, "%Y-%m") = ?', [$period]);
    }

    // Phase 6.1 real-world regression: a chain-cascade duplicate. $qb was
    // fed a safe, bound whereRaw earlier in the SAME fluent chain; a LATER
    // selectRaw() with a fully literal string (no variable at all) must
    // NOT also be flagged just because $qb's earlier call happened to see
    // tainted (but safely bound) data.
    public function safeChainThenSelectRaw($request, $qb)
    {
        $period = $request->get('period');
        $qb = $qb->whereRaw('period = ?', [$period]);

        return $qb->selectRaw('id, name');
    }

    // Phase 6.1 real-world regression: same chain-cascade shape, for
    // orderByRaw() instead of selectRaw() — orderByRaw's sink pattern has
    // no trailing `...` at all, so this specifically exercises whether an
    // unconstrained $QB receiver (rather than a trailing wildcard) is the
    // source of the false positive.
    public function safeChainThenOrderByRaw($request, $qb)
    {
        $period = $request->get('period');
        $qb = $qb->whereRaw('period = ?', [$period]);

        return $qb->orderByRaw('status DESC, schedule_date DESC');
    }

    // Phase 6.1 real-world regression: the exact allimaPanel shape — a
    // tainted value reaches only a ternary CONDITION, inline inside the
    // sink's own raw-string argument (unlike safeAllowlistedTernary above,
    // where the ternary result is assigned to a separate variable first).
    // Both of the ternary's branches are fixed literals; the tainted value
    // itself never becomes part of the SQL text.
    public function safeInlineTernaryInRawString($request, $qb)
    {
        $statusFilter = $request->input('status');

        return $qb->selectRaw('id, '.($statusFilter === 'completed' ? 'sig.manager_name' : 'NULL as manager_name'));
    }
}
