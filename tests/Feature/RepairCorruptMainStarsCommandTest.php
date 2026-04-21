<?php

namespace Tests\Feature;

use App\Models\System;
use App\Models\SystemBody;
use App\Services\EdsmApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepairCorruptMainStarsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedCorruptMainStar(System $system): SystemBody
    {
        // Mirror the old buggy ingestion: a main star where id64 == body_id
        // and both are 9-digit random ints, while planets reference Star:0.
        return SystemBody::factory()->create([
            'system_id' => $system->id,
            'id64' => 639619734,
            'body_id' => 639619734,
            'is_main_star' => 1,
            'name' => $system->name,
            'type' => 'Star',
        ]);
    }

    private function fakeEdsmBodies(string $systemName): void
    {
        Http::fake([
            'www.edsm.net/api-system-v1/bodies*' => Http::response([
                'bodyCount' => 2,
                'bodies' => [
                    // Reproduce EDSM's response shape where the main star
                    // is missing id64/bodyId entirely.
                    [
                        'name' => $systemName,
                        'type' => 'Star',
                        'subType' => 'M (Red dwarf) Star',
                        'isMainStar' => true,
                        'parents' => null,
                    ],
                    [
                        'id64' => 36034161969875800,
                        'bodyId' => 1,
                        'name' => $systemName.' 1',
                        'type' => 'Planet',
                        'subType' => 'High metal content world',
                        'isMainStar' => false,
                        'parents' => [['Star' => 0]],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_dry_run_reports_corrupt_rows_without_mutating(): void
    {
        $system = System::factory()->create(['name' => 'Corrupt System', 'slug' => 'corrupt-system']);
        $this->seedCorruptMainStar($system);

        $this->mock(EdsmApiService::class, function ($mock) {
            $mock->shouldNotReceive('updateSystemBodies');
        });

        $this->artisan('systems:repair-corrupt-main-stars', ['--dry-run' => true])
            ->expectsOutputToContain('Found 1 corrupt main star(s):')
            ->expectsOutputToContain('corrupt-system')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertDatabaseHas('systems_bodies', [
            'system_id' => $system->id,
            'body_id' => 639619734,
        ]);
    }

    public function test_repair_deletes_and_reingests_affected_system(): void
    {
        $system = System::factory()->create(['name' => 'Trappist-Test', 'slug' => 'trappist-test']);
        $this->seedCorruptMainStar($system);
        $this->fakeEdsmBodies('Trappist-Test');

        $this->artisan('systems:repair-corrupt-main-stars', ['--sleep' => 0])
            ->expectsConfirmation('Delete and re-ingest bodies for these systems?', 'yes')
            ->expectsOutputToContain('main star body_id=0')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('systems_bodies', [
            'system_id' => $system->id,
            'body_id' => 639619734,
        ]);
        $this->assertDatabaseHas('systems_bodies', [
            'system_id' => $system->id,
            'is_main_star' => 1,
            'body_id' => 0,
        ]);
    }

    public function test_reports_no_rows_when_all_clean(): void
    {
        $this->artisan('systems:repair-corrupt-main-stars', ['--dry-run' => true])
            ->expectsOutputToContain('No corrupt main stars found.')
            ->assertExitCode(0);
    }

    public function test_legitimate_binary_main_star_is_not_flagged(): void
    {
        $system = System::factory()->create(['name' => 'Dironii-Test', 'slug' => 'dironii-test']);
        // Real binary main star: id64 is the full EDSM 18-digit id, body_id
        // is a low integer representing position in a Null-barycenter system.
        SystemBody::factory()->create([
            'system_id' => $system->id,
            'id64' => 72060701480194786,
            'body_id' => 2,
            'is_main_star' => 1,
            'name' => 'Dironii A',
            'type' => 'Star',
        ]);

        $this->artisan('systems:repair-corrupt-main-stars', ['--dry-run' => true])
            ->expectsOutputToContain('No corrupt main stars found.')
            ->assertExitCode(0);
    }
}
