<?php

use Symfony\Component\Yaml\Yaml;

/*
 * A bounded STRUCTURAL regression test — not a shell interpreter. It pins the
 * properties of the example workflow that keep LaraDogs' exit code from being
 * masked: `set +e`, capture `$?` immediately after the command, keep it across
 * the output commands, and `exit` with it last.
 */

function ciExampleRunLines(): array
{
    $doc = Yaml::parseFile(dirname(__DIR__, 3).'/docs/ci/examples/github-actions-self-hosted.yml');
    $step = collect($doc['jobs']['laradogs']['steps'])->firstWhere('name', 'Audit with LaraDogs');

    $lines = array_map('trim', explode("\n", $step['run']));

    // Drop blanks and comments — only statements matter for ordering.
    return array_values(array_filter($lines, fn (string $l) => $l !== '' && ! str_starts_with($l, '#')));
}

it('is valid YAML with explicit minimal permissions and no privileged patterns', function () {
    $path = dirname(__DIR__, 3).'/docs/ci/examples/github-actions-self-hosted.yml';
    $doc = Yaml::parseFile($path);
    $raw = (string) file_get_contents($path);
    $uncommented = implode("\n", array_filter(explode("\n", $raw), fn ($l) => ! str_starts_with(ltrim($l), '#')));

    expect($doc['permissions'])->toBe(['contents' => 'read', 'checks' => 'write'])
        ->and($uncommented)->not->toContain('pull_request_target')
        ->and($uncommented)->not->toContain('contents: write')
        ->and($uncommented)->not->toMatch('/echo[^\n]*(TOKEN|secrets\.)/i');
});

it('disables immediate shell exit before invoking LaraDogs', function () {
    $lines = ciExampleRunLines();
    $setPlusE = array_search('set +e', $lines, true);
    $invoke = collect($lines)->search(fn (string $l) => str_contains($l, 'laradogs:ci:audit'));

    expect($setPlusE)->not->toBeFalse()->and($invoke)->not->toBeFalse()->and($setPlusE)->toBeLessThan($invoke);
});

it('captures $? immediately after the LaraDogs command, before any other statement', function () {
    $lines = ciExampleRunLines();
    $invoke = collect($lines)->search(fn (string $l) => str_contains($l, 'docker compose'));

    // The command spans continuation lines (ending in `\`); its last line is
    // the first one that does not end with a backslash.
    $end = $invoke;
    while (str_ends_with($lines[$end], '\\')) {
        $end++;
    }

    expect($lines[$end + 1])->toBe('exit_code=$?');
});

it('keeps the captured code across the output commands and exits with it last', function () {
    $lines = ciExampleRunLines();
    $capture = array_search('exit_code=$?', $lines, true);
    $cat = collect($lines)->search(fn (string $l) => str_starts_with($l, 'cat '));

    expect($capture)->not->toBeFalse()
        ->and($cat)->toBeGreaterThan($capture) // output happens AFTER the capture
        ->and(end($lines))->toBe('exit $exit_code')
        // nothing reassigns the variable between capture and exit
        ->and(collect(array_slice($lines, $capture + 1))->contains(fn (string $l) => str_starts_with($l, 'exit_code=')))->toBeFalse();
});

it('invokes the audit with the expected revision and GitHub reporting, output to a file', function () {
    $run = implode("\n", ciExampleRunLines());

    expect($run)->toContain('laradogs:ci:audit')
        ->toContain('--expected-revision=')
        ->toContain('--github-report')
        ->toContain('--json')
        ->toContain('> laradogs-result.json');
});
