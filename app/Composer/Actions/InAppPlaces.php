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
            'place' => ['crumb' => 'Paperwork › Share your plan › Your record', 'actions' => self::RECORD, 'here' => 'Delete your record',
                'label' => 'Open Your record →', 'href' => '/bureaucracy?record=delete'],
            'note' => 'Want a copy first? Download it there before you delete.',
        ],
        'stop_paperwork_processing' => [
            'title' => 'Stop Paperwork processing your record',
            'text' => 'Stopping its processing is your right over your data, so it’s your tap, in Paperwork. It keeps your record but doesn’t use it until you resume.',
            'place' => ['crumb' => 'Paperwork › Share your plan › Your record', 'actions' => self::RECORD, 'here' => 'Stop processing',
                'label' => 'Open Your record →', 'href' => '/bureaucracy?record=stop'],
            'note' => 'You can resume processing there at any time.',
        ],
        'withdraw_ai_permission' => [
            'text' => 'Withdrawing your permission for Composer’s AI is your choice to make, in its notice under the box. Composer then answers without the AI.',
            // The notice under Composer's box holds Withdraw (ai-notice.tsx): opened by the page, not a link.
            'place' => ['crumb' => 'Composer › About the AI', 'actions' => ['What it sends', 'Withdraw'], 'here' => 'Withdraw',
                'label' => 'Open the AI notice →', 'nav' => 'ai_notice'],
            'note' => 'You can give it again with your next message.',
        ],
        'withdraw_paperwork_permission' => [
            'title' => 'Stop Composer reading it',
            'text' => 'Composer reads your Paperwork record only for a message you allow it on. Withdrawing that permission is your choice to make, in the notice under the box; it stops any request still on its way.',
            'place' => ['crumb' => 'Composer › About the AI › Your Paperwork', 'actions' => ['What it sends', 'Withdraw'], 'here' => 'Withdraw',
                'label' => 'Open the AI notice →', 'nav' => 'ai_notice', 'purpose' => 'composer_paperwork'],
            'note' => 'Withdrawing Composer’s AI withdraws this too.',
        ],
        'delete_account' => [
            'text' => 'Deleting your account can’t be undone, so I don’t do it from here. Settings asks you to confirm with your password.',
            'place' => ['crumb' => 'Settings › Profile › Delete account', 'actions' => ['Profile', 'Delete account'], 'here' => 'Delete account',
                'label' => 'Open Settings →', 'href' => '/settings/profile'],
            'note' => 'Your Paperwork record has its own copy to download first.',
        ],
    ];

    /** Stopping the use of their Paperwork data, two ways: the other one is always offered too. */
    public const STOPPING = ['withdraw_paperwork_permission' => 'stop_paperwork_processing', 'stop_paperwork_processing' => 'withdraw_paperwork_permission'];

    /** Your record's own actions in Paperwork, as its panel names them. */
    private const RECORD = ['Download a copy', 'Stop processing', 'Delete your record'];

    /** @return array<string, mixed>|null the card for it */
    public static function card(string $what): ?array
    {
        $place = self::PLACES[$what] ?? null;

        // Composer's own words: the card says them, and the answer when the AI said nothing.
        return $place === null ? null : ['type' => 'handoff', 'what' => $what, ...$place];
    }
}
