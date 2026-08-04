import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, of, throwError } from 'rxjs';
import { catchError, map, shareReplay } from 'rxjs/operators';
import { environment } from '../../environments/environment';

/**
 * Demo roles used across the frontend-only build.
 * Mirrors the 9 roles seeded in backend/database/seeders/DatabaseSeeder.php
 * exactly (same snake_case names) so the two stay consistent if a real
 * backend is wired up later.
 */
export type DemoRole =
  | 'admin'
  | 'process_owner'
  | 'expert_metier'
  | 'responsable_departement'
  | 'validator'
  | 'manager'
  | 'operator'
  | 'hr_admin'
  | 'hr_user';

/**
 * The `users.access_role` enum (App\Enums\UserRole) — the *other*, newer role
 * dimension, deliberately separate from DemoRole above (which mirrors the
 * legacy `roles` table read via `user.role.name`). A user carries both at
 * once, and they are not interchangeable: the article workflow Gates
 * (create-articles, validate-metier, validate-qualite in AppServiceProvider)
 * are all defined on `access_role`, while CheckRole and every existing route
 * guard still key off DemoRole. Use `hasAccessRole()` for the former,
 * `canAccess()`/`inGroup()` for the latter.
 */
export type AccessRole =
  | 'redacteur'
  | 'responsable_departement'
  | 'qualite'
  | 'data_owner'
  | 'admin'
  | 'lecteur';

// Real credential checking now happens server-side (POST /v1/auth/login).
// This tenant object is only still needed as the placeholder tenant register()
// assigns to a brand-new local-only signup session.
const STORE_TENANT = { name: 'FLESK Store #101 - Tunis', plan: 'premium' };

/** Display labels for the sidebar/user-footer (role keys stay snake_case internally). */
export const ROLE_LABELS: Record<DemoRole, string> = {
  admin: 'Administrateur',
  process_owner: 'Propriétaire de processus',
  expert_metier: 'Expert métier',
  responsable_departement: 'Responsable de Département',
  validator: 'Validateur (Qualité)',
  manager: 'Manager',
  operator: 'Opérateur',
  hr_admin: 'Administrateur RH',
  hr_user: 'Employé RH',
};

/**
 * Module 4 asked for a coarse 4-tier permission vocabulary (EMPLOYEE / MANAGER /
 * HR_ADMIN / SUPER_ADMIN). Per your call, these are ADDITIVE logical aliases over
 * the 9 real roles — not a replacement. Every existing route guard (kaizen,
 * hr-admin, knowledge-base authoring) keeps using its original fine-grained
 * `data.roles` list untouched. Use PermissionGroup/inGroup() only for new Module 4
 * UI (nav-section visibility, *appHasRole) that wants the coarser vocabulary.
 *
 * Grouping rationale:
 * - SUPER_ADMIN -> admin (the one role with cross-cutting access today).
 * - HR_ADMIN    -> hr_admin (unchanged 1:1 mapping).
 * - MANAGER     -> supervisory/validation-tier roles: manager, process_owner,
 *                  responsable_departement, validator.
 * - EMPLOYEE    -> individual-contributor-tier roles: expert_metier, operator, hr_user.
 */
export type PermissionGroup = 'EMPLOYEE' | 'MANAGER' | 'HR_ADMIN' | 'SUPER_ADMIN';

export const ROLE_GROUPS: Record<PermissionGroup, DemoRole[]> = {
  SUPER_ADMIN: ['admin'],
  HR_ADMIN: ['hr_admin'],
  MANAGER: ['manager', 'process_owner', 'responsable_departement', 'validator'],
  EMPLOYEE: ['expert_metier', 'operator', 'hr_user'],
};

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private http = inject(HttpClient);

  // Global reactive session state (restored from localStorage on startup).
  currentUser = signal<any>(this.readUser());
  currentTenant = signal<any>(this.readUser()?.tenant ?? null);
  token = signal<string | null>(localStorage.getItem('auth_token'));

  /** Current role key (one of the 9 DemoRole values), or null. */
  role = computed<string | null>(() => this.currentUser()?.role ?? null);

  /**
   * Current `access_role` (see AccessRole), or null. Null for a session cached
   * before this field started being persisted, and for the local-only
   * register() path which never had one — treat null as "no article-workflow
   * privileges" rather than guessing, since the server is the real authority
   * on every one of these Gates anyway.
   */
  accessRole = computed<AccessRole | null>(() => this.currentUser()?.access_role ?? null);

  /**
   * Reads the cached session, rejecting it if the role doesn't match a known
   * DemoRole (e.g. a session saved under a previous role scheme, such as the
   * old SUPER_ADMIN/HR_ADMIN/EMPLOYEE set). An unrecognized role would
   * otherwise silently break `canAccess`/`roleLabel` — self-heal by clearing
   * the stale session so the guard sends the user back to /login instead.
   */
  private readUser(): any {
    try {
      const user = JSON.parse(localStorage.getItem('current_user') || 'null');
      if (user && !(user.role in ROLE_LABELS)) {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('current_user');
        return null;
      }
      return user;
    } catch {
      return null;
    }
  }

  /**
   * One-shot repair for sessions cached before `access_role` started being
   * persisted (see establishApiSession). Those users hold a perfectly valid
   * token but a session object with no access_role, which reads as "no
   * article-workflow privileges" and silently hides the validation queue and
   * its actions until they log out and back in.
   *
   * Called once at bootstrap from app.config.ts. Deliberately NOT blocking:
   * the initializer subscribes and returns immediately, so a slow or dead API
   * can't hold up the first paint. `accessRole` is a signal, so the affected
   * UI (the sidebar entry, the per-row action buttons) simply appears when the
   * response lands.
   *
   * Fires at most one request per app load, and only when there is something
   * to repair — see the three guards below.
   */
  backfillAccessRole(): void {
    const user = this.currentUser();

    // No session to repair: logged out, or a token with no cached user.
    if (!this.token() || !user) return;

    // THE redundancy guard: any session established since access_role started
    // being persisted already carries it, so a normal login is followed by
    // zero /me traffic. The column is NOT NULL with a 'lecteur' default
    // server-side, so a present value is never empty — truthiness is a safe
    // test for "already backfilled".
    if (user.access_role) return;

    this.http.get<{ user: any }>(`${environment.apiUrl}/v1/auth/me`).subscribe({
      next: payload => {
        const accessRole = (payload?.user?.access_role ?? null) as AccessRole | null;
        if (!accessRole) return;

        // Merge rather than replace: this only owns the one missing field, and
        // must not clobber `tenant`/`matricule`/anything else the live session
        // is already carrying.
        const merged = { ...this.currentUser(), access_role: accessRole };

        localStorage.setItem('current_user', JSON.stringify(merged));
        this.currentUser.set(merged);
      },
      // Best-effort repair, not an auth check — swallow. A 401 here means the
      // token is dead, but every guarded request the user makes will hit the
      // same 401 and the auth guard handles that; tearing down the session
      // from a background bootstrap call would turn a cosmetic gap into a
      // surprise logout on a transient network blip.
      error: () => {},
    });
  }

  /**
   * The IP address the API sees this client connecting from, per
   * GET /v1/auth/me's `client_ip`.
   *
   * Deliberately fetched on demand rather than cached onto the session at
   * login: it is request context, not user data, and a user who logs in at the
   * office and reads a document later from home must be watermarked with the
   * address they are actually reading from (spec §10.3).
   *
   * Never throws and never nulls out the session — the caller only needs this
   * for display, so a failure degrades to `null` and lets the UI show its own
   * placeholder. Deriving it client-side is not an option: a browser cannot see
   * its own public IP, only the server can report it.
   */
  fetchClientIp(): Observable<string | null> {
    return this.http.get<{ client_ip?: string | null }>(`${environment.apiUrl}/v1/auth/me`).pipe(
      map(payload => payload?.client_ip ?? null),
      catchError(() => of(null))
    );
  }

  /** Memoised {@see fetchClientIp} result, shared by every subscriber. */
  private clientIpOnce$?: Observable<string | null>;

  /**
   * Same value as fetchClientIp(), but the request is issued once per session
   * and replayed to later subscribers.
   *
   * This exists because the watermark is now per *viewer frame*: an article
   * with a PDF, an infographic and a video renders three overlays, and each one
   * asking the API where the client is connecting from would mean three
   * identical /v1/auth/me calls to paint one unchanging string. The address
   * cannot change mid-session in any way that matters to a watermark.
   *
   * refCount stays false on purpose — navigating away from the last viewer and
   * back must not re-issue the call.
   */
  clientIpOnce(): Observable<string | null> {
    return (this.clientIpOnce$ ??= this.fetchClientIp().pipe(
      shareReplay({ bufferSize: 1, refCount: false })
    ));
  }

  /**
   * Authenticates against the real Laravel backend (POST /v1/auth/login) and
   * establishes a session from the Sanctum token it returns.
   *
   * This used to be a purely local lookup against a hardcoded account list —
   * no backend involved. The 9 demo accounts are seeded server-side with the
   * exact same emails/passwords (see backend/database/seeders/DatabaseSeeder.php),
   * so existing demo logins keep working, but every subsequent request now
   * carries a real Sanctum bearer token instead of a fake `demo-token-<ts>`
   * that the API would reject with 401.
   */
  login(credentials: { email: string; password: string }): Observable<any> {
    const email = (credentials?.email || '').trim().toLowerCase();

    return this.http
      .post<any>(`${environment.apiUrl}/v1/auth/login`, { email, password: credentials?.password })
      .pipe(
        map(response => this.establishApiSession(response)),
        catchError((err: HttpErrorResponse) => {
          const message =
            err.status === 0
              ? 'Serveur injoignable. Vérifiez que l’API est bien démarrée.'
              : err.error?.message || 'Identifiants invalides.';
          return throwError(() => ({ error: { message } }));
        })
      );
  }

  /**
   * Demo registration: creates a self-registered session on the fly.
   * Defaults to `hr_user` (the HR self-service role) as the closest fit for a
   * walk-up signup — flagging this as a judgment call, not a spec'd behavior.
   */
  register(userData: any): Observable<any> {
    const account = {
      id: Date.now(),
      name: userData?.name || 'Nouvel employé',
      email: (userData?.email || 'demo@flesk.com').toLowerCase(),
      role: 'hr_user' as DemoRole,
      tenant: STORE_TENANT,
    };
    return of(this.establishSession(account));
  }

  logout(): Observable<any> {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('current_user');
    this.token.set(null);
    this.currentUser.set(null);
    this.currentTenant.set(null);
    return of({ message: 'Déconnexion réussie' });
  }

  /**
   * Whether the current role may access something restricted to `allowedRoles`.
   * `admin` always passes (frontend-only convenience — the backend Gates do NOT
   * grant admin an automatic bypass, they list it explicitly per-gate); an
   * empty/absent list means "any authenticated user".
   */
  canAccess(allowedRoles: string[] | undefined | null): boolean {
    if (!allowedRoles || allowedRoles.length === 0) return true;
    const r = this.role();
    return r === 'admin' || (!!r && allowedRoles.includes(r));
  }

  /**
   * Whether the current `access_role` is one of `allowedRoles` — the
   * access_role counterpart of canAccess(), for UI gated on the article
   * workflow Gates. `admin` always passes, matching how every one of those
   * Gates lists 'admin' explicitly server-side.
   *
   * This is UI affordance only: hiding a button never authorizes anything, and
   * ArticleController re-checks each Gate on its own.
   */
  hasAccessRole(allowedRoles: AccessRole[]): boolean {
    const r = this.accessRole();
    if (!r) return false;
    return r === 'admin' || allowedRoles.includes(r);
  }

  /**
   * Whether the current role belongs to the given coarse permission group
   * (see PermissionGroup/ROLE_GROUPS above). `admin` always passes, mirroring
   * the same bypass semantics as canAccess().
   */
  inGroup(group: PermissionGroup): boolean {
    const r = this.role() as DemoRole | null;
    if (!r) return false;
    if (r === 'admin') return true;
    return ROLE_GROUPS[group].includes(r);
  }

  /** Local, no-backend session — still used by register()'s walk-up signup. */
  private establishSession(account: { id: number; name: string; email: string; role: DemoRole; tenant: any }): any {
    const user = {
      id: account.id,
      name: account.name,
      email: account.email,
      role: account.role,
      tenant: account.tenant,
    };
    const token = 'demo-token-' + Date.now();

    localStorage.setItem('auth_token', token);
    localStorage.setItem('current_user', JSON.stringify(user));

    this.token.set(token);
    this.currentUser.set(user);
    this.currentTenant.set(user.tenant);

    return { user, token, tenant: user.tenant };
  }

  /**
   * Real-backend session — maps AuthController::tokenPayload()'s shape
   * ({ access_token, user: { ...role: {name}, tenant: {...} }, tenant }) onto
   * the same flat { id, name, email, role: string, tenant } shape the rest of
   * the app (canAccess, inGroup, sidebar, tenant banner…) already expects.
   */
  private establishApiSession(payload: { access_token: string; user: any; tenant: any }): any {
    const roleName: string | null = payload.user?.role?.name ?? null;
    const tenant = payload.tenant ?? payload.user?.tenant ?? null;

    const user = {
      id: payload.user.id,
      name: payload.user.name,
      email: payload.user.email,
      // The API has always returned this (users.matricule, no $hidden on the
      // model); it was simply being dropped here. Kept because the article
      // viewer's watermark identifies the reader by employee id — see
      // ArticleWorkflowDetailComponent.watermarkText.
      matricule: payload.user.matricule ?? null,
      role: roleName,
      // The second, independent role dimension (see AccessRole). Also always
      // returned by the API — `access_role` is fillable and not $hidden on the
      // User model, and the column is NOT NULL with a 'lecteur' default — it
      // was simply being dropped here, same as matricule was. The article
      // workflow UI reads it via accessRole()/hasAccessRole().
      access_role: (payload.user.access_role ?? null) as AccessRole | null,
      tenant,
    };

    localStorage.setItem('auth_token', payload.access_token);
    localStorage.setItem('current_user', JSON.stringify(user));

    this.token.set(payload.access_token);
    this.currentUser.set(user);
    this.currentTenant.set(tenant);

    return { user, token: payload.access_token, tenant };
  }
}
