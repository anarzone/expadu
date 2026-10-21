<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\Facts\FactDefinition;

final class CaseFactToolSchema
{
    /**
     * @return array<string, mixed>
     */
    public function for(FactDefinition $definition, bool $subjectAware = false): array
    {
        return [
            'name' => 'extract_authorized_fact',
            'description' => 'Extract only the answer to the server-authored question. Do not provide advice or prose.',
            'strict' => true,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'result' => [
                        'anyOf' => [
                            $this->candidateSchema($definition, $subjectAware),
                            $this->outcomeOnlySchema('unknown'),
                            $this->outcomeOnlySchema('off_topic'),
                            ...($subjectAware ? [$this->outcomeOnlySchema('unclear_subject')] : []),
                        ],
                    ],
                ],
                'required' => ['result'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateSchema(FactDefinition $definition, bool $subjectAware): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outcome' => ['type' => 'string', 'enum' => ['candidate']],
                'value' => $this->valueSchema($definition),
                ...($subjectAware ? ['subject' => ['type' => 'string', 'enum' => ['selected_person']]] : []),
            ],
            'required' => $subjectAware ? ['outcome', 'subject', 'value'] : ['outcome', 'value'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outcomeOnlySchema(string $outcome): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outcome' => ['type' => 'string', 'enum' => [$outcome]],
            ],
            'required' => ['outcome'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valueSchema(FactDefinition $definition): array
    {
        return match ($definition->type) {
            'enum' => ['type' => 'string', 'enum' => $definition->options],
            // Strict tool schemas take string formats but no numeric bounds:
            // the extractor itself rejects a negative integer.
            'date' => ['type' => 'string', 'format' => 'date'],
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
        };
    }
}
