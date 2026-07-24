<?php

namespace App\Http\Controllers;

use App\Models\Procedure;
use App\Models\ProcedureVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcedureController extends Controller
{
    // 1. Get all procedures along with their active version details
    public function index()
    {
        $procedures = Procedure::with(['currentVersion', 'creator:id,name', 'tenant:id,name'])->get();
        return response()->json($procedures, 200);
    }

    // 2. Create a new procedure AND its initial version (Atomic Transaction)
    public function store(Request $request)
    {
        $validated = $request->validate([
            'reference' => 'required|string|unique:procedures,reference|max:50',
            'name'      => 'required|string|max:255',
            'module'    => 'required|string|max:255',
            'status'    => 'string|in:Validé,En attente',
        ]);

        $procedure = DB::transaction(function () use ($validated, $request) {
            return Procedure::create([
                'tenant_id' => $request->user()->tenant_id,
                'created_by' => $request->user()->id,
                'reference' => $validated['reference'],
                'name' => $validated['name'],
                'module' => $validated['module'],
                'status' => $validated['status'] ?? 'En attente',
            ]);
        });

        return response()->json($procedure, 201);
    }

    // 3. View a specific procedure along with its complete version history
    public function show($id)
    {
        $procedure = Procedure::with(['versions', 'currentVersion', 'creator:id,name'])->find($id);

        if (!$procedure) {
            return response(['message' => 'Procédure introuvable.'], 404);
        }

        return response($procedure, 200);
    }

    // 4. Update a document by appending a NEW version (Zero-Doublon Enforcement)
    public function update(Request $request, $id)
    {
        $procedure = Procedure::find($id);

        if (!$procedure) {
            return response(['message' => 'Procédure introuvable.'], 404);
        }

        $fields = $request->validate([
            'pdf_url' => 'required|string',
            'infographic_url' => 'nullable|string',
            'video_url' => 'nullable|string',
        ]);

        $updatedProcedure = DB::transaction(function () use ($procedure, $fields) {
            $latestVersionNumber = ProcedureVersion::where('procedure_id', $procedure->id)->max('version_number');

            if ($procedure->current_version_id) {
                ProcedureVersion::where('id', $procedure->current_version_id)->update(['archived_at' => now()]);
            }

            $newVersion = ProcedureVersion::create([
                'tenant_id' => $procedure->tenant_id,
                'procedure_id' => $procedure->id,
                'version_number' => ($latestVersionNumber ?? 0) + 1,
                'pdf_url' => $fields['pdf_url'],
                'infographic_url' => $fields['infographic_url'] ?? null,
                'video_url' => $fields['video_url'] ?? null,
                'published_at' => now(),
            ]);

            $procedure->update(['current_version_id' => $newVersion->id]);

            return $procedure;
        });

        return response($updatedProcedure->load('currentVersion'), 200);
    }

    // 5. Delete a procedure entry completely from storage records
    public function destroy($id)
    {
        $procedure = Procedure::find($id);

        if (!$procedure) {
            return response(['message' => 'Procédure introuvable.'], 404);
        }

        $procedure->delete(); // Cascade rules in the DB migrations handle cleanup automatically

        return response(['message' => 'Procédure supprimée avec succès.'], 200);
    }
}