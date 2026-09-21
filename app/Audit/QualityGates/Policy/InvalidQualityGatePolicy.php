<?php

namespace App\Audit\QualityGates\Policy;

use InvalidArgumentException;

/**
 * A policy document that is not a valid Quality Gate policy — thrown by
 * the strict parser. Carries a human-readable reason only, never the
 * offending payload verbatim beyond short identifiers.
 */
final class InvalidQualityGatePolicy extends InvalidArgumentException {}
