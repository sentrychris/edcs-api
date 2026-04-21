<?php

namespace Tests\Feature;

use App\Models\System;
use App\Services\EdsmApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemBodiesFromEdsmTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBodiesResponse(array $bodies): void
    {
        Http::fake([
            'www.edsm.net/api-system-v1/bodies*' => Http::response([
                'bodyCount' => count($bodies),
                'bodies' => $bodies,
            ], 200),
        ]);
    }

    private function fakeStar(array $overrides = []): array
    {
        return array_merge([
            'id64' => 111,
            'bodyId' => 0,
            'name' => 'Test Star',
            'type' => 'Star',
            'subType' => 'M (Red dwarf) Star',
            'isMainStar' => true,
            'parents' => null,
        ], $overrides);
    }

    private function fakePlanet(array $overrides = []): array
    {
        return array_merge([
            'id64' => 222,
            'bodyId' => 1,
            'name' => 'Test Star 1',
            'type' => 'Planet',
            'subType' => 'High metal content world',
            'isMainStar' => false,
            'parents' => [['Star' => 0]],
        ], $overrides);
    }

    public function test_main_star_without_body_id_falls_back_to_zero(): void
    {
        $system = System::factory()->create(['name' => 'Test System']);

        // EDSM sometimes omits id64/bodyId for the main star — reproducing
        // the data shape that previously left the star with a random body_id
        // and broke the orbit-children lookup.
        $starWithoutIds = $this->fakeStar();
        unset($starWithoutIds['id64'], $starWithoutIds['bodyId']);

        $this->fakeBodiesResponse([$starWithoutIds, $this->fakePlanet()]);

        app(EdsmApiService::class)->updateSystemBodies($system);

        $this->assertDatabaseHas('systems_bodies', [
            'system_id' => $system->id,
            'name' => 'Test Star',
            'is_main_star' => 1,
            'body_id' => 0,
        ]);
    }

    public function test_body_id_is_preserved_when_edsm_provides_it(): void
    {
        $system = System::factory()->create(['name' => 'Test System']);

        $this->fakeBodiesResponse([$this->fakeStar(), $this->fakePlanet()]);

        app(EdsmApiService::class)->updateSystemBodies($system);

        $this->assertDatabaseHas('systems_bodies', [
            'system_id' => $system->id,
            'name' => 'Test Star',
            'body_id' => 0,
        ]);
        $this->assertDatabaseHas('systems_bodies', [
            'system_id' => $system->id,
            'name' => 'Test Star 1',
            'body_id' => 1,
        ]);
    }
}
