<?php

namespace App\Http\Controllers\Api\V1;

use App\Home\BringList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BringDocumentRequest;
use App\Http\Resources\Api\V1\BringListResource;
use Illuminate\Http\Request;

class PaperworkDocumentsController extends Controller
{
    /** What to bring to a Paperwork appointment (Today's plans' `bring.process`): its documents, each ready or not. */
    public function index(Request $request, string $process, BringList $bring): BringListResource
    {
        return new BringListResource($bring->for($request->user(), $process));
    }

    /** Tick a document ready or not: the same record Paperwork's Documents list changes. */
    public function update(BringDocumentRequest $request, string $process, string $document, BringList $bring): BringListResource
    {
        return new BringListResource($bring->set($request->user(), $process, $document, $request->boolean('ready')));
    }
}
