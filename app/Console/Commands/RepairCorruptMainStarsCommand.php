<?php

namespace App\Console\Commands;

use App\Models\System;
use App\Models\SystemBody;
use App\Services\EdsmApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RepairCorruptMainStarsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'systems:repair-corrupt-main-stars
        {--dry-run : List affected systems without deleting or re-ingesting}
        {--sleep=1 : Seconds to pause between EDSM re-ingests}';

    /**
     * @var string
     */
    protected $description = 'Re-ingest bodies for systems where the main star was stored with a random body_id due to EDSM omitting id64 during the original import';

    private const RELATION_KEYS = ['withBodies', 'withFleetCarriers', 'withInformation', 'withStations'];

    public function __construct(private readonly EdsmApiService $edsm)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sleep = (int) $this->option('sleep');

        $corrupt = $this->findCorruptMainStars();

        if ($corrupt->isEmpty()) {
            $this->info('No corrupt main stars found.');

            return self::SUCCESS;
        }

        $this->info("Found {$corrupt->count()} corrupt main star(s):");
        foreach ($corrupt as $row) {
            $this->line(sprintf('  - system_id=%d slug=%s body_id=%d', $row->system_id, $row->slug, $row->body_id));
        }

        if ($dryRun) {
            $this->warn('Dry run — no changes made.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Delete and re-ingest bodies for these systems?', false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        foreach ($corrupt as $row) {
            $this->repairSystem($row);

            if ($sleep > 0 && $row !== $corrupt->last()) {
                sleep($sleep);
            }
        }

        $this->info('Repair complete.');

        return self::SUCCESS;
    }

    /**
     * A row is corrupt when the main star's id64 matches its body_id AND that
     * value sits in the 9-digit random_int range used by the old fallback.
     * Legitimate binary-system main stars (e.g. Dironii, body_id=2) are
     * excluded because their id64 is the real EDSM 18-digit id.
     *
     * @return Collection<int, object{id: int, system_id: int, slug: string, body_id: int}>
     */
    private function findCorruptMainStars(): Collection
    {
        return SystemBody::query()
            ->join('systems', 'systems.id', '=', 'systems_bodies.system_id')
            ->where('systems_bodies.is_main_star', 1)
            ->whereColumn('systems_bodies.id64', 'systems_bodies.body_id')
            ->whereBetween('systems_bodies.body_id', [100_000_000, 999_999_999])
            ->get([
                'systems_bodies.id',
                'systems_bodies.system_id',
                'systems.slug',
                'systems_bodies.body_id',
            ]);
    }

    private function repairSystem(object $row): void
    {
        $this->line("Repairing {$row->slug}...");

        DB::transaction(function () use ($row) {
            SystemBody::where('system_id', $row->system_id)->delete();
        });

        $system = System::find($row->system_id);
        if (! $system) {
            $this->error("System {$row->system_id} vanished before re-ingest.");

            return;
        }

        $this->edsm->updateSystemBodies($system);

        $this->forgetSystemCache($row->slug);

        $mainStarBodyId = SystemBody::where('system_id', $row->system_id)
            ->where('is_main_star', 1)
            ->value('body_id');

        if ($mainStarBodyId === 0) {
            $this->info("  OK {$row->slug} — main star body_id=0");
        } else {
            $this->warn("  WARN {$row->slug} — main star body_id={$mainStarBodyId} (EDSM may still be missing data)");
        }
    }

    /**
     * Forget every combination of relation query keys used by the system show
     * endpoint cache. Keys are sorted alphabetically to match the controller.
     */
    private function forgetSystemCache(string $slug): void
    {
        $combinations = $this->powerSet(self::RELATION_KEYS);

        foreach ($combinations as $combo) {
            sort($combo);
            $suffix = $combo ? '_'.implode('_', $combo) : '';
            Cache::forget("system_detail_{$slug}{$suffix}");
        }
    }

    /**
     * @param  list<string>  $items
     * @return list<list<string>>
     */
    private function powerSet(array $items): array
    {
        $result = [[]];
        foreach ($items as $item) {
            foreach ($result as $subset) {
                $result[] = [...$subset, $item];
            }
        }

        return $result;
    }
}
