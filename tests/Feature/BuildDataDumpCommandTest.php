<?php

namespace Tests\Feature;

use App\Console\Commands\BuildDataDumpCommand;
use App\Models\FleetCarrier;
use App\Models\System;
use App\Models\SystemBody;
use App\Models\SystemInformation;
use App\Models\SystemStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildDataDumpCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_dir(storage_path('dumps'))) {
            mkdir(storage_path('dumps'), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (BuildDataDumpCommand::TYPES as $type) {
            $path = BuildDataDumpCommand::dumpPath($type);
            if (file_exists($path)) {
                unlink($path);
            }
            if (file_exists($path.'.tmp')) {
                unlink($path.'.tmp');
            }
        }

        parent::tearDown();
    }

    public function test_fails_without_type_option(): void
    {
        $this->artisan('dumps:build')->assertFailed();
    }

    public function test_fails_with_unknown_type(): void
    {
        $this->artisan('dumps:build --type=unknown')->assertFailed();
    }

    public function test_builds_systems_dump(): void
    {
        System::factory()->count(3)->create();

        $this->artisan('dumps:build --type=systems')->assertSuccessful();

        $path = BuildDataDumpCommand::dumpPath('systems');
        $this->assertFileExists($path);

        $data = json_decode(gzdecode(file_get_contents($path)), true);
        $this->assertCount(3, $data);
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('name', $data[0]);
        $this->assertArrayHasKey('coords_x', $data[0]);
    }

    public function test_builds_populated_systems_dump_filters_by_population(): void
    {
        $populated = System::factory()->create();
        SystemInformation::factory()->create(['system_id' => $populated->id, 'population' => 1000]);

        $unpopulated = System::factory()->create();
        SystemInformation::factory()->create(['system_id' => $unpopulated->id, 'population' => 0]);

        System::factory()->create(); // no information at all

        $this->artisan('dumps:build --type=populated-systems')->assertSuccessful();

        $data = json_decode(gzdecode(file_get_contents(BuildDataDumpCommand::dumpPath('populated-systems'))), true);
        $this->assertCount(1, $data);
        $this->assertEquals($populated->name, $data[0]['name']);
    }

    public function test_builds_systems_recent_dump_filters_by_updated_at(): void
    {
        $recent = System::factory()->create(['updated_at' => now()->subDays(3)]);
        System::factory()->create(['updated_at' => now()->subDays(10)]);

        $this->artisan('dumps:build --type=systems-recent')->assertSuccessful();

        $data = json_decode(gzdecode(file_get_contents(BuildDataDumpCommand::dumpPath('systems-recent'))), true);
        $this->assertCount(1, $data);
        $this->assertEquals($recent->name, $data[0]['name']);
    }

    public function test_builds_bodies_dump(): void
    {
        $system = System::factory()->create();
        SystemBody::factory()->count(2)->create(['system_id' => $system->id]);

        $this->artisan('dumps:build --type=bodies')->assertSuccessful();

        $path = BuildDataDumpCommand::dumpPath('bodies');
        $this->assertFileExists($path);

        $data = json_decode(gzdecode(file_get_contents($path)), true);
        $this->assertCount(2, $data);
    }

    public function test_builds_stations_dump(): void
    {
        $system = System::factory()->create();
        SystemStation::factory()->count(2)->create(['system_id' => $system->id]);

        $this->artisan('dumps:build --type=stations')->assertSuccessful();

        $data = json_decode(gzdecode(file_get_contents(BuildDataDumpCommand::dumpPath('stations'))), true);
        $this->assertCount(2, $data);
    }

    public function test_builds_carriers_dump(): void
    {
        $system = System::factory()->create();
        FleetCarrier::factory()->count(2)->create(['system_id' => $system->id]);

        $this->artisan('dumps:build --type=carriers')->assertSuccessful();

        $data = json_decode(gzdecode(file_get_contents(BuildDataDumpCommand::dumpPath('carriers'))), true);
        $this->assertCount(2, $data);
    }

    public function test_builds_all_dumps(): void
    {
        $this->artisan('dumps:build --type=all')->assertSuccessful();

        foreach (BuildDataDumpCommand::TYPES as $type) {
            $this->assertFileExists(BuildDataDumpCommand::dumpPath($type), "Missing dump for type: {$type}");
        }
    }

    public function test_dump_is_valid_json_array_when_empty(): void
    {
        $this->artisan('dumps:build --type=systems')->assertSuccessful();

        $data = json_decode(gzdecode(file_get_contents(BuildDataDumpCommand::dumpPath('systems'))), true);
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }
}
