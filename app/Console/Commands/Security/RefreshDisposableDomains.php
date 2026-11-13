<?php

namespace App\Console\Commands\Security;

use App\Security\DisposableEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fetches the current disposable-email-domains blocklist into storage, where
 * it replaces the copy that ships with the app. A failed or short download
 * changes nothing: the last good list stays in use.
 */
class RefreshDisposableDomains extends Command
{
    protected $signature = 'security:refresh-disposable-domains';

    protected $description = 'Fetch the latest list of throwaway email domains';

    public function handle(): int
    {
        try {
            $response = Http::timeout(30)->get(DisposableEmail::Source);
        } catch (Throwable $e) {
            $this->error('Couldn’t fetch the list: '.$e->getMessage());

            return self::FAILURE;
        }

        $domains = $response->successful() ? DisposableEmail::parse($response->body()) : [];

        if (count($domains) < DisposableEmail::MinDomains) {
            $this->error('The download had '.count($domains).' domains (status '.$response->status().'); keeping the current list.');

            return self::FAILURE;
        }

        Storage::disk('local')->put(DisposableEmail::Refreshed, implode("\n", $domains)."\n");
        $this->info(count($domains).' throwaway domains saved.');

        return self::SUCCESS;
    }
}
