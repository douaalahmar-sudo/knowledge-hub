import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Observable, of, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
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
