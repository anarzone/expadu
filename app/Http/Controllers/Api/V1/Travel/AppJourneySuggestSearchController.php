<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Http\Controllers\Api\JourneySuggestSearchController as Web;

/** Plan a trip's suggestions: saved places, recents and the geocoder, for the iPhone app's token. Its own class: one URL per Wayfinder action. */
class AppJourneySuggestSearchController extends Web {}
