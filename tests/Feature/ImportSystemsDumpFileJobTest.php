<?php

namespace Tests\Feature;

use App\Jobs\ImportSystemsDumpFileJob;
use App\Models\System;
use App\Models\SystemInformation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportSystemsDumpFileJobTest extends TestCase
{
    use RefreshDatabase;

    private string $dumpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dumpDir = storage_path('dumps');
        File::ensureDirectoryExists($this->dumpDir);
    }

    protected function tearDown(): void
    {
        File::delete($this->dumpDir.'/test_systems.json');
        parent::tearDown();
    }

    public function test_imports_new_systems_from_dump_file(): void
    {
        $this->writeDumpFile([
            $this->makeDumpSystem(1001, 'Alpha Centauri', 3.03, -0.09, 3.16),
            $this->makeDumpSystem(1002, 'Sol', 0.0, 0.0, 0.0),
        ]);

        $job = new ImportSystemsDumpFileJob('import:system', 'test_systems.json');
        $job->handle();

        $this->assertDatabaseCount('systems', 2);
        $this->assertDatabaseHas('systems', ['id64' => 1001, 'name' => 'Alpha Centauri']);
        $this->assertDatabaseHas('systems', ['id64' => 1002, 'name' => 'Sol']);
    }

    public function test_imports_system_information_from_dump_file(): void
    {
        $system = $this->makeDumpSystem(2001, 'Achenar', 67.5, -119.47, 24.84);
        $system['allegiance'] = 'Empire';
        $system['government'] = 'Patronage';
        $system['economy'] = 'Terraforming';
        $system['population'] = 14000000000;
        $system['security'] = 'High';
        $system['controllingFaction'] = (object) [
            'name' => 'Achenar Empire League',
            'allegiance' => 'Boom',
        ];

        $this->writeDumpFile([$system]);

        $job = new ImportSystemsDumpFileJob('import:system', 'test_systems.json');
        $job->handle();

        $createdSystem = System::where('id64', 2001)->first();
        $this->assertNotNull($createdSystem);

        $info = SystemInformation::where('system_id', $createdSystem->id)->first();
        $this->assertNotNull($info);
        $this->assertEquals('Empire', $info->allegiance);
        $this->assertEquals('Patronage', $info->government);
        $this->assertEquals(14000000000, $info->population);
    }

    public function test_updates_existing_systems_on_reimport(): void
    {
        System::factory()->create([
            'id64' => 3001,
            'name' => 'OldName',
            'coords_x' => 0.0,
            'coords_y' => 0.0,
            'coords_z' => 0.0,
        ]);

        $this->writeDumpFile([
            $this->makeDumpSystem(3001, 'NewName', 1.0, 2.0, 3.0),
        ]);

        $job = new ImportSystemsDumpFileJob('import:system', 'test_systems.json');
        $job->handle();

        $this->assertDatabaseCount('systems', 1);
        $this->assertDatabaseHas('systems', [
            'id64' => 3001,
            'name' => 'NewName',
            'coords_x' => 1.0,
            'coords_y' => 2.0,
            'coords_z' => 3.0,
        ]);
    }

    public function test_generates_slug_for_new_systems(): void
    {
        $this->writeDumpFile([
            $this->makeDumpSystem(4001, 'Maia', 45.59, -21.34, -64.28),
        ]);

        $job = new ImportSystemsDumpFileJob('import:system', 'test_systems.json');
        $job->handle();

        $system = System::where('id64', 4001)->first();
        $this->assertNotNull($system->slug);
        $this->assertStringContainsString('4001', $system->slug);
        $this->assertStringContainsString('maia', $system->slug);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeDumpSystem(int $id64, string $name, float $x, float $y, float $z): array
    {
        return [
            'id64' => $id64,
            'name' => $name,
            'coords' => (object) ['x' => $x, 'y' => $y, 'z' => $z],
            'updateTime' => now()->toDateTimeString(),
        ];
    }

    private function writeDumpFile(array $systems): void
    {
        File::put(
            $this->dumpDir.'/test_systems.json',
            json_encode($systems),
        );
    }
}
