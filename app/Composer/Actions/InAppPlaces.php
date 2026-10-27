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
            'text' => 'Composer reads your Paperwork record only once you’ve allowed it, and remembers that until you turn it off. Turning it off is your choice to make, in the notice under the box or in Settings › Composer; it stops any request still on its way.',
            'place' => ['crumb' => 'Composer › About the AI › Your Paperwork', 'actions' => ['What it sends', 'Withdraw'], 'here' => 'Withdraw',
                'label' => 'Open the AI notice →', 'nav' => 'ai_notice', 'purpose' => 'composer_paperwork'],
            'note' => 'Withdrawing Composer’s AI withdraws this too.',
        ],
        'push_notifications' => [
            'title' => 'Push notifications on this device',
            'text' => 'Turning push notifications on needs your own tap in this browser, so it’s done in Settings.',
            'place' => ['crumb' => 'Settings › Notifications', 'actions' => ['Push notifications', 'Each kind'], 'here' => 'Push notifications',
                'label' => 'Open Notifications →', 'href' => '/settings/notifications'],
            'note' => 'Each kind of notification can be turned on or off there too, or here.',
        ],
        'change_email' => [
            'title' => 'Change your email',
            'text' => 'A new email needs confirming before it’s yours, so it’s changed in Settings, which sends you a link.',
            'place' => ['crumb' => 'Settings › Your account', 'actions' => ['Name', 'Email address'], 'here' => 'Email address',
                'label' => 'Open Settings →', 'href' => '/settings/account'],
        ],
        'stop_personalisation' => [
            'title' => 'Turn off personalised suggestions',
            'text' => 'Turning personalisation off deletes what Expadu learnt about what you like, so it’s your tap, in Settings.',
            'place' => ['crumb' => 'Settings › Personalised suggestions', 'actions' => ['Account', 'Personalised suggestions'], 'here' => 'Learn what I like',
                'label' => 'Open Settings →', 'href' => '/settings/suggestions'],
            'note' => 'You can turn it on again at any time; it starts learning afresh.',
        ],
        'stop_location_sharing' => [
            'title' => 'Stop sharing your location',
            'text' => 'Turning location sharing off deletes the locations Expadu kept for transit, so it’s your tap, in Settings.',
            'place' => ['crumb' => 'Settings › Transit & tickets', 'actions' => ['I have a Deutschlandticket', 'Share location for transit'], 'here' => 'Share location for transit',
                'label' => 'Open Transit settings →', 'href' => '/settings/transit'],
            'note' => 'Departures and trips then start from a stop or place you pick.',
        ],
        'delete_account' => [
            'text' => 'Deleting your account can’t be undone, so I don’t do it from here. Settings asks you to confirm with your password.',
            'place' => ['crumb' => 'Settings › Delete account', 'actions' => ['Profile', 'Delete account'], 'here' => 'Delete account',
                'label' => 'Open Settings →', 'href' => '/settings/delete'],
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
