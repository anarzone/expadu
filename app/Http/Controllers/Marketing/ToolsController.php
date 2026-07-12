<?php

namespace App\Http\Controllers\Marketing;

use App\Bureaucracy\PermanentResidencyEligibility;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * The free tools on expadu.com/tools — pure client-side calculators whose
 * constants come from the same engines the app uses (fare config, NE
 * eligibility tracks), so a figure can never drift between app and tool.
 */
class ToolsController extends Controller
{
    public function index(): View
    {
        return view('marketing.tools.index');
    }

    public function dticket(): View
    {
        return view('marketing.tools.dticket', [
            'fares' => config('rheinlandtarif.single'),
            'dticketEur' => config('rheinlandtarif.deutschlandticket.monthly_eur'),
            'jobticketEur' => config('rheinlandtarif.deutschlandticket.jobticket_approx_eur'),
            'eezyCapEur' => config('rheinlandtarif.eezy.monthly_cap_eur'),
            'verifiedAt' => config('rheinlandtarif.verified_at'),
            'sourceUrl' => config('rheinlandtarif.source'),
            'toolData' => [
                'fares' => config('rheinlandtarif.single'),
                'dticket' => config('rheinlandtarif.deutschlandticket.monthly_eur'),
                'jobticket' => config('rheinlandtarif.deutschlandticket.jobticket_approx_eur'),
                'eezyCap' => config('rheinlandtarif.eezy.monthly_cap_eur'),
            ],
        ]);
    }

    public function residency(): View
    {
        $tracks = PermanentResidencyEligibility::tracks();

        return view('marketing.tools.residency', [
            'tracks' => $tracks,
            'toolData' => [
                'tracks' => collect($tracks)
                    ->map(fn (array $track, string $key): array => [...$track, 'key' => $key])
                    ->values()
                    ->all(),
                'blueCardAltMonths' => 27,
                'skilledDegreeMonths' => 24,
            ],
        ]);
    }

    public function citizenship(): View
    {
        return view('marketing.tools.citizenship', [
            'rules' => $this->citizenshipRules(),
        ]);
    }

    /**
     * German citizenship (naturalisation) rules after the 2024 StAG reform.
     * Figures cite the statute directly; the quiz never asserts eligibility —
     * it maps answers to the likely track and lists what must still be true.
     *
     * @return array{standard_years: int, fast_years: int, spouse_residence_years: int, spouse_marriage_years: int, reform_date: string, sources: array<int, array{label: string, url: string}>}
     */
    private function citizenshipRules(): array
    {
        return [
            'standard_years' => 5,
            'fast_years' => 3,
            'spouse_residence_years' => 3,
            'spouse_marriage_years' => 2,
            'reform_date' => '2024-06-27',
            'sources' => [
                ['label' => '§ 10 StAG — standard naturalisation', 'url' => 'https://www.gesetze-im-internet.de/stag/__10.html'],
                ['label' => '§ 9 StAG — spouses of German citizens', 'url' => 'https://www.gesetze-im-internet.de/stag/__9.html'],
                ['label' => 'BAMF — naturalisation test', 'url' => 'https://www.bamf.de/DE/Themen/Integration/ZugewanderteTeilnehmende/Einbuergerung/einbuergerung-node.html'],
            ],
        ];
    }
}
