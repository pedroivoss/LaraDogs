<?php

namespace App\Audit\Engine\Contracts;

use App\Audit\Discovery\Profile\ProjectProfile;
use App\Audit\Engine\AuditContext;
use App\Audit\Engine\AuditEngine;
use App\Audit\Engine\Execution\AnalyzerResult;

/**
 * The contract every analyzer must implement, real or fake. No analyzer
 * implementations exist yet in production code (Phase 4+) — Phase 2 only
 * establishes this shape and exercises it with synthetic analyzers under
 * tests/Support/.
 *
 * `applicability()` and `availability()` are deliberately separate methods
 * (not one combined "canRun()") and are called in that order by
 * {@see AuditEngine}: a not-applicable analyzer's
 * availability is never even checked. See
 * docs/auditing/audit-engine.md#applicability-vs-availability.
 *
 * `run()` is only ever called when both are satisfied (i.e. the plan says
 * Planned) and must never be called directly by anything other than the
 * engine.
 */
interface Analyzer
{
    public function id(): AnalyzerId;

    public function name(): string;

    public function category(): AnalyzerCategory;

    /**
     * Does this analyzer's rules make sense for what {@see ProjectProfile}
     * detected? A fact about the project — must not depend on the host
     * environment (that's {@see availability()}).
     */
    public function applicability(ProjectProfile $profile): Applicability;

    /**
     * Can this analyzer's tooling actually run on this host, right now? A
     * fact about the environment — must not depend on the project (that's
     * {@see applicability()}). Only called when applicability() is
     * Applicable.
     */
    public function availability(AuditContext $context): Availability;

    /**
     * Execute the analyzer. Only called when applicability() is Applicable
     * and availability() is Available. Must never execute anything that
     * originates in the analyzed project — see
     * docs/auditing/audit-engine.md's security boundary section.
     */
    public function run(AuditContext $context): AnalyzerResult;
}
