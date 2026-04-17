<?php

namespace Tests\Feature;

use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BakeGalaxyTilesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/edcs-galaxy-tiles-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpRoot)) {
            $this->rrmdir($this->tmpRoot);
        }
        parent::tearDown();
    }

    public function test_bakes_manifest_and_tiles_for_seeded_systems(): void
    {
        // Two systems sharing a sector (Sgr A* sector at 0,0,0 → world sector 39,32,39)
        // and one in a different sector.
        System::factory()->create(['id64' => 1, 'coords_x' => 0,    'coords_y' => 0, 'coords_z' => 0]);
        System::factory()->create(['id64' => 2, 'coords_x' => 100,  'coords_y' => 0, 'coords_z' => 0]);
        System::factory()->create(['id64' => 3, 'coords_x' => 5000, 'coords_y' => 0, 'coords_z' => 0]);

        $this->artisan('galaxy:bake-tiles', ['--output' => $this->tmpRoot])
            ->assertSuccessful();

        $this->assertFileExists($this->tmpRoot.'/manifest.json');
        $manifest = json_decode(file_get_contents($this->tmpRoot.'/manifest.json'), true);

        $this->assertSame(1, $manifest['version']);
        $this->assertSame(1280, $manifest['sector_size']);
        $this->assertSame(5120, $manifest['lod1_size']);
        $this->assertSame(3, $manifest['lod0']['count']);
        $this->assertSame('/api/galaxy/tiles/v1/lod0.bin', $manifest['lod0']['url']);
        $this->assertSame('/api/galaxy/tiles/v1/lod1/{key}.bin', $manifest['lod1_url_template']);
        $this->assertSame('/api/galaxy/tiles/v1/lod2/{key}.bin', $manifest['lod2_url_template']);
        $this->assertContains('39_32_39', $manifest['lod2_tiles']);
        $this->assertCount(2, $manifest['lod2_tiles']);

        // Sector tile binary: [uint32 count][uint64 id64 × count]
        $tile = file_get_contents($this->tmpRoot.'/v1/lod2/39_32_39.bin');
        $count = unpack('V', substr($tile, 0, 4))[1];
        $this->assertSame(2, $count);
        $this->assertSame(strlen($tile), 4 + 8 * $count);
    }

    public function test_subsequent_bakes_increment_version(): void
    {
        System::factory()->create(['id64' => 1, 'coords_x' => 0, 'coords_y' => 0, 'coords_z' => 0]);

        $this->artisan('galaxy:bake-tiles', ['--output' => $this->tmpRoot])->assertSuccessful();
        $this->artisan('galaxy:bake-tiles', ['--output' => $this->tmpRoot])->assertSuccessful();

        $manifest = json_decode(file_get_contents($this->tmpRoot.'/manifest.json'), true);
        $this->assertSame(2, $manifest['version']);
        $this->assertDirectoryExists($this->tmpRoot.'/v1');
        $this->assertDirectoryExists($this->tmpRoot.'/v2');
    }

    public function test_prune_option_deletes_previous_versions(): void
    {
        System::factory()->create(['id64' => 1, 'coords_x' => 0, 'coords_y' => 0, 'coords_z' => 0]);

        $this->artisan('galaxy:bake-tiles', ['--output' => $this->tmpRoot])->assertSuccessful();
        $this->artisan('galaxy:bake-tiles', ['--output' => $this->tmpRoot, '--prune' => true])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->tmpRoot.'/v1');
        $this->assertDirectoryExists($this->tmpRoot.'/v2');
    }

    private function rrmdir(string $path): void
    {
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
