<?php

namespace App\Onboarding;

use App\Bureaucracy\Facts\FactInputMethod;
use App\Bureaucracy\Facts\WriteFactAssertion;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyOnboardingDraft;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CompleteBureaucracyOnboarding
{
    public function __construct(private PersonCommandScope $scope, private BureaucracyDraftSchema $schema, private WriteFactAssertion $writer, private DraftFactChanges $changes) {}

    public function execute(User $actor, BureaucracyPerson $person, string $draftId, int $draftVersion, int $expectedFactRevision, string $requestId): array
    {
        if (! Str::isUuid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'Use a request identifier for confirmation.']);
        }
        $requestId = strtolower($requestId);

        return $this->scope->run($actor, $person, AccessScope::EditFacts, function ($person, $case) use ($actor, $draftId, $draftVersion, $expectedFactRevision, $requestId): array {
            $draft = BureaucracyOnboardingDraft::query()->whereKey($draftId)->lockForUpdate()->first();
            if ($draft === null || $draft->actor_id !== $actor->id || $draft->person_id !== $person->id) {
                throw new AuthorizationException;
            }
            if ($draft->status === 'completed' && $draft->completion_request_id === $requestId && $draft->version === $draftVersion) {
                return $this->receipt($draft);
            }
            if ($draft->status !== 'active' || $draft->expires_at->lessThanOrEqualTo(now()) || $draft->version !== $draftVersion
                || $draft->schema_version !== $this->schema->version() || $case->fact_version !== $expectedFactRevision) {
                throw new ConflictHttpException('The draft, confirmed answers or form changed. Review them again before confirmation.');
            }
            app(ArchiveSyntheticAnswers::class)->execute($person, $case);
            $answers = $this->changes->prepare($case, $this->schema->confirmed($draft->payload['answers']));
            foreach ($answers as $key => $answer) {
                $state = $answer['answer_state'] ?? 'value';
                if (! $answer['_write']) {
                    continue;
                }
                try {
                    $this->writer->write($actor, $person, $key, $answer['value'], $answer['effective_from'] ?? null,
                        $case->fresh()->fact_version, $state, $answer['_corrects_id'], FactInputMethod::Onboarding);
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ['answers.'.$key.'.'.$field => $messages])->all());
                }
            }
            if ($person->account_user_id === $actor->id) {
                User::query()->whereKey($actor->id)->update(['onboarded_at' => now()]);
            }
            $draft->update(['status' => 'completed', 'payload' => null, 'completion_request_id' => $requestId,
                'completed_fact_revision' => $case->fresh()->fact_version, 'completed_at' => now()->utc(),
                'expires_at' => now()->utc()->addDays(config('bureaucracy_onboarding.receipt_days'))]);

            return $this->receipt($draft->fresh());
        });
    }

    private function receipt(BureaucracyOnboardingDraft $draft): array
    {
        return ['status' => 'confirmed', 'person_id' => $draft->person_id, 'fact_revision' => $draft->completed_fact_revision];
    }
}
