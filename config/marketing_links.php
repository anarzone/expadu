<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hosts the daily link check leaves to a person
    |--------------------------------------------------------------------------
    |
    | marketing:check-links reads every outside link on the marketing pages.
    | These hosts put a bot check in front of every automated visitor, so a
    | daily alert about them would only ever be noise. The command lists their
    | links as "check by hand" and never raises an alert for them. A bot check
    | is never solved or worked around.
    |
    */
    'manual_hosts' => [
        'make-it-in-germany.com',
    ],
];
