<?php

namespace App\Audit\Engine\Contracts;

enum AvailabilityStatus: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
}
