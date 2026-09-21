<?php

namespace App\Audit\QualityGates;

/**
 * Stable identifiers of the V1 rule catalog — persisted in policies and
 * results, exposed in CLI JSON, and intended to stay valid for a future
 * API / CI / MCP. Never rename a value once shipped.
 */
enum GateRuleId: string
{
    case MaxOpenFindings = 'laradogs.gate.max-open-findings';
    case NoNewSeverity = 'laradogs.gate.no-new-severity';
    case AnalyzerStatus = 'laradogs.gate.analyzer-status';
    case AnalyzerCoverage = 'laradogs.gate.analyzer-coverage';
}
