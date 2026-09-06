<?php

namespace App\Audit\Engine\Contracts;

enum ApplicabilityStatus: string
{
    case Applicable = 'applicable';
    case NotApplicable = 'not_applicable';
}
