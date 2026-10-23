<?php

namespace App\Composer\Actions;

/**
 * Changes Composer never makes (owner, 23 Oct 2026: deleting a record, stopping its
 * processing, an AI permission, the account): what would happen, and the exact place
 * that does it, where the person confirms it themselves.
 */
final class InAppPlaces
{
    public const PLACES = [
        'delete_paperwork_record' => [
            'text' => 'Deleting your Paperwork record can’t be undone, so I don’t do it from here. Paperwork asks you to confirm before anything is erased.',
            'crumb' => 'Paperwork › Share your plan › Your record', 'actions' => ['Download a copy', 'Stop processing', 'Delete your record'], 'here' => 'Delete your record',
            'go' => ['label' => 'Open Your record →', 'href' => '/bureaucracy?open=record'], 'note' => 'Want a copy first? Download it there before you delete.',
        ],
        'stop_paperwork_processing' => [
            'text' => 'Stopping its processing is your right over your data, so it’s your tap, in Paperwork. It keeps your record but doesn’t use it until you resume.',
            'crumb' => 'Paperwork › Share your plan › Your record', 'actions' => ['Download a copy', 'Stop processing', 'Delete your record'], 'here' => 'Stop processing',
            'go' => ['label' => 'Open Your record →', 'href' => '/bureaucracy?open=record'], 'note' => 'You can resume processing there at any time.',
        ],
        'withdraw_ai_permission' => [
            'text' => 'Withdrawing your permission for Composer’s AI is your choice to make, in its notice under the box. Composer then answers without the AI.',
            'crumb' => 'Composer › About the AI', 'actions' => ['What it sends', 'Withdraw'], 'here' => 'Withdraw',
            // The notice under Composer's box holds Withdraw (ai-notice.tsx).
            'go' => ['label' => 'Open the AI notice →', 'nav' => 'ai_notice'], 'note' => 'You can give it again with your next message.',
        ],
        'delete_account' => [
            'text' => 'Deleting your account can’t be undone, so I don’t do it from here. Settings asks you to confirm with your password.',
            'crumb' => 'Settings › Profile › Delete account', 'actions' => ['Profile', 'Delete account'], 'here' => 'Delete account',
            'go' => ['label' => 'Open Settings →', 'href' => '/settings/profile'], 'note' => 'Your Paperwork record has its own copy to download first.',
        ],
    ];

    /** @return array<string, mixed>|null the card for it */
    public static function card(string $what): ?array
    {
        $place = self::PLACES[$what] ?? null;

        // Composer's own words: the card says them, and the answer when the AI said nothing.
        return $place === null ? null : ['type' => 'handoff', 'what' => $what, ...$place];
    }
}
