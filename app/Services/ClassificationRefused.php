<?php

namespace App\Services;

use RuntimeException;

/**
 * The model's safety classifier declined the event. Retrying the same
 * input declines again and is billed again, so callers park the event
 * for human review instead of retrying.
 */
class ClassificationRefused extends RuntimeException {}
