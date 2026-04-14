<?php

namespace App\Services;

use App\Models\Commander;
use App\Models\System;
use App\Models\SystemBody;
use Illuminate\Support\Facades\Cache;

class StatService
{
    public function fetch(string $key, array $options)
    {
        if (array_key_exists('flushCache', $options) && $options['flushCache']) {
            Cache::forget($key);
        }

        $ttl = array_key_exists('ttl', $options)
            ? (int) $options['ttl']
            : 3600;

        return Cache::remember($key, $ttl, function () {
            $bodyCounts = SystemBody::toBase()
                ->selectRaw('count(*) as bodies')
                ->selectRaw("count(case when type = 'Star' then 1 end) as stars")
                ->selectRaw("count(case when type = 'Planet' then 1 end) as orbiting")
                ->first();

            return [
                'cartographical' => [
                    'systems' => System::count(),
                    'bodies' => (int) $bodyCounts->bodies,
                    'stars' => (int) $bodyCounts->stars,
                    'orbiting' => (int) $bodyCounts->orbiting,
                ],
                'commanders' => Commander::count(),
            ];
        });
    }
}
