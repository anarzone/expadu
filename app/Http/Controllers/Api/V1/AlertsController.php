<?php

namespace App\Http\Controllers\Api\V1;

use App\Alerts\AlertPresenter;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AlertsResource;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertsController extends Controller
{
    /** The bell's sheet: the web's Alerts list (AlertController::index), shaped for the app. */
    public function index(Request $request, AlertPresenter $presenter): AlertsResource
    {
        $alerts = $presenter->recent($request->user());
        $notices = $presenter->notices($alerts);

        return new AlertsResource([
            'alerts' => $alerts->map(function (Alert $alert) use ($presenter, $notices) {
                $notice = $notices[$alert->group_key] ?? null;

                return [...$presenter->present($alert, $notice), 'subtype' => $alert->subtype,
                    'lines' => $presenter->lines($alert, $notice), 'stops' => $notice['stops'] ?? []];
            })->all(),
            'unread' => $presenter->unread($request->user()),
        ]);
    }

    /** Mark one of their alerts read; another person's is not found, so nothing says it exists. */
    public function read(Request $request, int $alert): JsonResponse
    {
        $request->user()->alerts()->findOrFail($alert)->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
