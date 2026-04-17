<?php

namespace App\Services;

use App\Models\System;

/**
 * Bakes the galaxy-map tile set used by the frontend WebGL renderer.
 *
 * The systems table is way too large to ship to the browser as a single payload
 * (~95M rows), so we pre-bake a multi-LOD tile pyramid:
 *
 *   LOD 2 — one binary file per native 1280-ly sector, full id64 list.
 *   LOD 1 — one binary file per 5120-ly super-sector, sampled to ~10k systems.
 *   LOD 0 — single global file, sampled to ~50k systems.
 *
 * Each tile is `[uint32 count][uint64 id64 × count]` little-endian; the client
 * derives coords from the id64 boxel encoding it already knows.
 *
 * Output is written to `public/galaxy-tiles/v{N}/...` with a top-level
 * `manifest.json`. Tiles are served through Laravel (not directly by Nginx)
 * at `/api/galaxy/tiles/...` so they inherit the CORS middleware.
 */
class GalaxyTileBakerService
{
    /**
     * Sgr A* offset — the frontend treats Sgr A* as the galactic origin (0,0,0)
     * in render space, so sector indices are computed from `coords + BASE`.
     */
    private const BASE_X = 50240;

    private const BASE_Y = 41280;

    private const BASE_Z = 50240;

    private const SECTOR_SIZE = 1280;

    private const LOD1_SIZE = 5120;

    private const LOD0_TARGET = 50000;

    private const LOD1_TARGET_PER_TILE = 10000;

    /**
     * Bake a fresh tile set under a new version directory.
     *
     * @param  string  $outputRoot  - absolute path to public/galaxy-tiles
     * @param  callable|null  $progress  - optional fn(string $stage, int $current, int $total)
     * @return array{version: int, lod0_count: int, lod1_tiles: int, lod2_tiles: int}
     */
    public function bake(string $outputRoot, ?callable $progress = null): array
    {
        $version = $this->nextVersion($outputRoot);
        $versionRoot = $outputRoot.'/v'.$version;

        $this->ensureDir($versionRoot.'/lod2');
        $this->ensureDir($versionRoot.'/lod1');

        $lod2Tiles = $this->bakeLod2($versionRoot, $progress);
        $lod1Tiles = $this->bakeLod1($versionRoot, $lod2Tiles, $progress);
        $lod0Count = $this->bakeLod0($versionRoot, $lod2Tiles, $progress);

        $this->writeManifest($outputRoot, $versionRoot, $version, $lod0Count, $lod1Tiles, $lod2Tiles);

        return [
            'version' => $version,
            'lod0_count' => $lod0Count,
            'lod1_tiles' => count($lod1Tiles),
            'lod2_tiles' => count($lod2Tiles),
        ];
    }

    /**
     * Stream every system, group by native sector, and write one tile per sector.
     *
     * Returns an array of [sectorKey => count] for all sectors that received
     * at least one system; used downstream to drive LOD 1 sampling.
     *
     * @return array<string, int>
     */
    private function bakeLod2(string $versionRoot, ?callable $progress): array
    {
        $tiles = [];
        $handles = [];
        $counts = [];

        $cursor = System::select(['id64', 'coords_x', 'coords_y', 'coords_z'])
            ->orderBy('id')
            ->cursor();

        $i = 0;
        foreach ($cursor as $system) {
            $sx = (int) floor((((float) $system->coords_x) + self::BASE_X) / self::SECTOR_SIZE);
            $sy = (int) floor((((float) $system->coords_y) + self::BASE_Y) / self::SECTOR_SIZE);
            $sz = (int) floor((((float) $system->coords_z) + self::BASE_Z) / self::SECTOR_SIZE);

            $key = $sx.'_'.$sy.'_'.$sz;

            if (! isset($handles[$key])) {
                $handles[$key] = fopen($versionRoot.'/lod2/'.$key.'.bin.tmp', 'wb');
                fwrite($handles[$key], pack('V', 0)); // count placeholder
                $counts[$key] = 0;
            }

            fwrite($handles[$key], $this->packUint64((int) $system->id64));
            $counts[$key]++;

            if ((++$i % 50000) === 0 && $progress) {
                $progress('lod2', $i, 0);
            }
        }

        foreach ($handles as $key => $fh) {
            // backfill count header
            fseek($fh, 0);
            fwrite($fh, pack('V', $counts[$key]));
            fclose($fh);
            rename(
                $versionRoot.'/lod2/'.$key.'.bin.tmp',
                $versionRoot.'/lod2/'.$key.'.bin'
            );
            $tiles[$key] = $counts[$key];
        }

        if ($progress) {
            $progress('lod2', $i, $i);
        }

        return $tiles;
    }

    /**
     * Build LOD 1 super-tiles by reading back the LOD 2 tiles in groups of 4×4×4
     * native sectors and sampling each group down to LOD1_TARGET_PER_TILE.
     *
     * @param  array<string, int>  $lod2Tiles
     * @return array<string, int>
     */
    private function bakeLod1(string $versionRoot, array $lod2Tiles, ?callable $progress): array
    {
        $groups = [];
        foreach ($lod2Tiles as $key => $count) {
            [$sx, $sy, $sz] = array_map('intval', explode('_', $key));
            $gx = (int) floor($sx * self::SECTOR_SIZE / self::LOD1_SIZE);
            $gy = (int) floor($sy * self::SECTOR_SIZE / self::LOD1_SIZE);
            $gz = (int) floor($sz * self::SECTOR_SIZE / self::LOD1_SIZE);
            $groupKey = $gx.'_'.$gy.'_'.$gz;
            $groups[$groupKey][] = $key;
        }

        $tiles = [];
        $i = 0;
        foreach ($groups as $groupKey => $sectorKeys) {
            $totalInGroup = 0;
            foreach ($sectorKeys as $sk) {
                $totalInGroup += $lod2Tiles[$sk];
            }

            $stride = max(1, (int) ceil($totalInGroup / self::LOD1_TARGET_PER_TILE));

            $out = fopen($versionRoot.'/lod1/'.$groupKey.'.bin.tmp', 'wb');
            fwrite($out, pack('V', 0));
            $written = 0;
            $cursor = 0;

            foreach ($sectorKeys as $sk) {
                $in = fopen($versionRoot.'/lod2/'.$sk.'.bin', 'rb');
                fread($in, 4); // skip count header
                while (! feof($in)) {
                    $buf = fread($in, 8);
                    if ($buf === false || strlen($buf) < 8) {
                        break;
                    }
                    if ($cursor % $stride === 0) {
                        fwrite($out, $buf);
                        $written++;
                    }
                    $cursor++;
                }
                fclose($in);
            }

            fseek($out, 0);
            fwrite($out, pack('V', $written));
            fclose($out);
            rename(
                $versionRoot.'/lod1/'.$groupKey.'.bin.tmp',
                $versionRoot.'/lod1/'.$groupKey.'.bin'
            );
            $tiles[$groupKey] = $written;

            if ((++$i % 50) === 0 && $progress) {
                $progress('lod1', $i, count($groups));
            }
        }

        if ($progress) {
            $progress('lod1', $i, count($groups));
        }

        return $tiles;
    }

    /**
     * Build the single global LOD 0 tile by sampling across the whole catalogue
     * down to LOD0_TARGET. Reads from LOD 2 tiles to avoid a second DB pass.
     *
     * @param  array<string, int>  $lod2Tiles
     */
    private function bakeLod0(string $versionRoot, array $lod2Tiles, ?callable $progress): int
    {
        $total = array_sum($lod2Tiles);
        $stride = max(1, (int) ceil($total / self::LOD0_TARGET));

        $out = fopen($versionRoot.'/lod0.bin.tmp', 'wb');
        fwrite($out, pack('V', 0));
        $written = 0;
        $cursor = 0;

        foreach ($lod2Tiles as $key => $_) {
            $in = fopen($versionRoot.'/lod2/'.$key.'.bin', 'rb');
            fread($in, 4);
            while (! feof($in)) {
                $buf = fread($in, 8);
                if ($buf === false || strlen($buf) < 8) {
                    break;
                }
                if ($cursor % $stride === 0) {
                    fwrite($out, $buf);
                    $written++;
                }
                $cursor++;
            }
            fclose($in);
        }

        fseek($out, 0);
        fwrite($out, pack('V', $written));
        fclose($out);
        rename($versionRoot.'/lod0.bin.tmp', $versionRoot.'/lod0.bin');

        if ($progress) {
            $progress('lod0', 1, 1);
        }

        return $written;
    }

    /**
     * Write the manifest atomically (write-then-rename) so a half-written file
     * is never observed by readers.
     *
     * @param  array<string, int>  $lod1Tiles
     * @param  array<string, int>  $lod2Tiles
     */
    private function writeManifest(
        string $outputRoot,
        string $versionRoot,
        int $version,
        int $lod0Count,
        array $lod1Tiles,
        array $lod2Tiles,
    ): void {
        $base = '/api/galaxy/tiles/v'.$version;

        $manifest = [
            'version' => $version,
            'generated_at' => now()->toIso8601String(),
            'sector_size' => self::SECTOR_SIZE,
            'lod1_size' => self::LOD1_SIZE,
            'lod0' => [
                'url' => $base.'/lod0.bin',
                'count' => $lod0Count,
            ],
            'lod1_url_template' => $base.'/lod1/{key}.bin',
            'lod2_url_template' => $base.'/lod2/{key}.bin',
            'lod1_tiles' => array_keys($lod1Tiles),
            'lod2_tiles' => array_keys($lod2Tiles),
        ];

        $tmp = $outputRoot.'/manifest.json.tmp';
        file_put_contents($tmp, json_encode($manifest, JSON_UNESCAPED_SLASHES));
        rename($tmp, $outputRoot.'/manifest.json');
    }

    private function nextVersion(string $outputRoot): int
    {
        $this->ensureDir($outputRoot);
        $existing = glob($outputRoot.'/v*', GLOB_ONLYDIR) ?: [];
        $max = 0;
        foreach ($existing as $dir) {
            $n = (int) substr(basename($dir), 1);
            if ($n > $max) {
                $max = $n;
            }
        }

        return $max + 1;
    }

    private function ensureDir(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Pack an unsigned 64-bit integer little-endian. We use bitshifts rather
     * than `pack('P', ...)` because PHP's `P` format is signed-aware and id64
     * values can occupy the full 64 bits.
     */
    private function packUint64(int $v): string
    {
        return pack('VV', $v & 0xFFFFFFFF, ($v >> 32) & 0xFFFFFFFF);
    }
}
