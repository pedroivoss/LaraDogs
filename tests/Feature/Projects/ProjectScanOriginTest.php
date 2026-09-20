<?php

namespace Tests\Feature\Projects;

use App\Audit\Findings\ScanOrigin;
use App\Audit\Findings\ScanStatus;
use App\Audit\Projects\RegisterProject;
use App\Models\Audit\Project;
use App\Models\Audit\Scan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 7.1.4.1: Scan History / Scan Detail expose scan ORIGIN (why a
 * scan happened) — never the initiating user (Owner privacy, 7.1.3).
 */
class ProjectScanOriginTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return (new RegisterProject)->register(dirname(__DIR__, 2).'/Fixtures/discovery/laravel-blade')->project;
    }

    private function scan(Project $project, ScanOrigin $origin, ?User $actor = null): Scan
    {
        return Scan::query()->create([
            'project_id' => $project->id,
            'status' => ScanStatus::Completed,
            'origin' => $origin,
            'initiated_by_user_id' => $actor?->id,
            'started_at' => now(),
            'finished_at' => now(),
            'project_profile' => ['project' => ['type' => 'laravel']],
        ]);
    }

    public function test_origin_labels_are_the_user_facing_spelling(): void
    {
        $this->assertSame('Manual', ScanOrigin::Manual->label());
        $this->assertSame('Scheduled', ScanOrigin::Scheduled->label());
        $this->assertSame('CLI', ScanOrigin::Cli->label());

        // Every case has an explicit label (a new case must define one).
        foreach (ScanOrigin::cases() as $origin) {
            $this->assertNotSame('', $origin->label());
        }
    }

    public function test_scan_history_lists_each_scans_origin_and_label(): void
    {
        $project = $this->project();
        $cli = $this->scan($project, ScanOrigin::Cli);
        $scheduled = $this->scan($project, ScanOrigin::Scheduled);
        $manual = $this->scan($project, ScanOrigin::Manual);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.scans', $project))
            ->assertInertia(fn ($page) => $page
                ->has('scans', 3)
                // newest first (id tiebreaker within the same second)
                ->where('scans.0.id', $manual->public_id)
                ->where('scans.0.origin', 'manual')
                ->where('scans.0.origin_label', 'Manual')
                ->where('scans.1.id', $scheduled->public_id)
                ->where('scans.1.origin', 'scheduled')
                ->where('scans.1.origin_label', 'Scheduled')
                ->where('scans.2.id', $cli->public_id)
                ->where('scans.2.origin', 'cli')
                ->where('scans.2.origin_label', 'CLI')
            );
    }

    public function test_scan_detail_exposes_the_origin(): void
    {
        $project = $this->project();
        $scan = $this->scan($project, ScanOrigin::Scheduled);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.scans.show', ['project' => $project, 'scan' => $scan]))
            ->assertInertia(fn ($page) => $page
                ->where('scan.origin', 'scheduled')
                ->where('scan.origin_label', 'Scheduled')
            );
    }

    public function test_the_initiating_user_is_never_exposed_by_history_or_detail(): void
    {
        $project = $this->project();
        $owner = User::factory()->owner()->create(['email' => 'the-owner@example.test', 'name' => 'Secret Owner Name']);
        $scan = $this->scan($project, ScanOrigin::Manual, $owner);

        $viewer = User::factory()->create();

        foreach ([
            route('projects.scans', $project),
            route('projects.scans.show', ['project' => $project, 'scan' => $scan]),
            route('projects.show', $project),
        ] as $url) {
            $body = $this->actingAs($viewer)->get($url)->getContent();

            $this->assertStringNotContainsString('the-owner@example.test', $body);
            $this->assertStringNotContainsString('Secret Owner Name', $body);
            $this->assertStringNotContainsString('initiated_by_user_id', $body);
            $this->assertStringNotContainsString('initiator', $body);
        }
    }

    public function test_an_unknown_stored_origin_is_never_silently_coerced_into_a_valid_one(): void
    {
        $project = $this->project();
        $scan = $this->scan($project, ScanOrigin::Manual);

        DB::table('scans')->where('id', $scan->id)->update(['origin' => 'bogus']);

        // The enum cast refuses it loudly — there is no fallback label that
        // would present an impossible state as Manual/Scheduled/CLI.
        $this->expectException(\ValueError::class);
        Scan::query()->findOrFail($scan->id)->origin;
    }
}
