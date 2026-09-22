<?php

use App\Audit\Source\Git\RemoteUrlSanitizer;

it('removes embedded username, password and token from an HTTPS remote', function (string $raw) {
    $clean = RemoteUrlSanitizer::sanitize($raw);

    expect($clean)->toBe('https://example.com/org/repo.git')
        ->and($clean)->not->toContain('user')->not->toContain('token')->not->toContain('secret');
})->with([
    'user:password' => 'https://user:secret@example.com/org/repo.git',
    'token only' => 'https://token@example.com/org/repo.git',
    'password containing @' => 'https://user:se@cret@example.com/org/repo.git',
    'query token and fragment' => 'https://example.com/org/repo.git?token=secret#frag',
]);

it('keeps a normal HTTPS remote as is', function () {
    expect(RemoteUrlSanitizer::sanitize('https://gitlab.example.org:8443/group/sub/repo.git'))
        ->toBe('https://gitlab.example.org:8443/group/sub/repo.git');
});

it('keeps an SCP-style SSH remote without the user', function () {
    expect(RemoteUrlSanitizer::sanitize('git@github.com:org/repo.git'))->toBe('github.com:org/repo.git');
});

it('keeps an ssh:// remote without user or password', function () {
    expect(RemoteUrlSanitizer::sanitize('ssh://deploy:pw@host.example:2222/org/repo.git'))
        ->toBe('ssh://host.example:2222/org/repo.git');
});

it('never exposes a local absolute path', function (string $raw) {
    $clean = RemoteUrlSanitizer::sanitize($raw);

    expect($clean)->toBe(RemoteUrlSanitizer::LOCAL_LABEL)
        ->and($clean)->not->toContain('home')->not->toContain('secret');
})->with([
    'absolute' => '/home/pedro/secret-projects/repo.git',
    'relative' => '../sibling/repo.git',
    'home' => '~/repos/repo.git',
    'windows' => 'C:\\Users\\pedro\\repo.git',
    'file url' => 'file:///home/pedro/secret-projects/repo.git',
    'file url host' => 'file://localhost/home/pedro/repo.git',
]);

it('reports malformed or unsupported remotes safely without echoing them', function (string $raw) {
    expect(RemoteUrlSanitizer::sanitize($raw))->toBe(RemoteUrlSanitizer::UNKNOWN_LABEL);
})->with([
    'unknown scheme' => 'ftp://user:pw@host/x',
    'whitespace' => 'https://host/a b',
    'ambiguous authority' => 'https://tok/en@host/path',
    'control characters' => "https://host/x\x00y",
    'invalid host' => 'https://exa mple.com/x',
]);

it('returns null for an empty or missing remote', function () {
    expect(RemoteUrlSanitizer::sanitize(null))->toBeNull()
        ->and(RemoteUrlSanitizer::sanitize('   '))->toBeNull();
});

it('bounds an oversized remote', function () {
    expect(RemoteUrlSanitizer::sanitize('https://example.com/'.str_repeat('a', 5000)))
        ->toBe(RemoteUrlSanitizer::UNKNOWN_LABEL);
});
