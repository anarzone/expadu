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
     * Situation-aware prompts and one representative paperwork task.
     *
     * Mirrors the bureaucracy catalogue (database/seeders/data/bureaucracy);
     * copy is marketing-toned but every deadline and fee matches the sourced
     * YAML. Static by design — the section must not shift with DB contents.
     *
     * @return array<string, array{chips: array<int, string>, task: array{title: string, deadline: string, meta: string, documents: array<int, string>, next: string}}>
     */
    private function personas(): array
    {
        return [
            'Student' => [
                'chips' => ['arrived', 'study'],
                'task' => [
                    'title' => 'Residence permit — student',
                    'deadline' => 'before your visa ends',
                    'meta' => 'Ausländerbehörde · we track the date',
                    'documents' => ['Enrolment certificate', 'Proof of funding', 'Biometric photo'],
                    'next' => 'Semester ticket check — your enrolment may already cover transit',
                ],
            ],
            'Employee' => [
                'chips' => ['arrived', 'evening'],
                'task' => [
                    'title' => 'Anmeldung — register your address',
                    'deadline' => 'within 14 days',
                    'meta' => 'Bürgeramt · free of charge',
                    'documents' => ['Passport', 'Wohnungsgeberbestätigung (landlord form)', 'Anmeldeformular'],
                    'next' => 'Health insurance — payroll asks in week one',
                ],
            ],
            'Freelancer' => [
                'chips' => ['workday', 'arrived'],
                'task' => [
                    'title' => 'Tax registration (ELSTER)',
                    'deadline' => 'first weeks',
                    'meta' => 'Fragebogen zur steuerlichen Erfassung',
                    'documents' => ['Steuer-ID', 'Business description', 'Bank details (IBAN)'],
                    'next' => 'Self-employment permit — business plan, financing and insurance',
                ],
            ],
            'Family' => [
                'chips' => ['kids', 'arrived'],
                'task' => [
                    'title' => 'Anmeldung — the whole family',
                    'deadline' => 'within 14 days',
                    'meta' => 'One appointment · everyone’s passports',
                    'documents' => ['All passports', 'Wohnungsgeberbestätigung', 'Marriage and birth certificates'],
                    'next' => 'Kindergeld and Kita place — timing matters',
                ],
            ],
        ];
    }

    /**
     * Canned prompts for the hero typing demo. No live LLM for anonymous
     * visitors — the first scenario is also server-rendered so the section
     * reads without JavaScript.
     *
     * @return array<string, array{label: string, cards: array<int, array<string, mixed>>}>
     */
    private function demoScenarios(): array
    {
        return [
            'arrived' => [
                'label' => 'I just arrived — what do I do first?',
                'cards' => [
                    ['band' => 'Day one', 'title' => 'Anmeldung — register your address', 'meta' => 'Bürgeramt · find the right office', 'why' => 'The statutory window starts after you move in', 'alternative' => ['title' => 'Gather the Anmeldung documents', 'meta' => 'Passport · landlord form · registration form', 'why' => 'Be ready when a slot opens']],
                    ['band' => 'This week', 'title' => 'Make your health-insurance status explicit', 'meta' => 'Needed for work, study and many permits', 'why' => 'The right route depends on your situation', 'alternative' => ['title' => 'Open the bank account you need', 'meta' => 'Compare fees, cash access and language support', 'why' => 'Choose for your real routine']],
                    ['band' => 'Then', 'title' => 'Watch for your tax ID', 'meta' => 'Sent after a first registration in Germany', 'why' => 'Payroll and tax processes use it', 'alternative' => ['title' => 'Protect the registration certificate', 'meta' => 'Keep the original and a secure copy', 'why' => 'Later offices can ask for it']],
                ],
            ],
            'kids' => [
                'label' => 'Free Saturday afternoon with my kids',
                'cards' => [
                    ['band' => 'Afternoon', 'title' => 'Blücherpark playground', 'meta' => 'Park and playground · 12 min away', 'why' => 'Clear-weather option before 17:00', 'outdoor' => true, 'rain' => ['title' => 'Odysseum science centre', 'meta' => 'Indoor · family-friendly', 'why' => 'Swapped because rain starts later'], 'alternative' => ['title' => 'Vorgebirgspark meadow', 'meta' => 'Picnic-friendly · 15 min', 'why' => 'Quieter on Saturdays']],
                    ['band' => 'Afternoon', 'title' => 'Kölner Zoo', 'meta' => 'Family favourite · allow about 2 hours', 'why' => 'Fits the available afternoon', 'outdoor' => true, 'rain' => ['title' => 'Aquarium at the Zoo', 'meta' => 'Indoor part of the same destination', 'why' => 'Keeps the plan workable in rain'], 'alternative' => ['title' => 'Lindenthal animal park', 'meta' => 'Free outdoor option', 'why' => 'Lower-cost alternative']],
                    ['band' => 'Late afternoon', 'title' => 'Gelato on Körnerstraße', 'meta' => 'Wind-down · same area', 'why' => 'Six minutes from the previous stop', 'alternative' => ['title' => 'Waffles at Café Jakubowski', 'meta' => 'Ehrenfeld classic', 'why' => 'Indoor alternative nearby']],
                ],
            ],
            'evening' => [
                'label' => 'Chill evening in Ehrenfeld after work',
                'cards' => [
                    ['band' => 'Evening', 'title' => 'Herbrand’s beer garden', 'meta' => 'Ehrenfeld · 8 min walk', 'why' => 'Outdoor option when the weather works', 'outdoor' => true, 'rain' => ['title' => 'Bumann & Sohn — inside', 'meta' => 'Bar · indoor seating', 'why' => 'Swapped because rain starts later'], 'alternative' => ['title' => 'Braustelle', 'meta' => 'Small Ehrenfeld brewery · 10 min', 'why' => 'Nearby alternative']],
                    ['band' => 'Evening', 'title' => 'Bumann & Sohn', 'meta' => 'Bar and occasional live music', 'why' => 'A flexible second stop', 'alternative' => ['title' => 'Sonic Ballroom', 'meta' => 'Small live-music venue', 'why' => 'Event-led alternative']],
                    ['band' => 'Heads-up', 'title' => 'Leave-by reminder', 'meta' => 'Calculated from the selected route', 'why' => 'Expadu watches the timetable for you', 'alternative' => ['title' => 'Use the night connection', 'meta' => 'Shown when it is useful', 'why' => 'Ticket coverage is checked']],
                ],
            ],
            'study' => [
                'label' => 'Cheap study spot, then something fun tonight',
                'cards' => [
                    ['band' => 'Afternoon', 'title' => 'Stadtbibliothek Köln', 'meta' => 'Quiet work areas and Wi-Fi', 'why' => 'Low-cost study option', 'alternative' => ['title' => 'University library', 'meta' => 'Long opening hours on many days', 'why' => 'Alternative study environment']],
                    ['band' => 'Dinner', 'title' => 'A quick meal near the route', 'meta' => 'Budget and dietary filters applied', 'why' => 'Keeps travel between stops low', 'alternative' => ['title' => 'Cook at home first', 'meta' => 'Cheapest option', 'why' => 'Leaves more budget for the evening']],
                    ['band' => 'Tonight', 'title' => 'An English-friendly event', 'meta' => 'Time and route checked before ranking', 'why' => 'Fits after the study block', 'alternative' => ['title' => 'Original-version cinema screening', 'meta' => 'Language preference applied', 'why' => 'Quieter evening alternative']],
                ],
            ],
            'workday' => [
                'label' => 'Somewhere to work, good coffee, lunch nearby',
                'cards' => [
                    ['band' => 'Morning', 'title' => 'A laptop-friendly café', 'meta' => 'Opening hours and work suitability checked', 'why' => 'Matches the requested work block', 'alternative' => ['title' => 'A quiet public library', 'meta' => 'Free alternative', 'why' => 'Lower-cost option']],
                    ['band' => 'Lunch', 'title' => 'Lunch within walking distance', 'meta' => 'Budget and dietary filters applied', 'why' => 'Keeps the day compact', 'alternative' => ['title' => 'Quick takeaway nearby', 'meta' => 'Shorter stop', 'why' => 'More time for work']],
                    ['band' => 'Afternoon', 'title' => 'Coworking day pass', 'meta' => 'Phone booths and reliable Wi-Fi', 'why' => 'Useful for calls and focused work', 'alternative' => ['title' => 'Return to the library', 'meta' => 'No day-pass cost', 'why' => 'Budget alternative']],
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
