<?php

namespace App\Audit\Findings;

enum ScanStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
