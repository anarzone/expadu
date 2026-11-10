<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Http\Controllers\Api\LineStopController as Web;

/** One stop's runs on a line, read as it comes into view, for the iPhone app's token. Its own class: one URL per Wayfinder action. */
class AppLineStopController extends Web {}
