<?php

use App\Audit\Engine\Contracts\AnalyzerId;
use App\Audit\Engine\Registry\AnalyzerRegistry;
use App\Audit\Engine\Registry\DuplicateAnalyzerIdException;
use Tests\Support\Engine\Analyzers\AlwaysFailAnalyzer;
use Tests\Support\Engine\Analyzers\AlwaysPassAnalyzer;
use Tests\Support\Engine\Analyzers\UnavailableAnalyzer;

it('registers analyzers and retrieves them by id', function () {
    $registry = new AnalyzerRegistry;
    $analyzer = new AlwaysPassAnalyzer('a');

    $registry->register($analyzer);

    expect($registry->has(new AnalyzerId('a')))->toBeTrue();
    expect($registry->get(new AnalyzerId('a')))->toBe($analyzer);
    expect($registry->count())->toBe(1);
});

it('returns null for an unregistered id instead of throwing', function () {
    $registry = new AnalyzerRegistry;

    expect($registry->get(new AnalyzerId('missing')))->toBeNull();
    expect($registry->has(new AnalyzerId('missing')))->toBeFalse();
});

it('rejects a duplicate analyzer id, even from a different analyzer class', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('dup'));

    expect(fn () => $registry->register(new AlwaysFailAnalyzer('dup')))
        ->toThrow(DuplicateAnalyzerIdException::class, 'Analyzer id [dup] is already registered.');

    // The first registration must survive a failed second attempt.
    expect($registry->count())->toBe(1);
});

it('preserves registration order deterministically', function () {
    $registry = new AnalyzerRegistry;
    $registry->register(new AlwaysPassAnalyzer('first'));
    $registry->register(new UnavailableAnalyzer('second'));
    $registry->register(new AlwaysFailAnalyzer('third'));

    $ids = array_map(fn ($analyzer) => (string) $analyzer->id(), $registry->all());

    expect($ids)->toBe(['first', 'second', 'third']);
});

it('rejects an empty analyzer id', function () {
    expect(fn () => new AnalyzerId(''))->toThrow(InvalidArgumentException::class);
    expect(fn () => new AnalyzerId('   '))->toThrow(InvalidArgumentException::class);
});
