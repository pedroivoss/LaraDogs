<?php

use App\Console\Commands\Support\CiRevisionVerification;

it('is never a mismatch when no revision was expected', function () {
    expect(CiRevisionVerification::mismatched(null, null))->toBeFalse()
        ->and(CiRevisionVerification::mismatched(null, str_repeat('a', 40)))->toBeFalse();
});

it('is a mismatch when the audited revision is null but one was expected', function () {
    expect(CiRevisionVerification::mismatched(str_repeat('a', 40), null))->toBeTrue();
});

it('is a mismatch when the audited revision differs', function () {
    expect(CiRevisionVerification::mismatched(str_repeat('a', 40), str_repeat('b', 40)))->toBeTrue();
});

it('is not a mismatch when the audited revision is identical', function () {
    expect(CiRevisionVerification::mismatched(str_repeat('a', 40), str_repeat('a', 40)))->toBeFalse();
});

it('is case-sensitive (callers are expected to normalize beforehand)', function () {
    expect(CiRevisionVerification::mismatched(str_repeat('a', 40), str_repeat('A', 40)))->toBeTrue();
});
