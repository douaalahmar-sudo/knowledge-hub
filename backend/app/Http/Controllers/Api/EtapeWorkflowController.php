<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EtapeWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EtapeWorkflowController extends Controller
{
    /**
     * Get all workflow steps for the authenticated user's filiale.
     */
    public function index(Request $request): JsonResponse
    {
        // Scoping comes from the RLS policy on the underlying table — add it to
        // the RLS migration's STRICT_TABLES list when this table gains filiale_id.
        $steps = EtapeWorkflow::with(['procedure', 'assignedUser'])->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $steps
        ]);
    }

    /**
     * Store a new workflow step for a procedure.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'procedure_id' => 'required|exists:procedures,id',
            'step_name'    => 'required|string|max:255',
            'status'       => 'required|in:Pending,Approved,Rejected',
            'assigned_to'  => 'nullable|exists:users,id',
            'comments'     => 'nullable|string',
        ]);

        $step = EtapeWorkflow::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Étape de workflow créée avec succès.',
            'data'    => $step
        ], 201);
    }

    /**
     * Display a specific workflow step.
     */
    public function show(EtapeWorkflow $etapeWorkflow): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $etapeWorkflow->load(['procedure', 'assignedUser'])
        ]);
    }

    /**
     * Update/Approve/Reject a workflow step state.
     */
    public function update(Request $request, EtapeWorkflow $etapeWorkflow): JsonResponse
    {
        $validated = $request->validate([
            'status'   => 'sometimes|required|in:Pending,Approved,Rejected',
            'comments' => 'nullable|string',
        ]);

        $etapeWorkflow->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Statut de l\'étape mis à jour.',
            'data'    => $etapeWorkflow
        ]);
    }

    /**
     * Remove a workflow step.
     */
    public function destroy(EtapeWorkflow $etapeWorkflow): JsonResponse
    {
        $etapeWorkflow->delete();

        return response()->json([
            'success' => true,
            'message' => 'Étape supprimée.'
        ]);
    }
}