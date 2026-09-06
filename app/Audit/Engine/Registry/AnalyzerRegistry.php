<?php

namespace App\Audit\Engine\Registry;

use App\Audit\Engine\Contracts\Analyzer;
use App\Audit\Engine\Contracts\AnalyzerId;

/**
 * Explicit, in-memory analyzer registration — deliberately not a service
 * locator: analyzers are registered one at a time via {@see register()} by
 * whatever composes the registry (a test, or a future Laravel service
 * provider at the application's edge), never resolved magically by
 * convention/discovery. The domain (this class) has no knowledge of the
 * container.
 *
 * Preserves registration order (PHP arrays already do this) so
 * {@see all()} — and therefore the plan built from it — is deterministic:
 * the same registration sequence always produces the same order.
 */
final class AnalyzerRegistry
{
    /** @var array<string, Analyzer> */
    private array $analyzers = [];

    /**
     * @throws DuplicateAnalyzerIdException if an analyzer with this id is
     *                                      already registered.
     */
    public function register(Analyzer $analyzer): void
    {
        $key = (string) $analyzer->id();

        if (isset($this->analyzers[$key])) {
            throw new DuplicateAnalyzerIdException($analyzer->id());
        }

        $this->analyzers[$key] = $analyzer;
    }

    public function get(AnalyzerId $id): ?Analyzer
    {
        return $this->analyzers[(string) $id] ?? null;
    }

    public function has(AnalyzerId $id): bool
    {
        return isset($this->analyzers[(string) $id]);
    }

    /**
     * @return list<Analyzer>
     */
    public function all(): array
    {
        return array_values($this->analyzers);
    }

    public function count(): int
    {
        return count($this->analyzers);
    }
}
