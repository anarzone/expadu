<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Public tools use sourced configuration. Legacy legal calculators remain
 * unavailable until their rules satisfy the publication/review requirements.
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
        return view('marketing.tools.awaiting-review', [
            'toolName' => 'Permanent-residency timeline',
        ]);
    }

    public function nettoBrutto(): View
    {
        return view('marketing.tools.netto-brutto', [
            'year' => config('lohnrechner.year'),
            'verifiedAt' => config('lohnrechner.verified_at'),
            'sourceTariff' => config('lohnrechner.source_tariff'),
            'sourceSv' => config('lohnrechner.source_sv'),
            'source39b' => config('lohnrechner.source_39b'),
            'sourceCare' => config('lohnrechner.source_care'),
            'toolData' => config('lohnrechner'),
        ]);
    }

    public function citizenship(): View
    {
        return view('marketing.tools.awaiting-review', [
            'toolName' => 'Citizenship quiz',
        ]);
    }

    /**
     * Archived legacy rules, retained for human review only. These must not be
     * used in a public response without the project's authority/source gates.
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
