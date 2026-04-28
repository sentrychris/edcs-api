<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BuildDataDumpCommand extends Command
{
    protected $signature = 'dumps:build
        {--type= : Dump type to build: systems, populated-systems, systems-recent, bodies, bodies-recent, stations, stations-recent, carriers, carriers-recent, all}';

    protected $description = 'Build compressed JSON dump files for the data download endpoints.';

    /** @var array<int, string> */
    public const TYPES = [
        'systems',
        'populated-systems',
        'systems-recent',
        'bodies',
        'bodies-recent',
        'stations',
        'stations-recent',
        'carriers',
        'carriers-recent',
    ];

    public function handle(): int
    {
        $type = $this->option('type');

        if (! $type) {
            $this->error('The --type option is required.');

            return self::FAILURE;
        }

        if ($type === 'all') {
            foreach (self::TYPES as $t) {
                if ($this->buildDump($t) === self::FAILURE) {
                    return self::FAILURE;
                }
            }

            return self::SUCCESS;
        }

        if (! in_array($type, self::TYPES, true)) {
            $this->error("Unknown type: {$type}. Valid types: ".implode(', ', self::TYPES).', all');

            return self::FAILURE;
        }

        return $this->buildDump($type);
    }

    private function buildDump(string $type): int
    {
        $path = static::dumpPath($type);
        $tmp = $path.'.tmp';

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $this->line("Building {$type}...");

        $gz = gzopen($tmp, 'wb9');

        if ($gz === false) {
            $this->error("Failed to open {$tmp} for writing.");

            return self::FAILURE;
        }

        gzwrite($gz, "[\n");

        $first = true;
        $count = 0;

        foreach ($this->buildQuery($type)->cursor() as $record) {
            if (! $first) {
                gzwrite($gz, ",\n");
            }
            gzwrite($gz, json_encode($record));
            $first = false;
            $count++;
        }

        gzwrite($gz, "\n]");
        gzclose($gz);

        rename($tmp, $path);

        $this->info("Done — {$type}: {$count} records.");

        return self::SUCCESS;
    }

    private function buildQuery(string $type): Builder
    {
        $sevenDaysAgo = Carbon::now()->subDays(7);

        $systemsSelect = [
            'systems.id',
            'systems.id64',
            'systems.name',
            'systems.coords_x',
            'systems.coords_y',
            'systems.coords_z',
            'systems.body_count',
            'systems.slug',
            'systems.updated_at',
            'systems_information.allegiance',
            'systems_information.government',
            'systems_information.faction',
            'systems_information.faction_state',
            'systems_information.population',
            'systems_information.security',
            'systems_information.economy',
        ];

        return match ($type) {
            'systems' => DB::table('systems')
                ->leftJoin('systems_information', 'systems.id', '=', 'systems_information.system_id')
                ->select($systemsSelect),

            'populated-systems' => DB::table('systems')
                ->join('systems_information', 'systems.id', '=', 'systems_information.system_id')
                ->where('systems_information.population', '>', 0)
                ->select($systemsSelect),

            'systems-recent' => DB::table('systems')
                ->leftJoin('systems_information', 'systems.id', '=', 'systems_information.system_id')
                ->where('systems.updated_at', '>=', $sevenDaysAgo)
                ->select($systemsSelect),

            'bodies' => DB::table('systems_bodies'),

            'bodies-recent' => DB::table('systems_bodies')
                ->join('systems', 'systems_bodies.system_id', '=', 'systems.id')
                ->where('systems.updated_at', '>=', $sevenDaysAgo)
                ->select('systems_bodies.*'),

            'stations' => DB::table('systems_stations'),

            'stations-recent' => DB::table('systems_stations')
                ->where('information_last_updated', '>=', $sevenDaysAgo),

            'carriers' => DB::table('fleet_carriers'),

            'carriers-recent' => DB::table('fleet_carriers')
                ->where('information_last_updated', '>=', $sevenDaysAgo),
        };
    }

    public static function dumpPath(string $type): string
    {
        return storage_path("dumps/{$type}.json.gz");
    }
}
