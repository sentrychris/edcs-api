<?php

namespace App\Services\Frontier;

use App\Models\System;
use App\Models\User;
use App\Services\Edsm\EdsmSystemService;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Frontier CAPI client.
 */
class FrontierCApiService
{
    protected Client $client;

    public function __construct(protected FrontierAuthService $frontierAuthService)
    {
        $this->client = new Client([
            'headers' => [
                'User-Agent' => 'EDCS-v1.0.0',
            ],
            'base_uri' => config('elite.frontier.capi.url'),
        ]);
    }

    /**
     * Get the commander's profile information.
     *
     * @return object|null
     */
    public function getCommanderProfile(User $user): mixed
    {
        try {
            $response = $this->client->request('GET', '/profile', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getFrontierToken($user),
                    'Content-Type' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody()->getContents());
        } catch (Exception $e) {
            Log::error($e->getMessage());

            return null;
        }
    }

    /**
     * Confirm the user's commander profile.
     */
    public function confirmCommander(User $user): mixed
    {
        $profile = $this->getCommanderProfile($user);
        if (! property_isset($profile, 'commander')) {
            throw new Exception('Commander profile not found.');
        }

        $commander = $profile->commander;
        $user->commander()->updateOrCreate([
            'cmdr_name' => $commander->name,
        ], [
            'cmdr_name' => $commander->name,
            'credits' => $commander->credits,
            'debt' => $commander->debt,
            'alive' => $commander->alive,
            'docked' => $commander->docked,
            'onfoot' => $commander->onfoot,
            'rank' => json_encode($commander->rank),
        ]);

        if (property_isset($profile, 'lastSystem')) {
            $lastSystem = $profile->lastSystem;
            $system = System::whereId64($lastSystem->id)
                ->whereName($lastSystem->name)
                ->first();

            if (! $system) {
                $system = app(EdsmSystemService::class)->updateSystem($lastSystem->name);
            }

            $user->commander()->update([
                'last_system_id64' => $system->id64,
            ]);
        }

        return $profile;
    }

    /**
     * Get the user's journal logs from CAPI.
     */
    public function getJournal(User $user, mixed $year = '', mixed $month = '', mixed $day = ''): mixed
    {
        try {
            $uri = '/journal'
                .($year ? "/{$year}" : '')
                .($month ? "/{$month}" : '')
                .($day ? "/{$day}" : '');

            $response = $this->client->request('GET', $uri, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getFrontierToken($user),
                    'Content-Type' => 'application/json',
                ],
            ]);

            $content = $response->getBody()->getContents();
            $jsonObjects = preg_split('/\r\n|\r|\n/', $content);

            $decoded = [];
            foreach ($jsonObjects as $json) {
                if (! empty(trim($json))) {
                    $decoded[] = json_decode($json, true);
                }
            }

            return $decoded;
        } catch (Exception $e) {
            Log::error($e->getMessage());

            return [];
        }
    }

    /**
     * Get the user's active community goals.
     */
    public function getCommunityGoals(User $user): mixed
    {
        try {
            $response = $this->client->request('GET', '/communitygoals', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->getFrontierToken($user),
                    'Content-Type' => 'application/json',
                ],
            ]);

            $content = $response->getBody()->getContents();

            return $content ? json_decode($content) : [];
        } catch (Exception $e) {
            Log::error($e->getMessage());

            return [];
        }
    }

    /**
     * Resolve the Frontier access token for the given user.
     *
     * Checks Redis first. On a cache miss, falls back to the DB record.
     * If the stored token is expired (or missing), exchanges the refresh
     * token for a new access token before returning.
     */
    private function getFrontierToken(User $user): string
    {
        $cached = Redis::get("user_{$user->id}_frontier_token");
        if ($cached) {
            return $cached;
        }

        $frontierUser = $user->frontierUser;

        if ($frontierUser && $frontierUser->access_token && ! $frontierUser->isTokenExpired()) {
            $ttl = max(now()->diffInSeconds($frontierUser->token_expires_at) - 300, 60);
            Redis::set("user_{$user->id}_frontier_token", $frontierUser->access_token, 'EX', $ttl);

            return $frontierUser->access_token;
        }

        return $this->frontierAuthService->refreshToken($user);
    }
}
