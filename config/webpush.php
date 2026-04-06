<?php

return [

    /**
     * These are the keys for authentication (VAPID).
     * These keys must be safely stored and should not change.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:hello@expadu.com'),
        'public_key' => env('VAPID_PUBLIC_KEY', 'BJ_9B1fEWKeKCWNLza5ZamrzSIEkevDLxg-tdL1rb97dkfAGnN_PNmMzlbBDKslxnzGZIf2MgO0SJ-sd5X-3epc'),
        'private_key' => env('VAPID_PRIVATE_KEY', '-7iNq6ThNrJjvX00GcLzvn1WtHqFUgrCqVHE9GzzWfU'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /**
     * This is model that will be used to for push subscriptions.
     */
    'model' => \NotificationChannels\WebPush\PushSubscription::class,

    /**
     * This is the name of the table that will be created by the migration and
     * used by the PushSubscription model shipped with this package.
     */
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    /**
     * This is the database connection that will be used by the migration and
     * the PushSubscription model shipped with this package.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /**
     * The Guzzle client options used by Minishlink\WebPush.
     */
    'client_options' => [],

    /**
     * The automatic padding in bytes used by Minishlink\WebPush.
     * Set to false to support Firefox Android with v1 endpoint.
     */
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
