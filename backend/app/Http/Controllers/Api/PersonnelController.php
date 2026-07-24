<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PersonnelController extends Controller
{
    /**
     * List all personnel/users scoped to the active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $personnel = User::with('role')->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $personnel
        ]);
    }

    /**
     * Create a new personnel entry under the current tenant.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role_id'  => 'nullable|exists:roles,id',
        ]);

        $validated['password'] = bcrypt($validated['password']);

        $user = User::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Membre du personnel créé avec succès.',
            'data'    => $user->load('role')
        ], 201);
    }

    /**
     * Display details of a specific personnel member.
     */
    public function show(User $personnel): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $personnel->load('role')
        ]);
    }

    /**
     * Update personnel profile or role assignment.
     */
    public function update(Request $request, User $personnel): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'sometimes|required|string|max:255',
            'email'   => 'sometimes|required|string|email|max:255|unique:users,email,' . $personnel->id,
            'role_id' => 'nullable|exists:roles,id',
        ]);

        $personnel->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour.',
            'data'    => $personnel->load('role')
        ]);
    }

    /**
     * Remove personnel member from the tenant directory.
     */
    public function destroy(User $personnel): JsonResponse
    {
        $personnel->delete();

        return response()->json([
            'success' => true,
            'message' => 'Membre supprimé avec succès.'
        ]);
    }
}