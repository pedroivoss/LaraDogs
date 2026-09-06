<?php

namespace App\Audit\Engine\Contracts;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An analyzer's stable identity (e.g. "composer-security"). Not a plain
 * string so a typo can't silently become a different, unrelated analyzer.
 */
final readonly class AnalyzerId implements JsonSerializable, Stringable
{
    public function __construct(public string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Analyzer id cannot be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
