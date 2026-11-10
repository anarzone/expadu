<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Http\Controllers\Api\LineRouteController as Web;

/** A line's whole route, both ways, for the iPhone app's token. Its own class: one URL per Wayfinder action. */
class AppLineRouteController extends Web {}
