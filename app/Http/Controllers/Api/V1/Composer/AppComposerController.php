<?php

namespace App\Http\Controllers\Api\V1\Composer;

use App\Http\Controllers\ComposerController as Web;

/**
 * The web's own ComposerController for the iPhone app's token: the same code under /api/v1. Its own class
 * so the web's generated route helpers (Wayfinder) keep one URL per action.
 */
class AppComposerController extends Web {}
