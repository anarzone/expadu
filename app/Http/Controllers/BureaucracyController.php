<?php

namespace App\Http\Controllers;

use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\AccountPlanEntry;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\BureaucracyPerson;
use App\Waitlist\CoverageWaitlist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BureaucracyController extends Controller
{
    /**
     * Paperwork: the v2 plan for the signed-in account holder, or for someone who shared
     * theirs (?person=). Everything on the page comes from the plan read model; changes go
     * through the bureaucracy/v2 JSON commands.
     */
    public function index(Request $request, AccountPlanEntry $entry, PersonAccess $access): Response
    {
        $actor = $request->user();
        $jurisdiction = app(AccountHolderPlan::class)->jurisdiction($actor);
        $people = BureaucracyPerson::query()->where('record_status', 'active')
            ->where(fn ($query) => $query->where('account_user_id', $actor->id)
                ->orWhereHas('grants', fn ($grants) => $grants->where('grantee_user_id', $actor->id)->whereNull('revoked_at')->where('expires_at', '>', now()->utc())))
            ->orderBy('id')->limit(30)->get()
            ->filter(fn (BureaucracyPerson $person): bool => $access->scopesFor($actor, $person) !== [])
            ->map(fn (BureaucracyPerson $person): array => ['id' => $person->id, 'label' => $person->display_label,
                'kind' => $person->kind, 'is_self' => $person->account_user_id === $actor->id])
            ->values()->all();

        // Another person's plan (?person=) is shown only with a grant to view it; otherwise it
        // doesn't exist as far as this account can tell.
        $personId = $request->integer('person') ?: null;
        $other = $personId !== null && ! collect($people)->contains(fn (array $p): bool => $p['id'] === $personId && $p['is_self']);
        if ($other) {
            $person = BureaucracyPerson::query()->whereKey($personId)->where('record_status', 'active')->first();
            abort_if($person === null || ! collect($people)->contains('id', $personId), 404);
            try {
                $plan = app(PlanReadModel::class)->for($actor, $person, $jurisdiction);
            } catch (AuthorizationException|ModelNotFoundException) {
                abort(404);
            }
        }

        return Inertia::render('paperwork', [
            'entry' => $other
                ? ['schema_version' => 'bureaucracy.account-entry.1', 'state' => 'ready', 'plan' => $plan]
                : $entry->for($actor),
            'jurisdiction' => $jurisdiction,
            'people' => $people,
            // Outside Cologne: whether the person asked to be told, and confirmed by mail.
            // Read only there, and only for the signed-in account's own address.
            'coverageWaitlist' => fn () => $jurisdiction === 'outside_coverage' ? app(CoverageWaitlist::class)->state($actor) : null,
        ]);
    }
}
