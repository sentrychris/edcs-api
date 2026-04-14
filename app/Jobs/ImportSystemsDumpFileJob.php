<?php

namespace App\Jobs;

use App\Models\System;
use App\Models\SystemInformation;
use App\Services\EdsmApiService;
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

class ImportSystemsDumpFileJob implements ShouldQueue
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

    public int $batchSize = 5000;

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

        $systems = Items::fromFile($file);
        $systemBatch = [];
        $infoBatch = [];
        $count = 0;

        foreach ($systems as $system) {
            $count++;

            $systemPayload = [
                'id64' => $system->id64,
                'name' => $system->name,
                'coords_x' => $system->coords->x,
                'coords_y' => $system->coords->y,
                'coords_z' => $system->coords->z,
                'updated_at' => app(EdsmApiService::class)->formatSystemUpdateTime($system),
            ];

            if (property_isset($system, 'mainStar') && $system->mainStar !== '') {
                $systemPayload['main_star'] = $system->mainStar;
            }

            $systemBatch[] = $systemPayload;

            try {
                $infoPayload = [
                    'system_id' => $system->id64,
                    'allegiance' => property_isset($system, 'allegiance') ? $system->allegiance : null,
                    'economy' => property_isset($system, 'economy') ? $system->economy : null,
                    'government' => property_isset($system, 'government') ? $system->government : null,
                    'population' => property_isset($system, 'population') ? $system->population : 0,
                    'security' => property_isset($system, 'security') ? $system->security : 'None',
                ];

                if (property_isset($system, 'controllingFaction')) {
                    $faction = $system->controllingFaction;
                    $infoPayload['faction'] = property_isset($faction, 'name') ? $faction->name : null;
                    $infoPayload['faction_state'] = property_isset($faction, 'allegiance') ? $faction->allegiance : null;
                }

                $infoBatch[] = $infoPayload;
            } catch (Exception $e) {
                Log::channel($this->channel)
                    ->error('Failed to process information for '.$system->name.' record: '.$e->getMessage());
            }

            if (count($systemBatch) >= $this->batchSize) {
                $this->insertBatch($systemBatch, $infoBatch);
                Log::channel($this->channel)->info('Processed batch of '.count($systemBatch).' records.');

                $systemBatch = [];
                $infoBatch = [];
            }
        }

        // Insert any remaining records
        if (! empty($systemBatch)) {
            $this->insertBatch($systemBatch, $infoBatch);
            Log::channel($this->channel)->info('Processed final batch of '.count($systemBatch).' records.');
        }

        Log::channel($this->channel)->info('Completed processing of '.$count.' records from '.$this->file);
    }

    /**
     * Insert a batch of records and their information.
     */
    private function insertBatch(array $systemBatch, array $infoBatch): void
    {
        DB::transaction(function () use ($systemBatch, $infoBatch) {
            // Generate slugs for records that don't have one
            foreach ($systemBatch as &$system) {
                $system['slug'] ??= Str::slug($system['id64'].' '.$system['name']);
            }
            unset($system);

            // Bulk upsert systems — one query instead of N exists() + create() calls
            try {
                System::upsert(
                    $systemBatch,
                    ['id64'],
                    ['name', 'coords_x', 'coords_y', 'coords_z', 'updated_at'],
                );

                Log::channel($this->channel)->info('Upserted '.count($systemBatch).' system records.');
            } catch (Exception $e) {
                Log::channel($this->channel)->error('Failed to upsert systems batch: '.$e->getMessage());

                return;
            }

            // Bulk upsert system information
            if (empty($infoBatch)) {
                return;
            }

            try {
                // Map id64 -> system.id for the foreign key in one query
                $id64s = array_column($infoBatch, 'system_id');
                $idMap = System::whereIn('id64', $id64s)->pluck('id', 'id64');

                $now = now();
                $infoRecords = [];

                foreach ($infoBatch as $info) {
                    $systemId = $idMap[$info['system_id']] ?? null;

                    if ($systemId) {
                        $info['system_id'] = $systemId;
                        $info['created_at'] = $now;
                        $info['updated_at'] = $now;
                        $infoRecords[] = $info;
                    }
                }

                if (! empty($infoRecords)) {
                    SystemInformation::upsert(
                        $infoRecords,
                        ['system_id'],
                        ['allegiance', 'economy', 'government', 'population', 'security', 'faction', 'faction_state', 'updated_at'],
                    );

                    Log::channel($this->channel)->info('Upserted '.count($infoRecords).' information records.');
                }
            } catch (Exception $e) {
                Log::channel($this->channel)->error('Failed to upsert information batch: '.$e->getMessage());
            }
        });
    }
}
