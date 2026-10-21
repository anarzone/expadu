<?php

namespace App\Bureaucracy\Analyser;

final class AnswerToolSchema
{
    public const Name = 'answer_from_cards';

    /**
     * One strict tool. Card ids are an enum of this request's candidates; strict schemas
     * take no length or count bounds, so the verifier enforces those.
     *
     * @param  list<string>  $cardIds
     * @return array<string, mixed>
     */
    public function for(array $cardIds): array
    {
        $cardId = ['type' => 'string', 'enum' => array_values($cardIds)];

        return [
            'name' => self::Name,
            'description' => 'Answer only from the supplied cards. Each sentence restates one exact quote from one card. No other knowledge.',
            'strict' => true,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'answerable' => ['type' => 'boolean'],
                    'sentences' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'card_id' => $cardId,
                            'quote' => ['type' => 'string'],
                        ],
                        'required' => ['text', 'card_id', 'quote'],
                        'additionalProperties' => false,
                    ]],
                    'related_card_ids' => ['type' => 'array', 'items' => $cardId],
                ],
                'required' => ['answerable', 'sentences', 'related_card_ids'],
                'additionalProperties' => false,
            ],
        ];
    }
}
