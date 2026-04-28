<?php

namespace Tests\Feature;

use App\Console\Commands\BuildDataDumpCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataDownloadTest extends TestCase
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
        }

        parent::tearDown();
    }

    public function test_manifest_returns_all_dump_types(): void
    {
        $response = $this->getJson('/api/downloads/manifest');

        $response->assertOk();
        $response->assertJsonStructure(array_fill_keys(BuildDataDumpCommand::TYPES, ['available', 'size', 'built_at']));
    }

    public function test_manifest_shows_unavailable_when_no_dumps_exist(): void
    {
        $response = $this->getJson('/api/downloads/manifest');

        $response->assertOk();

        foreach (BuildDataDumpCommand::TYPES as $type) {
            $response->assertJsonPath("{$type}.available", false);
            $response->assertJsonPath("{$type}.size", null);
            $response->assertJsonPath("{$type}.built_at", null);
        }
    }

    public function test_manifest_shows_available_when_dump_exists(): void
    {
        $type = 'systems';
        $path = BuildDataDumpCommand::dumpPath($type);
        file_put_contents($path, gzencode('[]'));

        $response = $this->getJson('/api/downloads/manifest');

        $response->assertOk();
        $response->assertJsonPath("{$type}.available", true);
        $this->assertNotNull($response->json("{$type}.size"));
        $this->assertNotNull($response->json("{$type}.built_at"));
    }

    public function test_download_returns_404_for_unknown_type(): void
    {
        $this->getJson('/api/downloads/unknown-type')->assertNotFound();
    }

    public function test_download_returns_503_when_dump_not_generated(): void
    {
        $this->getJson('/api/downloads/systems')->assertStatus(503);
    }

    public function test_download_returns_file_when_available(): void
    {
        $path = BuildDataDumpCommand::dumpPath('systems');
        file_put_contents($path, gzencode('[{"id":1}]'));

        $response = $this->get('/api/downloads/systems');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/gzip');
        $this->assertStringContainsString('systems.json.gz', $response->headers->get('Content-Disposition'));
    }
}
