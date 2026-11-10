<?php

namespace App\Http\Controllers\Api\V1\Composer;

use App\Http\Controllers\ComposerPaperworkPermissionController as Web;

/**
 * The web's own ComposerPaperworkPermissionController for the iPhone app's token: the same code under /api/v1. Its own class
 * so the web's generated route helpers (Wayfinder) keep one URL per action.
 */
class AppComposerPaperworkPermissionController extends Web {}
