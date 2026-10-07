<?php

namespace App\Privacy;

use App\Models\BureaucracyCaseMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Retain only rolling usage counts when old, encrypted raw messages are erased. */
final class LegacyProcessingUsage
{
    /** Caller holds the actor lock and deletes the same messages in its transaction. */
    public function preserve(User $actor, array $caseIds): void
    {
        BureaucracyCaseMessage::query()->whereIn('case_id', $caseIds)->where('role', 'user')
            ->where('operation', ProcessingPurpose::FactExtraction->value)->where('created_at', '>', now()->subDay())
            ->select(['id', 'created_at'])->chunkById(100, function ($messages) use ($actor): void {
                $rows = $messages->map(fn (BureaucracyCaseMessage $message): array => [
                    'legacy_message_id' => $message->id, 'actor_id' => $actor->id,
                    'attempted_at' => $message->created_at->utc()->toDateTimeString(),
                    'delete_after' => $message->created_at->utc()->addDay()->toDateTimeString(),
                ])->all();
                DB::table('bureaucracy_processing_legacy_usage')->insertOrIgnore($rows);
            });
    }

    public function used(int $actorId): int
    {
        return DB::table('bureaucracy_processing_legacy_usage')->where('actor_id', $actorId)
            ->where('attempted_at', '>', now()->utc()->subDay())->count();
    }
}
