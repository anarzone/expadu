<?php

namespace App\Bureaucracy\Questions;

use App\Bureaucracy\Facts\CalendarDate;
use App\Bureaucracy\Facts\FactRegistry;
use App\Profile\Applicability;
use Symfony\Component\Yaml\Yaml;

final class OrientationQuestions
{
    public function __construct(private FactRegistry $facts) {}

    /** @return list<string> */
    public function missingKeys(array $attributes): array
    {
        $protocol = Yaml::parseFile(dirname(__DIR__, 3).'/database/seeders/data/bureaucracy/schema/orientation-questions.yaml');
        $keys = [];

        foreach ($protocol['questions'] as $question) {
            $key = $this->facts->definition($question['fact_key'])->key;
            if (($attributes[$key] ?? null) !== null
                || Applicability::evaluate($question['when'] ?? null, $attributes) !== Applicability::Yes) {
                continue;
            }

            if ($question['requires_actual_arrival'] ?? false) {
                $arrival = CalendarDate::parse($attributes['arrival_date'] ?? null);
                if ($arrival === null || $arrival->isFuture()) {
                    continue;
                }
            }

            $keys[] = $key;
        }

        return $keys;
    }
}
