<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Http\Controllers\Api\StationLinesMapController as Web;

/** Travel's map: the lines through a station and their next stops, for the iPhone app's token. Its own class: one URL per Wayfinder action. */
class AppStationLinesMapController extends Web {}
