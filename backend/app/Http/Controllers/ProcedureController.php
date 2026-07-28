<?php

namespace App\Http\Controllers;

use App\Models\Procedure;
use App\Models\ProcedureVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'reference_code' => 'required|string|unique:procedures,reference_code|max:50',
            'name'        => 'required|string|max:255',
            'module'      => 'required|string|max:255',
            'category'    => 'nullable|string|max:255',
            'department'  => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'status'      => 'string|in:Validé,En attente',
            'version'     => 'nullable|string|max:20',

            // Paths previously returned by POST /procedures/upload-triptych. The
            // form uploads first, then creates the procedure with the results.
            'pdf_path'          => 'nullable|string|max:255',
            'video_path'        => 'nullable|string|max:255',
            'infographic_path'  => 'nullable|string|max:255',
        ]);

        $procedure = DB::transaction(function () use ($validated, $request) {
            return Procedure::create(array_merge($validated, [
                'tenant_id' => $request->user()->tenant_id,
                'created_by' => $request->user()->id,
                'status' => $validated['status'] ?? 'En attente',
                'version' => $validated['version'] ?? '1.0',
            ]));
        });

        return response()->json($procedure, 201);
    }

    // 2b. Edit a procedure's metadata + asset pointers in place.
    //
    // Distinct from update() below, which appends a whole new *version*. The
    // triptych edit form (Task #16) only ever amends the current record, so it
    // needs a route that does not fabricate version history on every save.
    public function updateMeta(Request $request, $id)
    {
        $procedure = Procedure::find($id);

        if (! $procedure) {
            return response()->json(['message' => 'Procédure introuvable.'], 404);
        }

        $validated = $request->validate([
            'reference_code' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('procedures', 'reference_code')->ignore($procedure->id),
            ],
            'name'        => 'sometimes|string|max:255',
            'module'      => 'sometimes|string|max:255',
            'category'    => 'nullable|string|max:255',
            'department'  => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'status'      => 'sometimes|string|in:Validé,En attente',
            'version'     => 'sometimes|string|max:20',
            'is_active'   => 'sometimes|boolean',

            'pdf_path'          => 'nullable|string|max:255',
            'video_path'        => 'nullable|string|max:255',
            'infographic_path'  => 'nullable|string|max:255',
        ]);

        $procedure->fill($validated)->save();

        return response()->json($procedure->fresh(), 200);
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