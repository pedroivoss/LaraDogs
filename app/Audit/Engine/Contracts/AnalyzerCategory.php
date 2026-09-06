<?php

namespace App\Audit\Engine\Contracts;

/**
 * Mirrors the category list already recorded for the future `Finding`
 * domain (docs/auditing/overview.md) so an analyzer's category will align
 * with the findings it eventually produces, once Phase 3 exists.
 */
enum AnalyzerCategory: string
{
    case Security = 'security';
    case Bug = 'bug';
    case Performance = 'performance';
    case Dependency = 'dependency';
    case Quality = 'quality';
    case Configuration = 'configuration';
    case Test = 'test';
}
