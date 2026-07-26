<?php

namespace App\Http\Controllers;

use App\Models\HrRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrRequestController extends Controller
{
    /**
     * Employee view: requests submitted by the currently authenticated user.
     * (Tenant scoping is applied automatically by the BelongsToTenant trait.)
     */
    public function userRequests(Request $request): JsonResponse
    {
        $requests = HrRequest::with('user:id,name')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json($requests, 200);
    }

    /**
     * HR Admin view: every request across the current tenant/store.
     */
    public function index(): JsonResponse
    {
        $requests = HrRequest::with('user:id,name')
            ->latest()
            ->get();

        return response()->json($requests, 200);
    }

    /**
     * Employee action: create a new document/leave request with optional attachments.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'          => 'required|in:PAYSLIP,WORK_CERTIFICATE,LEAVE_REQUEST,CUSTOM',
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            // Leave requests must carry a valid date range.
            'start_date'    => 'nullable|date|required_if:type,LEAVE_REQUEST',
            'end_date'      => 'nullable|date|after_or_equal:start_date|required_if:type,LEAVE_REQUEST',
            // Supporting files.
            'attachments'   => 'nullable|array',
            'attachments.*' => 'file|max:10240|mimetypes:application/pdf,image/png,image/jpeg,image/webp,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);

        // Store each attachment on the public disk and keep its URL.
        $attachmentUrls = [];
        if ($request->hasFile('attachments')) {
            $folder = 'hr-requests/' . $request->user()->tenant_id . '/' . $request->user()->id;
            foreach ($request->file('attachments') as $file) {
                $path = $file->store($folder, 'public');
                $attachmentUrls[] = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
            }
        }

        $hrRequest = HrRequest::create([
            'user_id'     => $request->user()->id,
            'type'        => $validated['type'],
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'start_date'  => $validated['start_date'] ?? null,
            'end_date'    => $validated['end_date'] ?? null,
            'attachments' => $attachmentUrls,
            'status'      => 'PENDING',
            // tenant_id is auto-filled by the BelongsToTenant trait.
        ]);

        return response()->json($hrRequest->load('user:id,name'), 201);
    }

    /**
     * HR Admin action: approve/reject/mark-ready, add a note, and upload the generated PDF.
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        $hrRequest = HrRequest::find($id);

        if (!$hrRequest) {
            return response()->json(['message' => 'Demande introuvable.'], 404);
        }

        $validated = $request->validate([
            'status'     => 'required|in:PENDING,IN_PROGRESS,APPROVED,REJECTED,READY_FOR_DOWNLOAD',
            // A rejection must be justified.
            'admin_note' => 'nullable|string|required_if:status,REJECTED',
            // The generated document is required when marking a request ready to download.
            'pdf'        => 'nullable|file|mimetypes:application/pdf|max:20480|required_if:status,READY_FOR_DOWNLOAD',
        ]);

        if ($request->hasFile('pdf')) {
            $folder = 'hr-requests/' . $hrRequest->tenant_id . '/' . $hrRequest->user_id . '/generated';
            $hrRequest->pdf_path = $request->file('pdf')->store($folder, 'public');
        }

        $hrRequest->status = $validated['status'];
        $hrRequest->admin_note = $validated['admin_note'] ?? $hrRequest->admin_note;
        $hrRequest->save();

        return response()->json($hrRequest->load('user:id,name'), 200);
    }
}
