<?php

namespace App\Audit\Engine\Registry;

use App\Audit\Engine\Contracts\AnalyzerId;
use RuntimeException;

final class DuplicateAnalyzerIdException extends RuntimeException
{
    public function __construct(AnalyzerId $id)
    {
        parent::__construct("Analyzer id [{$id}] is already registered.");
    }
}
