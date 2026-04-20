<?php

namespace Tests\Feature;

use App\Models\FleetCarrier;
use App\Models\System;
use App\Models\SystemStation;
use App\Services\EdsmApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FleetCarriersFromEdsmTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fake EDSM /api-system-v1/stations response containing one stationary
     * station and one fleet carrier, so the routing logic in
     * EdsmApiService::updateSystemStations can be exercised end-to-end.
     */
    private function fakeStationsResponse(array $stations): void
    {
        Http::fake([
            'www.edsm.net/api-system-v1/stations*' => Http::response([
                'stations' => $stations,
            ], 200),
        ]);
    }

    private function fakeStation(array $overrides = []): array
    {
        return array_merge([
            'marketId' => 128016384,
            'type' => 'Coriolis Starport',
            'name' => 'Daedalus',
            'distanceToArrival' => 300,
            'allegiance' => 'Federation',
            'government' => 'Democracy',
            'economy' => 'Refinery',
            'secondEconomy' => null,
            'haveMarket' => true,
            'haveShipyard' => true,
            'haveOutfitting' => true,
            'otherServices' => ['Restock', 'Refuel'],
            'updateTime' => [
                'information' => '2026-04-17 10:00:00',
                'market' => '2026-04-17 10:00:00',
                'shipyard' => '2026-04-17 10:00:00',
                'outfitting' => '2026-04-17 10:00:00',
            ],
        ], $overrides);
    }

    private function fakeCarrier(array $overrides = []): array
    {
        return array_merge([
            'marketId' => 3700291584,
            'type' => 'Fleet Carrier',
            'name' => 'B1T-05Z',
            'distanceToArrival' => 0,
            'allegiance' => 'Independent',
            'government' => 'Fleet Carrier',
            'economy' => 'Fleet Carrier',
            'secondEconomy' => null,
            'haveMarket' => true,
            'haveShipyard' => true,
            'haveOutfitting' => false,
            'otherServices' => ['Restock', 'Refuel', 'Repair'],
            'updateTime' => [
                'information' => '2026-04-17 10:01:20',
                'market' => '2026-03-17 22:57:15',
                'shipyard' => '2026-04-17 11:20:19',
                'outfitting' => null,
            ],
        ], $overrides);
    }

    public function test_fleet_carriers_from_edsm_are_routed_to_fleet_carriers_table(): void
    {
        $system = System::factory()->create();

        $this->fakeStationsResponse([$this->fakeStation(), $this->fakeCarrier()]);

        app(EdsmApiService::class)->updateSystemStations($system);

        $this->assertDatabaseCount('systems_stations', 1);
        $this->assertDatabaseHas('systems_stations', [
            'system_id' => $system->id,
            'name' => 'Daedalus',
            'type' => 'Coriolis Starport',
        ]);

        $this->assertDatabaseCount('fleet_carriers', 1);
        $this->assertDatabaseHas('fleet_carriers', [
            'system_id' => $system->id,
            'market_id' => 3700291584,
            'name' => 'B1T-05Z',
        ]);
    }

    public function test_fleet_carrier_relocation_overwrites_system_id(): void
    {
        $systemA = System::factory()->create();
        $systemB = System::factory()->create();

        // Carrier starts at system A
        FleetCarrier::factory()->create([
            'system_id' => $systemA->id,
            'market_id' => 3700291584,
            'name' => 'B1T-05Z',
        ]);

        // EDSM now reports the same carrier at system B
        $this->fakeStationsResponse([$this->fakeCarrier()]);

        app(EdsmApiService::class)->updateSystemStations($systemB);

        $this->assertDatabaseCount('fleet_carriers', 1);
        $this->assertDatabaseHas('fleet_carriers', [
            'market_id' => 3700291584,
            'system_id' => $systemB->id,
        ]);
    }

    public function test_fleet_carriers_no_longer_present_are_removed_from_the_system(): void
    {
        $system = System::factory()->create();

        $leftover = FleetCarrier::factory()->create([
            'system_id' => $system->id,
            'market_id' => 3799999999,
            'name' => 'OLD-999',
        ]);

        // EDSM response for this system no longer includes the leftover carrier
        $this->fakeStationsResponse([$this->fakeCarrier()]);

        app(EdsmApiService::class)->updateSystemStations($system);

        $this->assertDatabaseMissing('fleet_carriers', ['id' => $leftover->id]);
        $this->assertDatabaseHas('fleet_carriers', [
            'market_id' => 3700291584,
            'system_id' => $system->id,
        ]);
    }

    public function test_show_endpoint_embeds_fleet_carriers_when_requested(): void
    {
        $system = System::factory()->create();
        $carrier = FleetCarrier::factory()->create([
            'system_id' => $system->id,
            'name' => 'T3S-TXX',
        ]);

        // Avoid a real EDSM call — the cache-miss path will still invoke
        // updateSystemStations for this request because withFleetCarriers=1.
        $this->mock(EdsmApiService::class, function ($mock) {
            $mock->shouldReceive('updateSystemStations')->andReturnNull();
        });

        $response = $this->getJson("/api/systems/{$system->slug}?withFleetCarriers=1");

        $response->assertOk();
        $response->assertJsonPath('data.fleet_carriers.0.name', $carrier->name);
        $response->assertJsonPath('data.fleet_carriers.0.market_id', $carrier->market_id);
    }

    public function test_show_endpoint_always_refreshes_stations_on_cache_miss_even_when_stations_exist(): void
    {
        $system = System::factory()->create();
        SystemStation::factory()->create(['system_id' => $system->id]);

        $this->mock(EdsmApiService::class, function ($mock) {
            $mock->shouldReceive('updateSystemStations')->once();
        });

        $this->getJson("/api/systems/{$system->slug}?withStations=1")->assertOk();
    }

    public function test_show_endpoint_refreshes_stations_once_when_both_stations_and_fleet_carriers_requested(): void
    {
        $system = System::factory()->create();

        $this->mock(EdsmApiService::class, function ($mock) {
            $mock->shouldReceive('updateSystemStations')->once();
        });

        $this->getJson("/api/systems/{$system->slug}?withStations=1&withFleetCarriers=1")->assertOk();
    }
}
