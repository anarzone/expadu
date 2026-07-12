<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Spot;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('marketing.landing', [
            'stats' => $this->stats(),
            'personas' => $this->personas(),
            'demoScenarios' => $this->demoScenarios(),
            'faqs' => $this->faqs(),
        ]);
    }

    /**
     * Live content counts for the hero proof strip and trust band.
     *
     * Pulled from the database so the landing never claims numbers we don't
     * have; "+" figures are floored to the nearest hundred so they stay
     * defensible as content fluctuates between cache refreshes.
     *
     * @return array{guides: int, places: int, places_label: string, events: int, events_label: string}
     */
    private function stats(): array
    {
        $cached = Cache::get('marketing.stats');
        if (is_array($cached)) {
            return $cached;
        }

        $guides = Task::query()->where('is_published', true)->count();
        $places = Spot::query()->count();
        $events = Event::query()->where('starts_at', '>=', now())->count();

        // "4,300+" reads better than "4,356" and stays true as content grows;
        // below the rounding threshold the exact number is the honest label.
        $label = fn (int $count): string => $count >= 100
            ? number_format(intdiv($count, 100) * 100).'+'
            : (string) $count;

        $stats = [
            'guides' => $guides,
            'places' => $places,
            'places_label' => $label($places),
            'events' => $events,
            'events_label' => $label($events),
        ];

        // Don't bake zeros for an hour: right after a fresh deploy the task
        // importer may not have run yet — serve the true (empty) numbers but
        // retry on the next request instead of caching them.
        if ($guides > 0) {
            Cache::put('marketing.stats', $stats, now()->addHour());
        }

        return $stats;
    }

    /**
     * The persona switcher content: each situation's real first three tasks.
     *
     * Mirrors the bureaucracy catalogue (database/seeders/data/bureaucracy);
     * copy is marketing-toned but every deadline and fee matches the sourced
     * YAML. Static by design — the section must not shift with DB contents.
     *
     * @return array<string, array{note: string, tasks: array<int, array{title: string, meta: string, deadline: string}>}>
     */
    private function personas(): array
    {
        return [
            'Student' => [
                'note' => 'SemesterTicket, blocked account, enrolment — your checklist knows student life.',
                'tasks' => [
                    ['title' => 'Anmeldung — register your address', 'meta' => 'Bürgeramt · bring the landlord form', 'deadline' => 'within 14 days'],
                    ['title' => 'Health insurance — student rate', 'meta' => 'about €145/mo all-in (2026)', 'deadline' => 'before enrolment'],
                    ['title' => 'Residence permit — student', 'meta' => 'enrolment proof + blocked account', 'deadline' => 'before your visa ends'],
                ],
            ],
            'Employee' => [
                'note' => 'Standard permit or Blue Card? Your contract decides — Expadu walks you through both.',
                'tasks' => [
                    ['title' => 'Anmeldung — register your address', 'meta' => 'everything else depends on it', 'deadline' => 'within 14 days'],
                    ['title' => 'Health insurance', 'meta' => 'pick a Krankenkasse — payroll needs it', 'deadline' => 'first week'],
                    ['title' => 'Residence permit — work', 'meta' => 'standard vs EU Blue Card (~€50.7k salary)', 'deadline' => 'before your visa ends'],
                ],
            ],
            'Freelancer' => [
                'note' => 'The self-employed path has extra steps — tax office first, then the permit.',
                'tasks' => [
                    ['title' => 'Anmeldung — register your address', 'meta' => 'needed for everything below', 'deadline' => 'within 14 days'],
                    ['title' => 'Tax registration (ELSTER)', 'meta' => 'Fragebogen zur steuerlichen Erfassung', 'deadline' => 'first weeks'],
                    ['title' => '§21 self-employment permit', 'meta' => 'business plan + financing + insurance', 'deadline' => 'before your visa ends'],
                ],
            ],
            'Family' => [
                'note' => 'Everyone gets registered, every permit is tracked — and life events update the plan.',
                'tasks' => [
                    ['title' => 'Anmeldung — the whole family', 'meta' => 'one appointment, everyone’s passports', 'deadline' => 'within 14 days'],
                    ['title' => 'Family-reunification permits', 'meta' => 'marriage/birth certificates + translations', 'deadline' => 'before visas end'],
                    ['title' => 'Kindergeld + Kita place', 'meta' => 'Kita via LITTLE BIRD — timing matters', 'deadline' => 'after arrival'],
                ],
            ],
        ];
    }

    /**
     * Canned prompts for the hero typing demo. No live LLM for anonymous
     * visitors — the first scenario is also server-rendered so the section
     * reads without JavaScript.
     *
     * @return array<int, array{prompt: string, cards: array<int, array{band: string, t: string, m: string, why: string}>}>
     */
    private function demoScenarios(): array
    {
        return [
            [
                'prompt' => 'Free Saturday afternoon with my kids',
                'cards' => [
                    ['band' => 'Afternoon', 't' => 'Blücherpark playground', 'm' => 'park & playground · 12 min away', 'why' => 'sunny window until 17:00'],
                    ['band' => 'Afternoon', 't' => 'Kölner Zoo', 'm' => 'family favourite · ~2h visit', 'why' => 'open till 18:00'],
                    ['band' => 'Late afternoon', 't' => 'Gelato on Körnerstraße', 'm' => 'wind-down · same area', 'why' => '🚶 6 min from the park'],
                ],
            ],
            [
                'prompt' => 'I just arrived — what do I do first?',
                'cards' => [
                    ['band' => 'Day one', 't' => 'Anmeldung — register your address', 'm' => 'Bürgeramt · we book the right office', 'why' => '14-day rule — the clock is ticking'],
                    ['band' => 'This week', 't' => 'Health insurance', 'm' => 'needed for work and your permit', 'why' => 'we compare the newcomer options'],
                    ['band' => 'Then', 't' => 'Steuer-ID arrives by post', 'm' => 'automatic after Anmeldung', 'why' => 'don’t bin it — payroll needs it'],
                ],
            ],
            [
                'prompt' => 'Chill evening in Ehrenfeld after work',
                'cards' => [
                    ['band' => 'Evening', 't' => 'Herbrand’s beer garden', 'm' => 'Ehrenfeld classic · 8 min walk', 'why' => 'mild evening — outside works'],
                    ['band' => 'Evening', 't' => 'Bumann & Sohn', 'm' => 'bar · live music some nights', 'why' => 'open till late'],
                    ['band' => 'Heads-up', 't' => 'Leave by 21:40', 'm' => 'last direct tram home', 'why' => 'we watch the timetable for you'],
                ],
            ],
        ];
    }

    /**
     * FAQ content — rendered as accordions and mirrored into FAQPage JSON-LD.
     *
     * @return array<int, array{q: string, a: string}>
     */
    private function faqs(): array
    {
        return [
            ['q' => 'Is Expadu free?', 'a' => 'Free during the Cologne launch. No card, no trial timer.'],
            ['q' => 'Which cities does it cover?', 'a' => 'Cologne, properly — every place, office and tram line. The free tools work Germany-wide. Somewhere else? Leave your city in the footer and you’ll know the day we arrive.'],
            ['q' => 'Do I need to speak German?', 'a' => 'No. The interface and every guide are in English. Germany will still send you letters in German — we tell you what each step means before you’re standing at the counter.'],
            ['q' => 'Is the advice official?', 'a' => 'Every fee, deadline and document list links to its official source — Stadt Köln, BAMF, ELSTER — with the date we last verified it. If something changes, you can flag it with one tap.'],
            ['q' => 'Where does my data live?', 'a' => 'On EU servers in Germany. GDPR isn’t a checkbox for us — it’s home turf.'],
            ['q' => 'Is there an app?', 'a' => 'Expadu is a web app you can install in two taps — iPhone and Android, straight from the browser. No app store detour.'],
        ];
    }
}
