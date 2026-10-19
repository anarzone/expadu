<?php

namespace App\Services;

use RuntimeException;

/** The KVB open-data stop list could not be fetched (or failed within the last minute). */
class KvbUnavailableException extends RuntimeException {}
