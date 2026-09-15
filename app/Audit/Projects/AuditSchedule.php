<?php

namespace App\Audit\Projects;

/**
 * V1 scheduling choices — deliberately NOT an arbitrary cron expression
 * UI (see docs/self-hosting.md). `Project.audit_schedule`.
 */
enum AuditSchedule: string
{
    case Disabled = 'disabled';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
