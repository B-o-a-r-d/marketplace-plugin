<?php

namespace Board\Marketplace;

use Board\Marketplace\Concerns\TalksToGitHub;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the curated marketplace catalog from the `B-o-a-r-d/Marketplace` repo —
 * one markdown file per plugin under `plugins/`, with YAML front-matter. The
 * catalog is community-contributed by fork + PR and merged after review; the app
 * only ever *reads* it (cached), never bakes it into the image.
 */
class MarketplaceClient
{
    use TalksToGitHub;

    private const CACHE_KEY = 'marketplace:catalog';

    private const TTL_MINUTES = 60;

    private function repo(): string
    {
        return (string) config('board-marketplace.catalog_repo', 'B-o-a-r-d/Marketplace');
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function catalog(bool $fresh = false): Collection
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return collect(Cache::remember(self::CACHE_KEY, now()->addMinutes(self::TTL_MINUTES), fn (): array => $this->fetch()));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entry(string $key): ?array
    {
        return $this->catalog()->firstWhere('key', $key);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch(): array
    {
        $index = $this->github()->get('https://api.github.com/repos/'.$this->repo().'/contents/plugins');

        if (! $index->successful() || ! is_array($index->json())) {
            return [];
        }

        $entries = [];

        foreach ($index->json() as $file) {
            if (($file['type'] ?? '') !== 'file' || ! str_ends_with((string) ($file['name'] ?? ''), '.md')) {
                continue;
            }

            $raw = $this->github()->get((string) $file['download_url']);

            if ($raw->successful() && ($entry = $this->parse($raw->body())) !== null) {
                $entries[] = $entry;
            }
        }

        return collect($entries)->sortBy('name')->values()->all();
    }

    /**
     * Parse a plugin markdown file: YAML front-matter + a description body.
     *
     * @return array<string, mixed>|null
     */
    private function parse(string $content): ?array
    {
        if (! preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $content, $m)) {
            return null;
        }

        try {
            $meta = Yaml::parse($m[1]) ?? [];
        } catch (\Throwable) {
            return null;
        }

        if (empty($meta['key']) || empty($meta['repo'])) {
            return null;
        }

        return [
            'key' => (string) $meta['key'],
            'name' => (string) ($meta['name'] ?? $meta['key']),
            'repo' => (string) $meta['repo'],
            'description' => (string) ($meta['description'] ?? ''),
            'author' => (string) ($meta['author'] ?? ''),
            'homepage' => (string) ($meta['homepage'] ?? ''),
            'icon' => (string) ($meta['icon'] ?? 'puzzle-piece'),
            'capabilities' => array_map('strval', (array) ($meta['capabilities'] ?? [])),
            'category' => (string) ($meta['category'] ?? 'other'),
            'readme' => trim($m[2]),
        ];
    }
}
