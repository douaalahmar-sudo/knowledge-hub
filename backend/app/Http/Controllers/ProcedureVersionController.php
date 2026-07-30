<?php

namespace App\Http\Controllers;

use App\Models\Procedure;
use App\Services\ProcedureDocumentIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcedureVersionController extends Controller
{
    public function store(Request $request, Procedure $procedure, ProcedureDocumentIngestionService $ingestionService): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimetypes:application/pdf,image/png,image/jpeg,image/webp,text/plain', 'max:20480'],
        ]);

        $version = $ingestionService->createVersion($procedure, $validated['document']);

        return response()->json([
            'message' => 'Version importée et mise en file de traitement.',
            'data' => $version->load(['procedure', 'filiale']),
        ], 201);
    }
}