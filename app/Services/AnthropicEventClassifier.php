<?php

namespace App\Services;

use App\Enums\EventCategory;
use App\Models\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One cheap tool-forced call per event at ingest: translate, classify,
 * summarize in our own words, pick trust chips conservatively, score
 * newcomer relevance. Same HTTP pattern as the composer's parser.
 */
class AnthropicEventClassifier implements ClassifiesEvents
{
    private const CHIPS = ['English-friendly', 'newcomers welcome', 'solo-OK', 'free'];

    public function classify(Event $event): array
    {
        $response = Http::baseUrl('https://api.anthropic.com')
            ->timeout(20)
            ->connectTimeout(3)
            ->withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->post('/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 700,
                'tool_choice' => ['type' => 'tool', 'name' => 'classify_event'],
                'tools' => [[
                    'name' => 'classify_event',
                    'description' => 'Store the classification of one local event.',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'relevance' => [
                                'type' => 'number',
                                'description' => '0-1: could a newcomer to Cologne show up alone, not speaking German, and leave with a contact? 0.8+ clearly yes, below 0.5 no.',
                            ],
                            'confidence' => [
                                'type' => 'number',
                                'description' => '0-1: your confidence in this whole classification.',
                            ],
                            'category' => [
                                'type' => 'string',
                                'enum' => array_map(fn (EventCategory $c) => $c->value, EventCategory::cases()),
                            ],
                            'language' => [
                                'type' => 'string',
                                'enum' => ['en', 'de', 'mixed', 'none_needed'],
                                'description' => 'Language a guest needs. none_needed = sports/dance/visual.',
                            ],
                            'chips' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => self::CHIPS],
                                'description' => 'ONLY chips you are certain about. A missing chip is fine; a wrong one destroys trust.',
                            ],
                            'title_en' => ['type' => 'string'],
                            'summary_en' => [
                                'type' => 'string',
                                'description' => 'Max 2 sentences IN YOUR OWN WORDS — never copy the source text.',
                            ],
                            'tip_en' => [
                                'type' => 'string',
                                'description' => 'Optional one-sentence "what to expect" for someone going alone.',
                            ],
                        ],
                        'required' => ['relevance', 'confidence', 'category', 'language', 'chips', 'title_en', 'summary_en'],
                    ],
                ]],
                'system' => 'You classify Cologne events for newcomers to Germany. Be conservative: when unsure, lower confidence and omit chips.',
                'messages' => [[
                    'role' => 'user',
                    'content' => implode("\n", array_filter([
                        "Title: {$event->title}",
                        $event->description ? "Description: {$event->description}" : null,
                        $event->location_name ? "Venue: {$event->location_name}" : null,
                        $event->starts_at ? 'Starts: '.$event->starts_at->format('D Y-m-d H:i') : null,
                        $event->price_text ? "Price: {$event->price_text}" : null,
                    ])),
                ]],
            ])
            ->throw();

        $input = collect($response->json('content', []))
            ->firstWhere('type', 'tool_use')['input'] ?? null;

        if (! is_array($input) || ! isset($input['relevance'], $input['confidence'], $input['category'], $input['language'], $input['title_en'], $input['summary_en'])) {
            throw new RuntimeException('Malformed classifier output');
        }

        return [
            'relevance' => max(0.0, min(1.0, (float) $input['relevance'])),
            'confidence' => max(0.0, min(1.0, (float) $input['confidence'])),
            'category' => (string) $input['category'],
            'language' => (string) $input['language'],
            'chips' => array_values(array_intersect((array) ($input['chips'] ?? []), self::CHIPS)),
            'title_en' => (string) $input['title_en'],
            'summary_en' => (string) $input['summary_en'],
            'tip_en' => isset($input['tip_en']) ? (string) $input['tip_en'] : null,
        ];
    }
}
