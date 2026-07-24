<?php

namespace App\Http\Controllers;

use App\Models\KaizenSignal;
use App\Models\Procedure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class KaizenController extends Controller
{
    /**
     * Fetch active signals for the Process Owner / Admin dashboard
     */
    public function index(Request $request)
    {
        $signals = KaizenSignal::with(['procedure', 'submitter', 'processOwner'])
            ->when($request->user()->role?->name === 'process_owner', function ($q) use ($request) {
                return $q->where('assigned_to', $request->user()->id);
            })
            ->latest()
            ->get();

        return response()->json(['data' => $signals]);
    }

    /**
     * Submit a new Kaizen Signal
     * State Machine Start: 'open' -> 'assigned'
     * SLA dynamically calculated based on criticality
     * Auto-routed to the specific procedure's Process Owner
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'procedure_id' => 'required|exists:procedures,id',
            'type'         => 'required|in:obsolescence,erreur_metier,amelioration',
            'criticality'  => 'required|in:faible,moyenne,critique',
            'description'  => 'required|string|min:10',
        ]);

        // 1. Fetch procedure to extract its assigned Process Owner
        $procedure = Procedure::findOrFail($validated['procedure_id']);

        // 2. Compute dynamic SLA deadline (24h for critique, 72h for moyenne, 7d for faible)
        $slaDueAt = KaizenSignal::calculateSla($validated['criticality']);

        // 3. Create signal & auto-route to the Process Owner
        $kaizen = KaizenSignal::create([
            'procedure_id' => $procedure->id,
            'submitter_id' => $request->user()->id,
            'assigned_to'  => $procedure->process_owner_id, // Auto-routing logic
            'type'         => $validated['type'],
            'criticality'  => $validated['criticality'],
            'status'       => $procedure->process_owner_id ? 'assigned' : 'open',
            'description'  => $validated['description'],
            'sla_due_at'   => $slaDueAt,
        ]);

        return response()->json([
            'message' => 'Kaizen signal created and auto-routed successfully',
            'data'    => $kaizen->load(['procedure', 'processOwner', 'submitter']),
        ], 201);
    }

    /**
     * State Machine Transition: 'assigned' -> 'in_review'
     */
    public function inReview(Request $request, KaizenSignal $kaizen)
    {
        Gate::authorize('resolve-kaizen', $kaizen);

        $kaizen->update(['status' => 'in_review']);

        return response()->json([
            'message' => 'Kaizen ticket status updated to in_review',
            'data'    => $kaizen
        ]);
    }

    /**
     * Resolve Kaizen Ticket & Trigger Procedure Republish (v+1)
     * State Machine Transition: 'in_review' / 'assigned' -> 'resolved'
     * Procedure Status Transition: 'published' -> 'archived' (Old), 'published' (New v+1)
     */
    public function resolve(Request $request, KaizenSignal $kaizen)
    {
        // Permission check
        Gate::authorize('resolve-kaizen', $kaizen);

        $validated = $request->validate([
            'resolution_notes' => 'required|string|min:5',
            'updated_content'  => 'required|string', // Revised procedure content
        ]);

        return DB::transaction(function () use ($kaizen, $validated) {
            // 1. Update Kaizen status to 'resolved' AND save resolution notes (FIXED HERE)
            $kaizen->update([
                'status'           => 'resolved',
                'resolution_notes' => $validated['resolution_notes'],
            ]);

            // 2. Fetch original procedure
            $oldProcedure = $kaizen->procedure;

            // 3. Archive current procedure version
            $oldProcedure->update([
                'status' => 'archived'
            ]);

            // 4. Calculate new version number (e.g., v1 -> v2)
            $currentVersionNum = (int) filter_var($oldProcedure->version ?? '1', FILTER_SANITIZE_NUMBER_INT);
            $nextVersion = 'v' . max($currentVersionNum + 1, 2);

            // 5. Publish new version (v+1)
            $newProcedure = Procedure::create([
                'title'            => $oldProcedure->title,
                'content'          => $validated['updated_content'],
                'version'          => $nextVersion,
                'status'           => 'published',
                'process_owner_id' => $oldProcedure->process_owner_id,
                'tenant_id'        => $oldProcedure->tenant_id,
            ]);

            return response()->json([
                'message' => "Kaizen ticket resolved. Procedure updated from {$oldProcedure->version} to {$nextVersion}",
                'data'    => [
                    'kaizen'           => $kaizen,
                    'archived_version' => $oldProcedure,
                    'new_published_v'  => $newProcedure,
                ]
            ], 200);
        });
    }
}