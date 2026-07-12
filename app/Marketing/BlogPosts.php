<?php

namespace App\Marketing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The blog's content store: markdown files in resources/content/blog with a
 * minimal front-matter block. File-based on purpose — posts ship through git
 * review like code, and every factual claim traces to the sourced
 * bureaucracy catalogue they're derived from.
 *
 * Filename convention: YYYY-MM-DD-slug.md → slug, with the date as the
 * published date. Front matter supports scalar `key: value` lines only.
 */
class BlogPosts
{
    /**
     * @return array<int, array{slug: string, title: string, description: string, published_at: Carbon, updated_at: Carbon, body_html: string}>
     */
    public function all(): array
    {
        // Cache SCALARS only (dates as ISO strings) — phpredis' serializer
        // unserializes inside the extension where the autoloader can't run,
        // so a cached Carbon comes back as an incomplete object and 500s the
        // page. Hydrate after retrieval instead.
        $posts = Cache::remember('marketing.blog.index', now()->addMinutes(30), function (): array {
            return collect(File::files(resource_path('content/blog')))
                ->filter(fn ($file): bool => $file->getExtension() === 'md')
                ->map(fn ($file): ?array => $this->parse($file->getPathname()))
                ->filter()
                ->sortByDesc(fn (array $post) => $post['published_at'])
                ->values()
                ->all();
        });

        return array_map(fn (array $post): array => [
            ...$post,
            'published_at' => Carbon::parse($post['published_at']),
            'updated_at' => Carbon::parse($post['updated_at']),
        ], $posts);
    }

    /**
     * @return array{slug: string, title: string, description: string, published_at: Carbon, updated_at: Carbon, body_html: string}|null
     */
    public function find(string $slug): ?array
    {
        return collect($this->all())->firstWhere('slug', $slug);
    }

    /**
     * @return array{slug: string, title: string, description: string, published_at: string, updated_at: string, body_html: string}|null
     */
    private function parse(string $path): ?array
    {
        $raw = File::get($path);
        $filename = basename($path, '.md');

        if (! preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $filename, $nameParts)) {
            return null;
        }

        // Front matter: a leading "---\nkey: value\n---" block.
        $meta = [];
        $body = $raw;
        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $raw, $sections)) {
            foreach (explode("\n", $sections[1]) as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $meta[trim($key)] = trim($value);
                }
            }
            $body = $sections[2];
        }

        if (! isset($meta['title'], $meta['description'])) {
            return null;
        }

        return [
            'slug' => $nameParts[2],
            'title' => $meta['title'],
            'description' => $meta['description'],
            // Dates stay ISO strings here so the cached payload is scalar-only;
            // all() hydrates them into Carbon after cache retrieval.
            'published_at' => $nameParts[1],
            'updated_at' => $meta['updated'] ?? $nameParts[1],
            'body_html' => Str::markdown($body, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]),
        ];
    }
}
