<?php

namespace App\Jobs;

use App\Models\System;
use App\Models\SystemBody;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonMachine\Items;

class ImportSystemsBodiesDumpFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $channel;

    /**
     * @var int
     */
    public $timeout = 0; // no timeout

    /**
     * @var int
     */
    public $tries = 10;

    public int $batchSize = 1500;

    protected string $file;

    /**
     * Create a new job instance.
     */
    public function __construct(string $channel, string $file)
    {
        $this->channel = $channel;
        $this->file = $file;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $file = storage_path('dumps/'.$this->file);

        if (! file_exists($file)) {
            Log::channel($this->channel)->error('No file found at '.$this->file);
            Log::channel($this->channel)->error('Exiting...');

            return;
        }

        Log::channel($this->channel)->info('Processing data from '.$this->file);
        Log::channel($this->channel)
            ->info($this->file.' (batch size: '.number_format($this->batchSize).'): please wait...');

        $bodies = Items::fromFile($file);
        $bodiesBatch = [];
        $count = 0;

        foreach ($bodies as $body) {
            $count++;

            $system = System::whereId64($body->systemId64)->first();
            if (! $system) {
                continue;
            }

            $bodyPayload = [
                'id64' => $body->id64,
                'body_id' => $body->bodyId,
                'system_id' => $system->id,
                'name' => $body->name,
                'type' => $body->type,
                'sub_type' => $body->subType,
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

            $bodiesBatch[] = $bodyPayload;
            $upsertKeys = array_diff(array_keys($bodyPayload), ['id64', 'name']);

            if (count($bodiesBatch) >= $this->batchSize) {
                $this->insertBatch($bodiesBatch, $upsertKeys);
                Log::channel($this->channel)->info('Processed batch of '.count($bodiesBatch).' records.');

                $bodiesBatch = [];
            }
        }

        // Insert any remaining records
        if (! empty($bodiesBatch)) {
            $this->insertBatch($bodiesBatch, $upsertKeys);
            Log::channel($this->channel)->info('Processed final batch of '.count($bodiesBatch).' records.');
        }

        Log::channel($this->channel)->info('Completed processing of '.$count.' records from '.$this->file);
    }

    /**
     * Insert a batch of records and their information.
     */
    private function insertBatch(array $bodiesBatch, array $upsertKeys): void
    {
        DB::transaction(function () use ($bodiesBatch, $upsertKeys) {
            // Generate slugs for records that don't have one
            foreach ($bodiesBatch as &$body) {
                $body['slug'] ??= Str::slug($body['id64'].' '.$body['name']);
            }
            unset($body);

            // Bulk upsert systems — one query instead of N exists() + create() calls
            try {
                SystemBody::upsert($bodiesBatch, ['id64'], $upsertKeys);
                Log::channel($this->channel)->info('Upserted '.count($bodiesBatch).' system body records.');
            } catch (Exception $e) {
                Log::channel($this->channel)->error('Failed to upsert systems bodies batch: '.$e->getMessage());

                return;
            }
        });
    }
}
