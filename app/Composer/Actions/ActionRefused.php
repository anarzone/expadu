<?php

namespace App\Composer\Actions;

use RuntimeException;

/** Why a change can't be made, in words the person reads (and the AI may say). */
final class ActionRefused extends RuntimeException {}
