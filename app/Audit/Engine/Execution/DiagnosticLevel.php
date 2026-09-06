<?php

namespace App\Audit\Engine\Execution;

enum DiagnosticLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
