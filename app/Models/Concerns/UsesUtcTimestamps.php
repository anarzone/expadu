<?php

namespace App\Models\Concerns;

trait UsesUtcTimestamps
{
    public function getDateFormat()
    {
        return 'Y-m-d H:i:sP';
    }

    public function freshTimestamp()
    {
        return parent::freshTimestamp()->utc();
    }
}
