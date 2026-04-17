<?php

namespace App\Console\Commands;

use App\Services\GalaxyTileBakerService;
use Illuminate\Console\Command;

class BakeGalaxyTilesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'galaxy:bake-tiles
        {--output= : Output root (defaults to public/galaxy-tiles)}
        {--prune : Delete previous version directories after a successful bake}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bake the galaxy-map tile pyramid (LOD 0/1/2) into public/galaxy-tiles';

    public function __construct(private readonly GalaxyTileBakerService $baker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $output = $this->option('output') ?: public_path('galaxy-tiles');

        $this->info("Baking galaxy tiles into {$output}");

        $progress = function (string $stage, int $current, int $total): void {
            if ($total > 0) {
                $this->line(sprintf('  [%s] %d / %d', $stage, $current, $total));
            } else {
                $this->line(sprintf('  [%s] %d processed', $stage, $current));
            }
        };

        $result = $this->baker->bake($output, $progress);

        $this->info(sprintf(
            'Done. v%d — LOD0: %d systems · LOD1: %d tiles · LOD2: %d tiles',
            $result['version'],
            $result['lod0_count'],
            $result['lod1_tiles'],
            $result['lod2_tiles'],
        ));

        if ($this->option('prune')) {
            $this->prunePreviousVersions($output, $result['version']);
        }

        return self::SUCCESS;
    }

    private function prunePreviousVersions(string $outputRoot, int $keepVersion): void
    {
        $dirs = glob($outputRoot.'/v*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            if ((int) substr(basename($dir), 1) === $keepVersion) {
                continue;
            }
            $this->line("  Pruning {$dir}");
            $this->rrmdir($dir);
        }
    }

    private function rrmdir(string $path): void
    {
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
