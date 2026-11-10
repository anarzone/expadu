<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Http\Controllers\Api\StationSearchController as Web;

/** Travel's stop picker: KVB stations by name, for the iPhone app's token. Its own class: one URL per Wayfinder action. */
class AppStationSearchController extends Web {}
