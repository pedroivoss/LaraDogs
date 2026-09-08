<?php

namespace Fixture\RayCall;

// Fixture for laradogs.quality.debug.ray-call.
// Positive cases below must be flagged; negative/safe cases must not.

class Controller
{
    // POSITIVE: a bare ray() debug call.
    public function positiveBare($value)
    {
        ray($value);

        return $value;
    }

    // POSITIVE: ray() with multiple arguments.
    public function positiveMultipleArgs($a, $b)
    {
        ray($a, $b);
    }

    // NEGATIVE: a similarly-named but unrelated function call.
    public function negativeUnrelatedFunction()
    {
        return array_ray_helper_that_is_not_real_but_not_named_ray();
    }

    // NEGATIVE: a method named ray() on an object, not the global helper —
    // this rule intentionally only matches the bare global function call.
    public function negativeMethodCall($sunTracker)
    {
        return $sunTracker->ray();
    }
}

function array_ray_helper_that_is_not_real_but_not_named_ray()
{
    return [];
}
