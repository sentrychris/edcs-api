<?php

namespace App\Services;

use App\Facades\DiscordAlert;
use App\Models\FleetCarrier;
use App\Models\System;
use App\Models\SystemBody;
use DateTime;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class EdsmApiService extends ApiService
{
    /**
     * Search EDSM for system data by system name and update records if found.
     *
     * @param  string  $name  - the system name
     * @return mixed the created system record or false
     */
    public function updateSystem(string $name): System|bool
    {
        $system = false;

        if (strlen($name) > 0 && ctype_digit(substr($name, 0, 1))) {
            // Split the string in case it's a slug prefixed with the id64
            $parts = explode('-', $name, 2);
            $systemName = $parts[1];
        } else {
            $systemName = $name;
        }

        try {
            // Set the API config and make the request
            $response = $this->setConfig(config('elite.edsm'))
                ->setCategory('systems')
                ->get(key: 'system', params: [
                    'systemName' => $systemName,
                    'showCoordinates' => true,
                    'showInformation' => true,
                    'showId' => true,
                ]);

            $this->setApiRequestCounter();

            // If there is a successful response, update the system record
            if ($response) {
                $system = System::updateOrCreate(['id64' => $response->id64], [
                    'id64' => $response->id64,
                    'name' => $response->name,
                    'coords_x' => $response->coords->x,
                    'coords_y' => $response->coords->y,
                    'coords_z' => $response->coords->z,
                    'updated_at' => now(),
                ]);
            } else {
                $message = 'Error updating system: No response from EDSM API for '.$systemName;
                Log::channel('import:system')->error($message);
                DiscordAlert::edsm(self::class, $message, false);
            }
        } catch (Exception $e) {
            $message = 'Error updating system: '.$systemName.': '.$e->getMessage();
            Log::channel('import:system')->error($message);
            DiscordAlert::edsm(self::class, $message, false);
        }

        return $system;
    }

    /**
     * Search EDSM for system bodies data by system name and update records if found.
     *
     * @param  System  $system  - the system we are searching bodies for
     */
    public function updateSystemBodies(System $system): void
    {
        $response = $this->setConfig(config('elite.edsm'))
            ->setCategory('system')
            ->get(key: 'bodies', params: [
                'systemName' => $system->name,
            ]);

        $this->setApiRequestCounter();

        if ($response) {
            $bodies = [];
            if (property_isset($response, 'bodies')) {
                $bodies = $response->bodies;
            } elseif (is_array($response) && isset($response['bodies'])) {
                $bodies = $response['bodies'];
            }

            $system->body_count = property_isset($response, 'bodyCount')
                ? $response->bodyCount
                : null;

            $system->save();

            if ($bodies) {
                $records = [];

                foreach ($bodies as $body) {
                    try {
                        $id = random_int(100000000, 999999999);
                        $bodyId = $id;

                        if (property_isset($body, 'id64')) {
                            $id = $body->id64;
                            $bodyId = $body->bodyId;
                        }

                        $records[] = [
                            'id64' => $id,
                            'body_id' => $bodyId,
                            'system_id' => $system->id,
                            'name' => $body->name,
                            'type' => $body->type,
                            'sub_type' => $body->subType,
                            'slug' => Str::slug($id.' '.$body->name),
                            'discovered_by' => property_isset($body, 'discovery') ? $body->discovery->commander : null,
                            'discovered_at' => property_isset($body, 'discovery') ? $body->discovery->date : null,
                            'distance_to_arrival' => property_isset($body, 'distanceToArrival') ? $body->distanceToArrival : null,
                            'is_main_star' => property_isset($body, 'isMainStar') ? $body->isMainStar : false,
                            'is_scoopable' => property_isset($body, 'isScoopable') ? $body->isScoopable : false,
                            'spectral_class' => property_isset($body, 'spectralClass') ? $body->spectralClass : null,
                            'luminosity' => property_isset($body, 'luminosity') ? $body->luminosity : null,
                            'solar_masses' => property_isset($body, 'solarMasses') ? $body->solarMasses : null,
                            'solar_radius' => property_isset($body, 'solarRadius') ? $body->solarRadius : null,
                            'absolute_magnitude' => property_isset($body, 'absoluteMagnitude') ? $body->absoluteMagnitude : null,
                            'surface_temp' => property_isset($body, 'surfaceTemperature') ? $body->surfaceTemperature : null,
                            'radius' => property_isset($body, 'radius') ? $body->radius : null,
                            'gravity' => property_isset($body, 'gravity') ? $body->gravity : null,
                            'earth_masses' => property_isset($body, 'earthMasses') ? $body->earthMasses : null,
                            'atmosphere_type' => property_isset($body, 'atmosphereType') ? $body->atmosphereType : null,
                            'volcanism_type' => property_isset($body, 'volcanismType') ? $body->volcanismType : null,
                            'terraforming_state' => property_isset($body, 'terraformingState') ? $body->terraformingState : null,
                            'is_landable' => property_isset($body, 'isLandable') ? $body->isLandable : false,
                            'orbital_period' => property_isset($body, 'orbitalPeriod') ? $body->orbitalPeriod : null,
                            'orbital_eccentricity' => property_isset($body, 'orbitalEccentricity') ? $body->orbitalEccentricity : null,
                            'orbital_inclination' => property_isset($body, 'orbitalInclination') ? $body->orbitalInclination : null,
                            'arg_of_periapsis' => property_isset($body, 'argOfPeriapsis') ? $body->argOfPeriapsis : null,
                            'rotational_period' => property_isset($body, 'rotationalPeriod') ? $body->rotationalPeriod : null,
                            'is_tidally_locked' => property_isset($body, 'rotationalPeriodTidallyLocked') ? $body->rotationalPeriodTidallyLocked : false,
                            'semi_major_axis' => property_isset($body, 'semiMajorAxis') ? $body->semiMajorAxis : null,
                            'axial_tilt' => property_isset($body, 'axialTilt') ? $body->axialTilt : null,
                            'rings' => property_isset($body, 'rings') ? json_encode($body->rings) : null,
                            'parents' => property_isset($body, 'parents') ? json_encode($body->parents) : null,
                        ];
                    } catch (Exception $e) {
                        $message = 'Error updating system bodies: '.$system->name.': '.$e->getMessage();
                        Log::channel('import:system')->error($message);
                        DiscordAlert::edsm(self::class, $message, false);
                    }
                }

                if (! empty($records)) {
                    try {
                        SystemBody::upsert($records, ['id64'], [
                            'body_id', 'name', 'type', 'sub_type', 'discovered_by', 'discovered_at',
                            'distance_to_arrival', 'is_main_star', 'is_scoopable', 'spectral_class',
                            'luminosity', 'solar_masses', 'solar_radius', 'absolute_magnitude',
                            'surface_temp', 'radius', 'gravity', 'earth_masses', 'atmosphere_type',
                            'volcanism_type', 'terraforming_state', 'is_landable', 'orbital_period',
                            'orbital_eccentricity', 'orbital_inclination', 'arg_of_periapsis',
                            'rotational_period', 'is_tidally_locked', 'semi_major_axis', 'axial_tilt',
                            'rings', 'parents',
                        ]);
                    } catch (Exception $e) {
                        $message = 'Error upserting system bodies: '.$system->name.': '.$e->getMessage();
                        Log::channel('import:system')->error($message);
                        DiscordAlert::edsm(self::class, $message, false);
                    }
                }
            }
        } else {
            $message = 'Error updating system bodies: No response from EDSM API for '.$system->name;
            Log::channel('import:system')->error($message);
            DiscordAlert::edsm(self::class, $message, false);
        }
    }

    /**
     * Search EDSM for system information data by system name and update records if found.
     *
     * @param  System  $system  - the system we are searching information for
     */
    public function updateSystemInformation(System $system): void
    {
        $response = $this->setConfig(config('elite.edsm'))
            ->setCategory('systems')
            ->get(key: 'system', params: [
                'systemName' => $system->name,
                'showInformation' => true,
            ]);

        $this->setApiRequestCounter();

        if ($response && property_isset($response, 'information')) {
            try {
                $information = $response->information;

                $system->information()->updateOrCreate([
                    'allegiance' => property_isset($information, 'allegiance')
                        ? $information->allegiance
                        : null,

                    'government' => property_isset($information, 'government')
                        ? $information->government
                        : null,

                    'faction' => property_isset($information, 'faction')
                        ? $information->faction
                        : null,

                    'faction_state' => property_isset($information, 'factionState')
                        ? $information->factionState
                        : null,

                    'economy' => property_isset($information, 'economy')
                        ? $information->economy
                        : null,

                    'population' => property_isset($information, 'population')
                        ? $information->population
                        : 0,

                    'security' => property_isset($information, 'security')
                        ? $information->security
                        : null,
                ]);
            } catch (Exception $e) {
                $message = 'Error updating system information: '.$system->name.': '.$e->getMessage();
                Log::channel('import:system')->error($message);
                DiscordAlert::edsm(self::class, $message, false);
            }
        } else {
            $message = 'Error updating system information: No response from EDSM API for '.$system->name;
            Log::channel('import:system')->error($message);
            DiscordAlert::edsm(self::class, $message, false);
        }
    }

    /**
     * Search EDSM for system stations data by system name and update records if found.
     *
     * @param  System  $system  - the system we are searching stations for
     */
    public function updateSystemStations(System $system): void
    {
        $response = $this->setConfig(config('elite.edsm'))
            ->setCategory('system')
            ->get(key: 'stations', subkey: 'stations', params: [
                'systemName' => $system->name,
                'showId' => true,
            ]);

        $this->setApiRequestCounter();

        if ($response && property_isset($response, 'stations')) {
            $stations = $response->stations;
            $seenCarrierMarketIds = [];

            foreach ($stations as $station) {
                try {
                    if (! $station->type) {
                        $station->type = 'Station';
                    }

                    if ($station->type === 'Fleet Carrier') {
                        $carrier = $this->upsertFleetCarrier($system, $station);
                        if ($carrier !== null) {
                            $seenCarrierMarketIds[] = $carrier->market_id;
                        }

                        continue;
                    }

                    $system->stations()->updateOrCreate(
                        [
                            'name' => $station->name,
                            'type' => $station->type,
                        ],
                        [
                            'market_id' => property_isset($station, 'marketId')
                                ? $station->marketId
                                : null,

                            'distance_to_arrival' => property_isset($station, 'distanceToArrival')
                                ? $station->distanceToArrival
                                : null,

                            'body' => property_isset($station, 'body')
                                ? json_encode($station->body)
                                : null,

                            'allegiance' => property_isset($station, 'allegiance')
                                ? $station->allegiance
                                : null,

                            'government' => property_isset($station, 'government')
                                ? $station->government
                                : null,

                            'economy' => property_isset($station, 'economy')
                                ? $station->economy
                                : null,

                            'second_economy' => property_isset($station, 'secondEconomy')
                                ? $station->secondEconomy
                                : null,

                            'has_market' => property_isset($station, 'haveMarket')
                                ? $station->haveMarket
                                : null,

                            'has_shipyard' => property_isset($station, 'haveShipyard')
                                ? $station->haveShipyard
                                : null,

                            'has_outfitting' => property_isset($station, 'haveOutfitting')
                                ? $station->haveOutfitting
                                : null,

                            'other_services' => is_array($station->otherServices)
                                ? implode(',', $station->otherServices)
                                : null,

                            'controlling_faction' => property_isset($station, 'controllingFaction')
                                ? $station->controllingFaction->name
                                : null,

                            'information_last_updated' => property_isset($station, 'updateTime')
                                ? $this->date($station->updateTime->information)
                                : null,

                            'market_last_updated' => property_isset($station, 'updateTime')
                                ? $this->date($station->updateTime->market)
                                : null,

                            'shipyard_last_updated' => property_isset($station, 'updateTime')
                                ? $this->date($station->updateTime->shipyard)
                                : null,

                            'outfitting_last_updated' => property_isset($station, 'updateTime')
                                ? $this->date($station->updateTime->outfitting)
                                : null,
                        ]
                    );
                } catch (Exception $e) {
                    $message = 'Error updating system stations: '.$system->name.': '.$e->getMessage();
                    Log::channel('import:system')->error($message);
                    DiscordAlert::edsm(self::class, $message, false);
                }
            }

            // Carriers previously recorded at this system but absent from the
            // current EDSM response have relocated (or been decommissioned) —
            // drop them here so the system's carrier list stays accurate. If
            // they reappear elsewhere, the next /systems/{slug} hit for that
            // system will re-seat them via the market_id-keyed upsert.
            FleetCarrier::where('system_id', $system->id)
                ->whereNotIn('market_id', $seenCarrierMarketIds)
                ->delete();
        } else {
            $message = 'Error updating system stations: No response from EDSM API for '.$system->name;
            Log::channel('import:system')->error($message);
            DiscordAlert::edsm(self::class, $message, false);
        }
    }

    /**
     * Upsert a fleet carrier keyed on its global market_id. If the carrier
     * has since moved to this system from another, its system_id gets
     * overwritten here.
     *
     * @param  System  $system  - the system the carrier is currently docked in
     * @param  object  $station  - the raw EDSM station payload
     */
    private function upsertFleetCarrier(System $system, object $station): ?FleetCarrier
    {
        if (! property_isset($station, 'marketId')) {
            return null;
        }

        return FleetCarrier::updateOrCreate(
            ['market_id' => $station->marketId],
            [
                'system_id' => $system->id,
                'name' => $station->name,

                'distance_to_arrival' => property_isset($station, 'distanceToArrival')
                    ? $station->distanceToArrival
                    : null,

                'allegiance' => property_isset($station, 'allegiance')
                    ? $station->allegiance
                    : null,

                'government' => property_isset($station, 'government')
                    ? $station->government
                    : null,

                'economy' => property_isset($station, 'economy')
                    ? $station->economy
                    : null,

                'second_economy' => property_isset($station, 'secondEconomy')
                    ? $station->secondEconomy
                    : null,

                'has_market' => property_isset($station, 'haveMarket')
                    ? $station->haveMarket
                    : null,

                'has_shipyard' => property_isset($station, 'haveShipyard')
                    ? $station->haveShipyard
                    : null,

                'has_outfitting' => property_isset($station, 'haveOutfitting')
                    ? $station->haveOutfitting
                    : null,

                'other_services' => is_array($station->otherServices)
                    ? implode(',', $station->otherServices)
                    : null,

                'information_last_updated' => property_isset($station, 'updateTime')
                    ? $this->date($station->updateTime->information)
                    : null,

                'market_last_updated' => property_isset($station, 'updateTime')
                    ? $this->date($station->updateTime->market)
                    : null,

                'shipyard_last_updated' => property_isset($station, 'updateTime')
                    ? $this->date($station->updateTime->shipyard)
                    : null,

                'outfitting_last_updated' => property_isset($station, 'updateTime')
                    ? $this->date($station->updateTime->outfitting)
                    : null,
            ]
        );
    }

    private function date($date, $format = 'Y-m-d H:i:s', $minYear = 2013, $maxYear = 2026)
    {
        $d = DateTime::createFromFormat($format, $date);

        if ($d && $d->format($format) === $date) {
            $year = (int) $d->format('Y');
            if ($year >= $minYear && $year <= $maxYear) {
                return $date;
            }
        }

        return date('Y-m-d H:i:s');
    }

    /**
     * Get the API request counter for EDSM.
     */
    public function getApiRequestCounter(): int
    {
        return Redis::get('edsm_api_called') ?? 0;
    }

    /**
     * Set the API request counter for EDSM.
     */
    private function setApiRequestCounter(): void
    {
        $counter = Redis::get('edsm_api_called') ?? 1;
        Redis::set('edsm_api_called', $counter + 1, 'EX', 120);
    }
}
