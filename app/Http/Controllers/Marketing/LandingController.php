<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class LandingController extends Controller
{
    /**
     * The "Cologne in colour" landing: one sample Wednesday told from 13:10
     * to the next morning. Product shots are static sample content (labelled
     * on the page), so the page never depends on live database contents.
     */
    public function __invoke(): View
    {
        return view('marketing.landing', [
            'faqs' => $this->faqs(),
        ]);
    }

    /**
     * Rendered as the "Good to know" band and as FAQPage structured data,
     * so the markup and the schema always match.
     *
     * @return array<int, array{q: string, a: string}>
     */
    private function faqs(): array
    {
        return [
            ['q' => 'Is Expadu free?', 'a' => 'Yes — free. No card, no trial timer.'],
            ['q' => 'Which cities does it cover?', 'a' => 'Cologne, properly — every place, office and tram line. The free tools work Germany-wide. Somewhere else? Leave your city below and you’ll know the day we arrive.'],
            ['q' => 'Do I need to speak German?', 'a' => 'No. The interface and every guide are in English. Germany will still send you letters in German — we tell you what each step means before you’re standing at the counter.'],
            ['q' => 'Is the advice official?', 'a' => 'Every fee, deadline and document list links to its official source — Stadt Köln, BAMF, ELSTER — with the date we last verified it. If something changes, you can flag it with one tap.'],
            ['q' => 'Where does my data live?', 'a' => 'On EU servers in Germany. GDPR isn’t a checkbox for us — it’s home turf.'],
            ['q' => 'Is there an app?', 'a' => 'Expadu is a web app you can install in two taps — iPhone and Android, straight from the browser. No app store detour.'],
        ];
    }
}
