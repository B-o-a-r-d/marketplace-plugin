<?php

namespace Board\Marketplace\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Total download counts from packagist.org for catalog entries that carry a
 * composer package name. Cached for a few hours; failures cache a sentinel so
 * an unreachable Packagist never hammers the API on every page render.
 */
class PackagistStats
{
    private const TTL_HOURS = 6;

    private const UNKNOWN = -1;

    public function downloads(string $package): ?int
    {
        $total = Cache::remember(
            'marketplace:packagist:'.$package,
            now()->addHours(self::TTL_HOURS),
            function () use ($package): int {
                try {
                    $response = Http::timeout(5)->connectTimeout(3)
                        ->get("https://packagist.org/packages/{$package}.json");

                    return $response->successful()
                        ? (int) $response->json('package.downloads.total', self::UNKNOWN)
                        : self::UNKNOWN;
                } catch (\Throwable) {
                    return self::UNKNOWN;
                }
            },
        );

        return $total === self::UNKNOWN ? null : $total;
    }
}
