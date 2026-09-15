<?php

namespace App\Console\Commands;

use App\Audit\Projects\DispatchDueProjectAudits;
use Illuminate\Console\Command;

/**
 * Thin CLI/Scheduler adapter over {@see DispatchDueProjectAudits} — see
 * `routes/console.php` for the every-minute schedule that invokes this.
 */
final class DispatchDueProjectAuditsCommand extends Command
{
    protected $signature = 'laradogs:project:dispatch-due-audits';

    protected $description = 'Enqueue audits for every project whose schedule is due (called by the Scheduler)';

    public function handle(DispatchDueProjectAudits $dispatcher): int
    {
        $dispatched = $dispatcher->dispatch();

        if ($dispatched > 0) {
            $this->components->info("Dispatched {$dispatched} scheduled audit(s).");
        }

        return self::SUCCESS;
    }
}
