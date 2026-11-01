<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AccountExportRequest;
use App\Jobs\BuildAccountExport;
use App\Models\AccountExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings › Your data: "Download your data". Asking needs the same
 * confirmation as deleting the account; the file is built in the
 * background, is only ever the asker's, and lasts 24 hours.
 */
class AccountExportController extends Controller
{
    /** The latest request, for the panel to show its state. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['export' => $this->state($this->latest($request))]);
    }

    public function store(AccountExportRequest $request): JsonResponse
    {
        $user = $request->user();
        $latest = $this->latest($request);

        // One at a time: a file being built or still ready is the answer.
        if ($latest !== null && ($latest->status === 'queued' || $latest->isReady())) {
            return response()->json(['export' => $this->state($latest)]);
        }

        $export = AccountExport::create(['user_id' => $user->id, 'status' => 'queued']);
        BuildAccountExport::dispatch($export);

        return response()->json(['export' => $this->state($export->fresh())], 202);
    }

    public function download(Request $request, AccountExport $export): StreamedResponse
    {
        abort_unless($export->user_id === $request->user()->id, 404);
        abort_unless($export->isReady() && Storage::disk('local')->exists((string) $export->path), 410, 'This download has expired. Ask for a new one in Settings.');

        return Storage::disk('local')->download(
            (string) $export->path,
            'expadu-data-'.$export->ready_at?->format('Y-m-d').'.zip',
            ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private'],
        );
    }

    private function latest(Request $request): ?AccountExport
    {
        return AccountExport::query()->where('user_id', $request->user()->id)->latest()->first();
    }

    /** @return array<string, mixed>|null */
    private function state(?AccountExport $export): ?array
    {
        if ($export === null || ($export->status === 'ready' && ! $export->isReady())) {
            return null;
        }

        return [
            'id' => $export->id,
            'status' => $export->status,
            'bytes' => $export->bytes,
            'expires_at' => $export->expires_at?->toIso8601String(),
            'url' => $export->isReady() ? route('account-export.download', $export) : null,
        ];
    }
}
