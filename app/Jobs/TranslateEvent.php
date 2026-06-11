<?php

namespace App\Jobs;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Translate-once-at-ingest: one LLM call per scraped German event,
 * stores both languages forever. Never runs in the request path.
 * Idempotent — re-dispatching a translated event is a no-op.
 */
class TranslateEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public Event $event,
    ) {
        $this->onConnection('redis');
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $event = $this->event->fresh();
        if ($event === null || $event->title_en !== null) {
            return; // deleted or already translated
        }

        if (! config('services.anthropic.key')) {
            Log::info('TranslateEvent skipped — no ANTHROPIC_API_KEY configured');

            return;
        }

        $response = Http::baseUrl('https://api.anthropic.com')
            ->timeout(20)
            ->withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->post('/v1/messages', [
                'model' => (string) config('services.anthropic.model'),
                'max_tokens' => 1000,
                'tool_choice' => ['type' => 'tool', 'name' => 'set_translation'],
                'tools' => [[
                    'name' => 'set_translation',
                    'description' => 'Record the translation of an event listing.',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'source_lang' => ['type' => 'string', 'description' => 'ISO 639-1 code of the original text'],
                            'title_en' => ['type' => 'string'],
                            'description_en' => ['type' => 'string'],
                        ],
                        'required' => ['source_lang', 'title_en'],
                    ],
                ]],
                'system' => 'Translate German event listings into natural, concise English for an '
                    .'expat audience. Keep proper nouns (venue names, street names, event brand '
                    .'names) untranslated. If the text is already English, return it unchanged '
                    .'with source_lang "en".',
                'messages' => [[
                    'role' => 'user',
                    'content' => "Title: {$event->title}\n\nDescription: ".($event->description ?? '(none)'),
                ]],
            ])
            ->throw();

        $input = collect($response->json('content', []))
            ->firstWhere('type', 'tool_use')['input'] ?? null;

        if (! is_array($input) || empty($input['title_en'])) {
            Log::warning('TranslateEvent got no usable translation', ['event_id' => $event->id]);

            return;
        }

        $event->update([
            'title_en' => $input['title_en'],
            'description_en' => $input['description_en'] ?? null,
            'source_lang' => $input['source_lang'] ?? 'de',
        ]);
    }
}
