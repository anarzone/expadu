<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\Facts\FactDefinition;

final class CaseFactToolSchema
{
    /**
     * @return array<string, mixed>
     */
    public function for(FactDefinition $definition): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'extract_authorized_fact',
                'description' => 'Extract only the answer to the server-authored question. Do not provide advice or prose.',
                'strict' => true,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'result' => [
                            'anyOf' => [
                                $this->candidateSchema($definition),
                                $this->outcomeOnlySchema('unknown'),
                                $this->outcomeOnlySchema('off_topic'),
                            ],
                        ],
                    ],
                    'required' => ['result'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateSchema(FactDefinition $definition): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outcome' => ['type' => 'string', 'enum' => ['candidate']],
                'value' => $this->valueSchema($definition),
            ],
            'required' => ['outcome', 'value'],
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
            'date' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'],
            'integer' => ['type' => 'integer', 'minimum' => 0],
            'boolean' => ['type' => 'boolean'],
        };
    }
}
