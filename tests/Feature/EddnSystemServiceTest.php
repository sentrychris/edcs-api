<?php

namespace Tests\Feature;

use App\Models\System;
use App\Services\Eddn\EddnSystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EddnSystemServiceTest extends TestCase
{
    use RefreshDatabase;

    private function buildBatch(array $systems): array
    {
        $messages = [];

        foreach ($systems as $system) {
            $messages[] = [
                '$schemaRef' => 'https://eddn.edcd.io/schemas/journal/1',
                'header' => ['softwareName' => 'EDO Materials Helper', 'softwareVersion' => '1.0'],
                'message' => [
                    'StarSystem' => $system['name'],
                    'SystemAddress' => $system['id64'],
                    'StarPos' => $system['pos'],
                ],
            ];
        }

        return ['messages' => $messages];
    }

    public function test_latest_system_cache_holds_only_the_last_system_in_a_batch(): void
    {
        Cache::forget('latest_system');

        app(EddnSystemService::class)->process($this->buildBatch([
            ['name' => 'Sol', 'id64' => 10477373803, 'pos' => [0.0, 0.0, 0.0]],
            ['name' => 'Alpha Centauri', 'id64' => 3107509474763, 'pos' => [3.03, -0.09, 3.15]],
            ['name' => 'Wolf 359', 'id64' => 1183229809290, 'pos' => [3.03, 2.78, 7.25]],
        ]));

        $latest = Cache::get('latest_system');

        $this->assertInstanceOf(System::class, $latest);
        $this->assertSame('Wolf 359', $latest->name);
    }

    public function test_processing_an_empty_batch_does_not_touch_the_cache(): void
    {
        Cache::set('latest_system', 'sentinel');

        app(EddnSystemService::class)->process(['messages' => []]);

        $this->assertSame('sentinel', Cache::get('latest_system'));
    }
}
