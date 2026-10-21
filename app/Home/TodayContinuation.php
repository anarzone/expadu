<?php

namespace App\Home;

use App\Composer\WorkspaceStore;
use App\Models\User;

/**
 * Today's "Pick up where you left off" (the mock's .column-continuation):
 * the Composer conversation it opens on, when it has something in it.
 * Read through WorkspaceStore::read(), which upgrades and projects the
 * stored state (session 2). Changes apply directly in Composer, so there is
 * nothing waiting to be confirmed; it only offers to carry on.
 */
final class TodayContinuation
{
    private const UNTITLED = 'New conversation';

    public function __construct(private WorkspaceStore $workspaces) {}

    /** @return array{title: string, last_at: ?string}|null */
    public function for(User $user): ?array
    {
        $state = $this->workspaces->read($user);
        $active = collect($state['conversations'] ?? [])->firstWhere('id', $state['active'] ?? null);
        $requests = collect($active['messages'] ?? [])
            ->filter(fn (array $message) => ($message['role'] ?? null) === 'user' && trim((string) ($message['text'] ?? '')) !== '');

        if ($requests->isEmpty()) {
            return null;
        }

        $title = trim((string) ($active['title'] ?? ''));

        return [
            // Its title, or what was last asked while it has none.
            'title' => $title !== '' && $title !== self::UNTITLED ? $title : (string) $requests->last()['text'],
            'last_at' => collect($active['messages'])->last()['created_at'] ?? null,
        ];
    }
}
