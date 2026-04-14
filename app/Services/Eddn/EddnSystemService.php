<?php

namespace App\Services\Eddn;

use App\Facades\DiscordAlert;
use App\Models\System;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class EddnSystemService extends EddnService
{
    /**
     * Import systems through EDDN.
     *
     * @return void
     */
    public function process(array $batch)
    {
        $this->updateSystems($batch);
    }

    /**
     * Cache system names with their ID64s.
     *
     * @return void
     */
    public function updateSystems(array $batch)
    {
        // Filter to valid messages first
        $validMessages = [];

        foreach ($batch['messages'] as $receivedMessage) {
            if (! $this->isSoftwareAllowed($receivedMessage['header'])) {
                continue;
            }

            if (! $this->validateSchemaRef($receivedMessage['$schemaRef'])) {
                continue;
            }

            $message = $receivedMessage['message'];

            if (isset($message['StarSystem'])
                && isset($message['SystemAddress'])
                && isset($message['StarPos']) && count($message['StarPos']) === 3
            ) {
                $validMessages[] = $message;
            }
        }

        if (empty($validMessages)) {
            return;
        }

        // Pre-fetch all existing systems in one query instead of N individual queries
        $id64s = array_column($validMessages, 'SystemAddress');
        $existingSystems = System::whereIn('id64', $id64s)->get()->keyBy('id64');

        foreach ($validMessages as $message) {
            $starSystem = $message['StarSystem'];
            $starSystemId64 = $message['SystemAddress'];
            $existingSystem = $existingSystems[$starSystemId64] ?? null;

            if (! $existingSystem) {
                try {
                    $system = System::create([
                        'id64' => $starSystemId64,
                        'name' => $starSystem,
                        'coords_x' => $message['StarPos'][0],
                        'coords_y' => $message['StarPos'][1],
                        'coords_z' => $message['StarPos'][2],
                        'updated_at' => now(),
                    ]);

                    if (! $system && ! in_array($starSystemId64, Redis::smembers('eddn_systems_not_inserted'))) {
                        Redis::sadd('eddn_systems_not_inserted', $starSystemId64);
                    } else {
                        $this->updateSystemInformation($system, $message);
                    }
                } catch (Exception $e) {
                    if (! in_array($starSystem, config('imports.errors.systems.exclusions'))) {
                        $errorMessage = "Failed to insert SYSTEM {$starSystem} ({$starSystemId64})";
                        Log::channel('eddn')->error($errorMessage, ['error' => $e->getMessage()]);
                        DiscordAlert::eddn(self::class, $errorMessage.': '.$e->getMessage(), false);
                    }

                    continue;
                }
            } else {
                $system = $existingSystem;
                $this->updateSystemInformation($system, $message);
            }

            Cache::set('latest_system', $system);
        }
    }

    /**
     * Update sytem information data.
     *
     * @return void
     */
    public function updateSystemInformation(System $system, array $message)
    {
        if (isset($message['Population'])
            && isset($message['SystemAllegiance'])
            && isset($message['SystemEconomy'])
            && isset($message['SystemFaction'])
            && isset($message['SystemFaction']['Name'])
            && isset($message['SystemFaction']['FactionState'])
            && isset($message['SystemGovernment'])
            && isset($message['SystemSecurity'])
        ) {
            try {
                $record = [
                    'population' => $this->sanitizeMessageAttribute($message['Population']),
                    'allegiance' => $this->sanitizeMessageAttribute($message['SystemAllegiance']),
                    'economy' => $this->sanitizeMessageAttribute($message['SystemEconomy']),
                    'faction' => $this->sanitizeMessageAttribute($message['SystemFaction']['Name']),
                    'faction_state' => $this->sanitizeMessageAttribute($message['SystemFaction']['FactionState']),
                    'government' => $this->sanitizeMessageAttribute($message['SystemGovernment']),
                    'security' => $this->sanitizeMessageAttribute($message['SystemSecurity']),
                ];

                $information = $system->information()
                    ->updateOrCreate(['system_id' => $system->id], $record);

                if (! $information && ! in_array($system->id64, Redis::smembers('eddn_system_information_not_inserted'))) {
                    Redis::sadd('eddn_system_information_not_inserted', $system->id64);
                }
            } catch (Exception $e) {
                $message = "Failed to insert INFORMATION for {$system->name} ({$system->id64})";
                Log::channel('eddn')->error($message, ['error' => $e->getMessage()]);
                DiscordAlert::eddn(self::class, $message.': '.$e->getMessage(), false);
            }
        }
    }

    /**
     * Format message attributes.
     *
     * @return string
     */
    private function sanitizeMessageAttribute(string $attribute)
    {
        $value = '';

        if (str_contains($attribute, '$')) {
            if (str_starts_with($attribute, '$SYSTEM_SECURITY_')) {
                $value = str_replace(';', '', trim(str_replace('$SYSTEM_SECURITY_', '', $attribute)));
            } elseif (str_starts_with($attribute, '$GAlAXY_MAP_INFO_state_')) {
                $value = str_replace(';', '', trim(str_replace('$GAlAXY_MAP_INFO_state_', '', $attribute)));
            } else {
                $parts = explode('_', $attribute);
                $value = count($parts) === 2
                    ? str_replace(';', '', $parts[1])
                    : str_replace(';', '', $attribute);
            }
        } else {
            $value = str_replace(';', '', $attribute);
        }

        if (ctype_digit($value)) {
            return (int) $value;
        } else {
            return ucfirst(camel_to_spaces(trim($value)));
        }
    }
}
