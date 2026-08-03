<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication endpoints (Laravel Sanctum personal access tokens).
 *
 * ---------------------------------------------------------------------------
 * PRODUCTION SSO INTEGRATION PATH (Azure AD / OAuth2 / SAML2)
 * ---------------------------------------------------------------------------
 * `ssoMock()` below is a TEST DOUBLE ONLY — it issues a bearer token from an
 * email address with no credential verification whatsoever. It is hard-disabled
 * outside local/testing environments (see the guard in that method). Do not
 * "temporarily" enable it in production: it is a complete authentication bypass.
 *
 * To replace it with real enterprise SSO:
 *
 * 1. OIDC / OAuth2 (Azure AD — recommended for Microsoft 365 tenants)
 *    - Register the app in Azure Portal > Entra ID > App registrations. Record
 *      the Application (client) ID, Directory (tenant) ID, and a client secret.
 *    - Install a provider bridge: `composer require laravel/socialite` plus
 *      `socialiteproviders/microsoft-azure`.
 *    - Add redirect route `/api/v1/auth/sso/azure/callback` and whitelist that
 *      exact URI in the Azure app registration.
 *    - On callback: validate the `id_token` signature against Azure's JWKS
 *      endpoint, verify `iss`, `aud`, `exp`, and `nonce` claims, then match the
 *      verified `email`/`oid` claim to a local User row.
 *
 * 2. SAML2 (for identity providers without OIDC support)
 *    - Use `aacotroneo/laravel-saml2` or `24slides/laravel-saml2`.
 *    - Exchange IdP metadata XML, configure the x509 certificate, and verify
 *      the SAML assertion signature on every response before trusting it.
 *
 * 3. Shared hardening for either path
 *    - JIT-provision users, but map roles from an IdP group claim — never let
 *      the client supply its own role. Deny login if no group maps to a role.
 *    - Keep `filiale_id` assignment server-side, derived from the IdP claim.
 *    - Enforce token expiry (`config('sanctum.expiration')`) and revoke tokens
 *      on IdP-side deprovisioning via SCIM or a scheduled reconciliation job.
 */
class AuthController extends Controller
{
    /**
     * Issue a token for valid credentials.
     *
     * Returns 401 (not Laravel's default 422 ValidationException) for bad
     * credentials, so API clients can distinguish "malformed request" from
     * "wrong email/password".
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        // Hash::check on a null password would throw; short-circuit on missing user.
        // The generic message avoids leaking which emails exist (user enumeration).
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Les identifiants fournis sont incorrects.',
            ], 401);
        }

        return response()->json($this->tokenPayload($user));
    }

    /**
     * Register a new user.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users',
            'matricule' => 'required|string|unique:users',
            'password'  => 'required|string|min:8',
            'filiale_id' => 'required|uuid|exists:filiales,id',
        ]);

        $user = User::create([
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'matricule'  => $validated['matricule'],
            'password'   => Hash::make($validated['password']),
            'filiale_id' => $validated['filiale_id'],
        ]);

        return response()->json($this->tokenPayload($user), 201);
    }

    /**
     * Active authenticated user + resolved role/filiale.
     *
     * The `tenant` key is kept as an alias of `filiale` because the Angular
     * client still reads it (see AuthService.establishApiSession). Drop it once
     * the frontend has moved over.
     *
     * `client_ip` is per-*request* context rather than stored user data, which
     * is why it belongs on this endpoint and not in `tokenPayload()`: the
     * article viewer's watermark (cahier des charges §10.3) has to show the
     * address the document is being read from right now, and a session cached
     * at login time would keep showing the network the user logged in from.
     * Correctness of this value depends on the trustProxies() config in
     * bootstrap/app.php — see the note there.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('role', 'filiale');

        return response()->json([
            'user'      => $user,
            'role'      => $user->role?->name,
            'filiale'   => $user->filiale,
            'tenant'    => $user->filiale,
            'client_ip' => $request->ip(),
        ]);
    }

    /**
     * Revoke current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnexion réussie',
        ]);
    }

    /**
     * MOCK SSO — test double, never a production authentication path.
     *
     * Simulates an IdP callback that has already asserted the user's identity,
     * so QA can exercise the token flow without standing up Azure AD. Because it
     * trusts the caller-supplied email with no verification, it is refused
     * outside local/testing environments. See the class docblock for the real
     * Azure AD / SAML2 integration path.
     */
    public function ssoMock(Request $request): JsonResponse
    {
        if (!app()->environment(['local', 'testing'])) {
            return response()->json([
                'message' => 'Le SSO simulé est désactivé dans cet environnement.',
            ], 403);
        }

        $validated = $request->validate([
            'email'    => 'required|email',
            'provider' => 'nullable|string|in:azure_ad,okta,saml2',
        ]);

        // Real SSO would JIT-provision here from verified IdP claims. The mock
        // only matches pre-seeded users, so it can never invent a role/filiale.
        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return response()->json([
                'message' => 'Aucun utilisateur local ne correspond à cette identité SSO.',
            ], 404);
        }

        return response()->json(array_merge(
            $this->tokenPayload($user, 'sso_' . ($validated['provider'] ?? 'azure_ad')),
            ['sso_simulated' => true]
        ));
    }

    /**
     * Build the standard auth response, applying Sanctum's configured expiry.
     */
    private function tokenPayload(User $user, string $tokenName = 'auth_token'): array
    {
        $minutes   = config('sanctum.expiration');
        $expiresAt = $minutes ? now()->addMinutes((int) $minutes) : null;

        $token = $user->createToken($tokenName, ['*'], $expiresAt)->plainTextToken;

        return [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_at'   => $expiresAt?->toIso8601String(),
            'user'         => $user->load('role', 'filiale'),
            'filiale'      => $user->filiale,
            // Legacy alias for the Angular client — see me().
            'tenant'       => $user->filiale,
        ];
    }
}
