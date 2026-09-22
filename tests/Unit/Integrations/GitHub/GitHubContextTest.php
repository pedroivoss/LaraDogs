<?php

use App\Integrations\GitHub\GitHubContext;

function ghEnv(array $overrides = []): array
{
    return [...[
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_REPOSITORY' => 'pedroivoss/LaraDogs',
        'GITHUB_SHA' => str_repeat('a', 40),
        'GITHUB_SERVER_URL' => 'https://github.com',
        'GITHUB_API_URL' => 'https://api.github.com',
    ], ...$overrides];
}

it('parses a normal GitHub repository into owner and name', function () {
    $context = GitHubContext::fromEnvironment(ghEnv());

    expect($context->actionsMode)->toBeTrue()
        ->and($context->repositoryOwner)->toBe('pedroivoss')
        ->and($context->repositoryName)->toBe('LaraDogs')
        ->and($context->repositorySlug())->toBe('pedroivoss/LaraDogs')
        ->and($context->hasRepository())->toBeTrue();
});

it('rejects an invalid repository value', function (string $value) {
    $context = GitHubContext::fromEnvironment(ghEnv(['GITHUB_REPOSITORY' => $value]));

    expect($context->hasRepository())->toBeFalse()
        ->and($context->repositoryOwner)->toBeNull()
        ->and($context->repositoryName)->toBeNull();
})->with([
    'no slash' => 'pedroivossLaraDogs',
    'two slashes' => 'pedroivoss/Lara/Dogs',
    'empty' => '',
    'leading hyphen owner' => '-pedroivoss/LaraDogs',
    'shell metacharacters' => 'pedroivoss/`rm -rf /`',
    'path traversal' => '../../../etc/passwd',
    'space' => 'pedro ivoss/LaraDogs',
]);

it('accepts a valid full commit SHA (both 40 and 64 hex characters)', function (string $sha) {
    expect(GitHubContext::fromEnvironment(ghEnv(['GITHUB_SHA' => $sha]))->sha)->toBe($sha);
})->with([
    'sha-1' => str_repeat('f', 40),
    'sha-256' => str_repeat('f', 64),
]);

it('rejects an invalid SHA rather than passing it through', function (string $sha) {
    expect(GitHubContext::fromEnvironment(ghEnv(['GITHUB_SHA' => $sha]))->sha)->toBeNull();
})->with([
    'too short' => 'abc123',
    'non-hex' => str_repeat('g', 40),
    'with injection' => str_repeat('a', 39).';touch pwned',
]);

it('normalizes an uppercase SHA to lowercase', function () {
    expect(GitHubContext::fromEnvironment(ghEnv(['GITHUB_SHA' => str_repeat('A', 40)]))->sha)->toBe(str_repeat('a', 40));
});

it('handles a GitHub Enterprise Server base URL', function () {
    $context = GitHubContext::fromEnvironment(ghEnv([
        'GITHUB_SERVER_URL' => 'https://github.mycorp.internal',
        'GITHUB_API_URL' => 'https://github.mycorp.internal/api/v3',
    ]));

    expect($context->serverUrl)->toBe('https://github.mycorp.internal')
        ->and($context->apiUrl)->toBe('https://github.mycorp.internal/api/v3');
});

it('falls back to the configured defaults when GitHub Enterprise URLs are absent', function () {
    $context = GitHubContext::fromEnvironment(
        ['GITHUB_ACTIONS' => 'false'],
        configApiUrl: 'https://api.github.example',
        configServerUrl: 'https://github.example',
    );

    expect($context->apiUrl)->toBe('https://api.github.example')
        ->and($context->serverUrl)->toBe('https://github.example')
        ->and($context->actionsMode)->toBeFalse();
});

it('is safe when every optional PR/context variable is missing', function () {
    $context = GitHubContext::fromEnvironment([]);

    expect($context->actionsMode)->toBeFalse()
        ->and($context->repositoryOwner)->toBeNull()
        ->and($context->repositoryName)->toBeNull()
        ->and($context->sha)->toBeNull()
        ->and($context->hasRepository())->toBeFalse()
        ->and($context->apiUrl)->toBe('https://api.github.com')
        ->and($context->serverUrl)->toBe('https://github.com');
});

it('never lets an environment value inject a URL, header or command', function () {
    $context = GitHubContext::fromEnvironment(ghEnv([
        'GITHUB_SERVER_URL' => "https://github.com\r\nAuthorization: Bearer stolen",
        'GITHUB_API_URL' => 'javascript:alert(1)',
        'GITHUB_REPOSITORY' => "pedroivoss/LaraDogs\nX-Injected: 1",
        'GITHUB_SHA' => str_repeat('a', 40)."\n; rm -rf /",
    ]));

    // Every tainted field falls back to a safe default or null — never the
    // raw, control-character-bearing or non-https input.
    expect($context->serverUrl)->toBe('https://github.com')
        ->and($context->apiUrl)->toBe('https://api.github.com')
        ->and($context->hasRepository())->toBeFalse()
        ->and($context->sha)->toBeNull();
});

it('rejects a URL with embedded credentials', function () {
    $context = GitHubContext::fromEnvironment(ghEnv(['GITHUB_API_URL' => 'https://token:x-oauth-basic@api.github.com']));

    expect($context->apiUrl)->toBe('https://api.github.com'); // falls back to the safe default, credentials never kept
});

it('rejects a non-https URL', function () {
    $context = GitHubContext::fromEnvironment(ghEnv(['GITHUB_SERVER_URL' => 'http://github.com']));

    expect($context->serverUrl)->toBe('https://github.com');
});
