<?php

namespace App\Marketing;

use Illuminate\Http\Request;

/**
 * Which hosts search engines may index. Only production's marketing host
 * should rank: staging is a duplicate of it, and the app host has no public
 * pages. Read by robots.txt and the X-Robots-Tag header (SecurityHeaders).
 */
class SearchIndexing
{
    /**
     * The X-Robots-Tag value for this request, or null when it may be indexed.
     */
    public static function robotsTag(Request $request): ?string
    {
        if (self::isHidden($request)) {
            return 'noindex, nofollow';
        }

        // The app host is all auth screens and walled pages: nothing to rank.
        if ($request->getHost() === config('app.app_domain')) {
            return 'noindex';
        }

        return null;
    }

    /**
     * Every non-production environment and every staging host stays out of
     * search entirely. The host check matters: staging's APP_ENV is set on
     * the box, not in the repo, so the environment alone can't be trusted.
     */
    public static function isHidden(Request $request): bool
    {
        return ! app()->isProduction()
            || preg_match('/(^|\.)staging\./', $request->getHost()) === 1;
    }
}
