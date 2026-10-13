<?php

namespace App\Services\Marketing;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Help articles: Markdown files in resources/help with a small header
 * (title, summary, order) between --- lines. Written by us, but rendered
 * with raw HTML stripped all the same.
 */
class HelpCenter
{
    /**
     * @return Collection<int, array{slug: string, title: string, summary: string, order: int}>
     */
    public function articles(): Collection
    {
        return collect(glob(resource_path('help/*.md')) ?: [])
            ->map(fn (string $path): array => $this->meta($path))
            ->sortBy([['order', 'asc'], ['title', 'asc']])
            ->values();
    }

    /**
     * @return array{slug: string, title: string, summary: string, order: int, html: string}|null
     */
    public function article(string $slug): ?array
    {
        if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }

        $path = resource_path("help/{$slug}.md");

        if (! is_file($path)) {
            return null;
        }

        [, $body] = $this->split((string) file_get_contents($path));

        return [...$this->meta($path), 'html' => Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false])];
    }

    /**
     * @return array{slug: string, title: string, summary: string, order: int}
     */
    protected function meta(string $path): array
    {
        [$header] = $this->split((string) file_get_contents($path));
        $slug = basename($path, '.md');

        return [
            'slug' => $slug,
            'title' => $header['title'] ?? Str::headline($slug),
            'summary' => $header['summary'] ?? '',
            'order' => (int) ($header['order'] ?? 100),
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    protected function split(string $contents): array
    {
        if (! preg_match('/^---\R(.*?)\R---\R(.*)$/s', $contents, $match)) {
            return [[], $contents];
        }

        $header = [];

        foreach (preg_split('/\R/', $match[1]) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $header[trim($key)] = trim($value);
            }
        }

        return [$header, $match[2]];
    }
}
