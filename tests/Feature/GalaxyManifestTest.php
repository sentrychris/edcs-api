<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalaxyManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_503_when_manifest_missing(): void
    {
        $path = public_path('galaxy-tiles/manifest.json');
        if (is_file($path)) {
            unlink($path);
        }

        $this->getJson('/api/galaxy/manifest')->assertStatus(503);
    }

    public function test_returns_manifest_contents_when_present(): void
    {
        $dir = public_path('galaxy-tiles');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $manifest = [
            'version' => 7,
            'generated_at' => '2026-04-17T00:00:00+00:00',
            'sector_size' => 1280,
            'lod1_size' => 5120,
            'lod0' => ['url' => '/api/galaxy/tiles/v7/lod0.bin', 'count' => 42],
            'lod1_url_template' => '/api/galaxy/tiles/v7/lod1/{key}.bin',
            'lod2_url_template' => '/api/galaxy/tiles/v7/lod2/{key}.bin',
            'lod1_tiles' => ['0_0_0'],
            'lod2_tiles' => ['39_32_39'],
        ];

        file_put_contents($dir.'/manifest.json', json_encode($manifest));

        try {
            $this->getJson('/api/galaxy/manifest')
                ->assertOk()
                ->assertExactJson($manifest);
        } finally {
            unlink($dir.'/manifest.json');
        }
    }

    public function test_tile_route_streams_binary_file(): void
    {
        $dir = public_path('galaxy-tiles/v9/lod2');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.'/10_20_30.bin';
        $body = pack('V', 1).pack('VV', 0x01020304, 0x05060708);
        file_put_contents($path, $body);

        try {
            $response = $this->get('/api/galaxy/tiles/v9/lod2/10_20_30.bin');
            $response->assertOk();
            $this->assertSame($body, $response->streamedContent() ?: $response->getContent());
            $response->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        } finally {
            unlink($path);
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
    }

    public function test_tile_route_rejects_path_traversal(): void
    {
        $this->get('/api/galaxy/tiles/v1/..%2F..%2Fetc%2Fpasswd')->assertNotFound();
        $this->get('/api/galaxy/tiles/v1/lod3/x.bin')->assertNotFound();
        $this->get('/api/galaxy/tiles/va/lod0.bin')->assertNotFound();
    }

    public function test_tile_route_returns_404_when_file_missing(): void
    {
        $this->get('/api/galaxy/tiles/v999/lod0.bin')->assertNotFound();
    }
}
