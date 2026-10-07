<?php

namespace App\Onboarding;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyOnboardingDraft;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SaveBureaucracyDraft
{
    public function __construct(private PersonCommandScope $scope, private BureaucracyDraftSchema $schema) {}

    public function execute(User $actor, BureaucracyPerson $person, string $draftId, int $expectedVersion, int $step, array $answers): BureaucracyOnboardingDraft
    {
        if (! Str::isUuid($draftId) || $expectedVersion < 0 || $step < 1 || $step > config('bureaucracy_onboarding.max_steps')) {
            throw ValidationException::withMessages(['draft' => 'Use a current draft identifier, version and step.']);
        }

        return $this->scope->run($actor, $person, AccessScope::EditFacts, function () use ($actor, $person, $draftId, $expectedVersion, $step, $answers): BureaucracyOnboardingDraft {
            $draft = BureaucracyOnboardingDraft::query()->whereKey($draftId)->lockForUpdate()->first();
            if ($draft !== null && ($draft->actor_id !== $actor->id || $draft->person_id !== $person->id)) {
                throw new AuthorizationException;
            }
            if ($draft !== null && ($draft->status !== 'active' || $draft->expires_at->lessThanOrEqualTo(now())
                || $draft->version !== $expectedVersion || $draft->schema_version !== $this->schema->version())) {
                throw new ConflictHttpException('The draft changed or expired. Reload it before editing.');
            }
            if ($draft === null && $expectedVersion !== 0) {
                throw new ConflictHttpException('This draft is no longer available.');
            }
            if ($draft === null) {
                // Expired unfinished text is discarded, not promoted to confirmed answers.
                BureaucracyOnboardingDraft::query()->where('actor_id', $actor->id)->where('person_id', $person->id)
                    ->where('status', 'active')->where('expires_at', '<=', now()->utc())->update(['status' => 'expired', 'payload' => null]);
                if (BureaucracyOnboardingDraft::query()->where('actor_id', $actor->id)->where('person_id', $person->id)->where('status', 'active')->exists()) {
                    throw new ConflictHttpException('Another draft is active for this person. Reload before starting again.');
                }
            }
            $merged = $this->schema->merge($draft?->payload['answers'] ?? [], $answers);
            $draft ??= new BureaucracyOnboardingDraft(['id' => $draftId, 'actor_id' => $actor->id, 'person_id' => $person->id]);
            $draft->fill(['schema_version' => $this->schema->version(), 'version' => $expectedVersion + 1, 'status' => 'active',
                'payload' => ['step' => $step, 'answers' => $merged],
                'expires_at' => now()->utc()->addDays(config('bureaucracy_onboarding.draft_days'))])->save();

            return $draft->fresh();
        });
    }

    public function read(User $actor, BureaucracyPerson $person): ?array
    {
        return $this->scope->run($actor, $person, AccessScope::EditFacts, function () use ($actor, $person): ?array {
            $draft = BureaucracyOnboardingDraft::query()->where('actor_id', $actor->id)->where('person_id', $person->id)
                ->where('status', 'active')->where('expires_at', '>', now()->utc())->first();
            if ($draft === null) {
                return null;
            }

            return ['id' => $draft->id, 'version' => $draft->version, 'schema_version' => $draft->schema_version,
                'needs_review' => $draft->schema_version !== $this->schema->version(), 'expires_at' => $draft->expires_at->toIso8601String(),
                ...$draft->payload];
        });
    }

    public function discard(User $actor, BureaucracyPerson $person, string $draftId, int $expectedVersion): void
    {
        $this->scope->run($actor, $person, AccessScope::EditFacts, function () use ($actor, $person, $draftId, $expectedVersion): void {
            $draft = BureaucracyOnboardingDraft::query()->whereKey($draftId)->lockForUpdate()->first();
            if ($draft === null || $draft->person_id !== $person->id || $draft->actor_id !== $actor->id) {
                throw new AuthorizationException;
            }
            if ($draft->version !== $expectedVersion || ! in_array($draft->status, ['active', 'discarded'], true)) {
                throw new ConflictHttpException('The draft changed. Reload it before discarding it.');
            }
            $draft->update(['status' => 'discarded', 'payload' => null, 'expires_at' => now()->utc()]);
        });
    }
}
