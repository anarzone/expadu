<?php

namespace App\Console\Commands;

use App\Support\RedisLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#[Signature('api:health')]
#[Description('Check health of all external API dependencies')]
class CheckApiHealth extends Command
{
    /** @var array<int, array{name: string, status: string, ms: int, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->check('VRS TRIAS', fn () => $this->checkTrias());
        $this->check('VRS GTFS-RT', fn () => $this->checkGtfsRt());
        $this->check('KVB Open Data', fn () => $this->checkKvb());
        $this->check('Weather (Open-Meteo)', fn () => $this->checkOpenMeteo());
        $this->check('Weather (Bright Sky)', fn () => $this->checkBrightSky());
        $this->check('Geocoding (Photon)', fn () => $this->checkPhoton());
        $this->check('Rhine Level (WSV)', fn () => $this->checkRhine());
        $this->check('Resend (Email)', fn () => $this->checkResend());
        $this->check('MOTIS (Routing)', fn () => $this->checkMotis());
        $this->check('Transitous (Routing Failover)', fn () => $this->checkTransitous());

        $this->table(
            ['Service', 'Status', 'Time', 'Detail'],
            collect($this->results)->map(fn ($r) => [
                $r['name'],
                $r['status'] === 'up' ? '<fg=green>UP</>' : '<fg=red>DOWN</>',
                $r['ms'].'ms',
                $r['detail'],
            ]),
        );

        $down = collect($this->results)->where('status', 'down');

        if ($down->isNotEmpty()) {
            $names = $down->pluck('name')->implode(', ');
            Log::warning('API health check: services down', ['services' => $names]);
        }

        RedisLogger::log('api_health_check', $this->results);

        $this->info($down->isEmpty()
            ? 'All services healthy.'
            : $down->count().' service(s) down: '.$down->pluck('name')->implode(', '));

        return $down->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function check(string $name, \Closure $fn): void
    {
        $start = hrtime(true);

        try {
            $detail = $fn();
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->results[] = ['name' => $name, 'status' => 'up', 'ms' => $ms, 'detail' => $detail];
        } catch (\Throwable $e) {
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            $this->results[] = ['name' => $name, 'status' => 'down', 'ms' => $ms, 'detail' => $e->getMessage()];
        }
    }

    private function checkTrias(): string
    {
        $url = config('services.vrs.trias_url');
        if (! $url) {
            throw new \RuntimeException('Not configured');
        }

        $options = ['timeout' => 5, 'connect_timeout' => 3];
        $cert = config('services.vrs.client_cert');
        $certPass = config('services.vrs.client_cert_password');
        if ($cert && file_exists($cert)) {
            $options['cert'] = $certPass ? [$cert, $certPass] : $cert;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?><Trias version="1.1" xmlns="http://www.vdv.de/trias" xmlns:siri="http://www.siri.org.uk/siri"><ServiceRequest><siri:RequestTimestamp>'.now()->toIso8601String().'</siri:RequestTimestamp><siri:RequestorRef>expadu</siri:RequestorRef><RequestPayload><LocationInformationRequest><InitialInput><LocationName>Neumarkt</LocationName></InitialInput><Restrictions><Type>stop</Type><NumberOfResults>1</NumberOfResults></Restrictions></LocationInformationRequest></RequestPayload></ServiceRequest></Trias>';

        $response = Http::withOptions($options)
            ->withHeaders(['Content-Type' => 'text/xml; charset=UTF-8'])
            ->withBody($xml, 'text/xml')
            ->post($url);

        throw_unless($response->successful(), new \RuntimeException('HTTP '.$response->status()));

        return strlen($response->body()).' bytes';
    }

    private function checkGtfsRt(): string
    {
        $url = config('services.vrs.gtfsrt_url');
        if (! $url) {
            throw new \RuntimeException('Not configured');
        }

        $options = ['timeout' => 5, 'connect_timeout' => 3];
        $cert = config('services.vrs.client_cert');
        $certPass = config('services.vrs.client_cert_password');
        if ($cert && file_exists($cert)) {
            $options['cert'] = $certPass ? [$cert, $certPass] : $cert;
        }

        $response = Http::withOptions($options)->get($url);
        throw_unless($response->successful(), new \RuntimeException('HTTP '.$response->status()));

        return strlen($response->body()).' bytes';
    }

    private function checkKvb(): string
    {
        $response = Http::timeout(5)->get('https://data.webservice-kvb.koeln/service/opendata/haltestellen/json');
        throw_unless($response->successful(), new \RuntimeException('HTTP '.$response->status()));

        return count($response->json() ?? []).' stops';
    }

    private function checkOpenMeteo(): string
    {
        $response = Http::timeout(5)->get('https://api.open-meteo.com/v1/forecast', [
            'latitude' => 50.9375,
            'longitude' => 6.9603,
            'current' => 'temperature_2m',
        ]);
        throw_unless($response->successful(), new \RuntimeException('HTTP '.$response->status()));

        $temp = $response->json('current.temperature_2m');

        return $temp.'°C';
    }

    private function checkBrightSky(): string
    {
        $response = Http::timeout(5)->get('https://api.brightsky.dev/current_weather', [
            'lat' => 50.9375,
            'lon' => 6.9603,
        ]);
        throw_unless($response->successful(), new \RuntimeException('HTTP '.$response->status()));

        return 'OK';
    }

    private function checkPhoton(): string
    {
        $response = Http::timeout(3)->get('https://photon.komoot.io/api/', [
            'q' => 'Köln Neumarkt',
            'limit' => 1,
        ]);
        throw_unless($response->successful(), new \RuntimeException('HTTP '.$response->status()));

        $count = count($response->json('features') ?? []);

        return $count.' results';
    }

    private function checkRhine(): string
    {
        $response = Http::timeout(5)->get('https://www.pegelonline.wsv.de/webservices/rest-api/v2/stations/a6ee8177-107b-47dd-bcfd-30960ccc6e9c/W/currentmeasurement.json');
        throw_unless($response->successful(), new \RuntimeException('HTTP '.$response->status()));

        $level = $response->json('value');

        return $level.'cm';
    }

    private function checkResend(): string
    {
        $key = config('services.resend.key');
        if (! $key) {
            throw new \RuntimeException('Not configured');
        }

        $response = Http::timeout(5)
            ->withToken($key)
            ->get('https://api.resend.com/domains');

        throw_unless($response->successful(), new \RuntimeException('HTTP '.$response->status()));

        return 'API key valid';
    }

    private function checkMotis(): string
    {
        return $this->checkRoutingGeocoder((string) config('services.motis.url'), 'MOTIS');
    }

    private function checkTransitous(): string
    {
        return $this->checkRoutingGeocoder((string) config('services.transitous.url'), 'Transitous');
    }

    private function checkRoutingGeocoder(string $url, string $provider): string
    {
        if ($url === '') {
            throw new \RuntimeException('Not configured');
        }

        // Both routing adapters expose this lightweight MOTIS endpoint. It
        // proves the geocoder is reachable without creating a synthetic trip.
        $response = Http::timeout(3)
            ->connectTimeout(2)
            ->get(rtrim($url, '/').'/api/v1/geocode', [
                'text' => 'Köln Neumarkt',
                'language' => 'de',
            ]);

        throw_unless($response->successful(), new \RuntimeException('HTTP '.$response->status()));

        return $provider.' geocoder reachable';
    }
}
