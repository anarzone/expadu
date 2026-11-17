<?php

namespace App\Console\Commands;

use App\Personalisation\WeeklyMeasure;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MeasureWeek extends Command
{
    protected $signature = 'personalisation:week {--week= : a day in the week to count (Y-m-d); last week by default}';

    protected $description = 'Count a week of Today\'s ideas (shown, opened, saved, planned) in totals and keep it';

    public function handle(WeeklyMeasure $measure): int
    {
        $day = $this->option('week') !== null
            ? CarbonImmutable::parse((string) $this->option('week'), 'Europe/Berlin')
            : CarbonImmutable::now('Europe/Berlin')->subWeek();
        $report = $measure->for($day);

        DB::table('personalisation_weeks')->updateOrInsert(
            ['week' => $report['week']],
            ['report' => json_encode($report, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()],
        );

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
