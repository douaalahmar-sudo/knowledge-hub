<?php

namespace App\Http\Controllers;

use App\Models\KaizenReport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class KaizenReportController extends Controller
{
    /**
     * Display a listing of Kaizen feedback signals for the active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $reports = KaizenReport::with(['procedure', 'user'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reports
        ]);
    }

    /**
     * Submit a new Kaizen operational discrepancy report from the field.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'procedure_id' => 'required|exists:procedures,id',
            'type_ecart'   => 'required|string|max:255', // e.g., Obsolescence, Inexactitude, Danger
            'description'  => 'required|string',
            'proposition'  => 'nullable|string',
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['status']  = 'Ouvert'; // Initial status

        $report = KaizenReport::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Signalement Kaizen transmis au Process Owner.',
            'data'    => $report
        ], 201);
    }

    /**
     * Display a single Kaizen report details.
     */
    public function show(KaizenReport $kaizenReport): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $kaizenReport->load(['procedure', 'user'])
        ]);
    }

    /**
     * Update status or add resolution comments (Process Owner action).
     */
    public function update(Request $request, KaizenReport $kaizenReport): JsonResponse
    {
        $validated = $request->validate([
            'status'        => 'sometimes|required|in:Ouvert,En cours,Résolu,Rejeté',
            'owner_comment' => 'nullable|string',
        ]);

        $kaizenReport->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Rapport Kaizen mis à jour.',
            'data'    => $kaizenReport
        ]);
    }

    /**
     * Delete a Kaizen report.
     */
    public function destroy(KaizenReport $kaizenReport): JsonResponse
    {
        $kaizenReport->delete();

        return response()->json([
            'success' => true,
            'message' => 'Signalement Kaizen supprimé.'
        ]);
    }
}