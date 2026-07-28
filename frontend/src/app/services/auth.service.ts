import { Injectable, computed, signal } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';

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

interface DemoAccount {
  id: number;
  name: string;
  email: string;
  password: string;
  role: DemoRole;
  tenant: { name: string; plan: string };
}

const STORE_TENANT = { name: 'FLESK Store #101 - Tunis', plan: 'premium' };
// `responsable_departement` is seeded tenant-agnostic (HQ), matching the backend
// choice — see DatabaseSeeder.php. This is cosmetic here since the frontend mock
// stores don't actually filter by tenant, but it keeps the two layers consistent.
const HQ_TENANT = { name: 'FLESK HQ & Services Centraux', plan: 'premium' };

/** Hard-coded demo credentials — no backend required. Password for all: password123 */
const DEMO_ACCOUNTS: DemoAccount[] = [
  { id: 1, name: 'Sami Ben Ali',    email: 'admin@flesk.com',        password: 'password123', role: 'admin',                   tenant: STORE_TENANT },
  { id: 2, name: 'Amina Mansour',   email: 'owner@flesk.com',        password: 'password123', role: 'process_owner',           tenant: STORE_TENANT },
  { id: 3, name: 'Nizar Haddad',    email: 'expert@flesk.com',       password: 'password123', role: 'expert_metier',           tenant: STORE_TENANT },
  { id: 4, name: 'Rania Sassi',     email: 'dept.manager@flesk.com', password: 'password123', role: 'responsable_departement', tenant: HQ_TENANT },
  { id: 5, name: 'Leila Trabelsi',  email: 'validator@flesk.com',    password: 'password123', role: 'validator',               tenant: STORE_TENANT },
  { id: 6, name: 'Karim Bouazizi',  email: 'manager@flesk.com',      password: 'password123', role: 'manager',                 tenant: STORE_TENANT },
  { id: 7, name: 'Karim Zouari',    email: 'operator@flesk.com',     password: 'password123', role: 'operator',                tenant: STORE_TENANT },
  { id: 8, name: 'Fatma Gharbi',    email: 'hr.admin@flesk.com',     password: 'password123', role: 'hr_admin',                tenant: STORE_TENANT },
  { id: 9, name: 'Youssef Nasri',   email: 'hr.user@flesk.com',      password: 'password123', role: 'hr_user',                 tenant: STORE_TENANT },
];

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

  /** Local credential lookup — returns { user, token } or errors. */
  login(credentials: { email: string; password: string }): Observable<any> {
    const email = (credentials?.email || '').trim().toLowerCase();
    const match = DEMO_ACCOUNTS.find(a => a.email === email && a.password === credentials?.password);

    if (!match) {
      return throwError(() => ({ error: { message: 'Identifiants invalides. (Astuce : mot de passe = password123)' } }));
    }
    return of(this.establishSession(match));
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
}
