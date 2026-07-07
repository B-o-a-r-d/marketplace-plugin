<?php

namespace Board\Marketplace\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

trait TalksToGitHub
{
    /**
     * A GitHub HTTP client with conservative timeouts. Public repos work without
     * auth; an optional token raises the rate limit (services.github.token).
     */
    protected function github(): PendingRequest
    {
        $request = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'BoardBot/1.0 (+marketplace)',
        ])->connectTimeout(5)->timeout(30);

        $token = config('services.github.token');

        return $token ? $request->withToken($token) : $request;
    }
}
