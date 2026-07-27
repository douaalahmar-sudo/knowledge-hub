import { Injectable, computed, signal } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';

/** Demo roles used across the frontend-only build. */
export type DemoRole = 'SUPER_ADMIN' | 'HR_ADMIN' | 'EMPLOYEE';

interface DemoAccount {
  id: number;
  name: string;
  email: string;
  password: string;
  role: DemoRole;
  tenant: { name: string; plan: string };
}

const TENANT = { name: 'FLESK Store #101 - Tunis', plan: 'premium' };

/** Hard-coded demo credentials — no backend required. Password for all: 123 */
const DEMO_ACCOUNTS: DemoAccount[] = [
  { id: 1, name: 'Sami Ben Ali',  email: 'admin@flesk.tn',    password: '123', role: 'SUPER_ADMIN', tenant: TENANT },
  { id: 2, name: 'Fatma Gharbi',  email: 'hr@flesk.tn',       password: '123', role: 'HR_ADMIN',    tenant: TENANT },
  { id: 3, name: 'Youssef Nasri', email: 'employee@flesk.tn', password: '123', role: 'EMPLOYEE',    tenant: TENANT },
];

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  // Global reactive session state (restored from localStorage on startup).
  currentUser = signal<any>(this.readUser());
  currentTenant = signal<any>(this.readUser()?.tenant ?? null);
  token = signal<string | null>(localStorage.getItem('auth_token'));

  /** Current role key (SUPER_ADMIN | HR_ADMIN | EMPLOYEE), or null. */
  role = computed<string | null>(() => this.currentUser()?.role ?? null);

  private readUser(): any {
    try {
      return JSON.parse(localStorage.getItem('current_user') || 'null');
    } catch {
      return null;
    }
  }

  /** Local credential lookup — returns { user, token } or errors. */
  login(credentials: { email: string; password: string }): Observable<any> {
    const email = (credentials?.email || '').trim().toLowerCase();
    const match = DEMO_ACCOUNTS.find(a => a.email === email && a.password === credentials?.password);

    if (!match) {
      return throwError(() => ({ error: { message: 'Identifiants invalides. (Astuce : mot de passe = 123)' } }));
    }
    return of(this.establishSession(match));
  }

  /** Demo registration: creates an EMPLOYEE session on the fly. */
  register(userData: any): Observable<any> {
    const account = {
      id: Date.now(),
      name: userData?.name || 'Nouvel employé',
      email: (userData?.email || 'demo@flesk.tn').toLowerCase(),
      role: 'EMPLOYEE' as DemoRole,
      tenant: TENANT,
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
   * SUPER_ADMIN always passes; an empty/absent list means "any authenticated user".
   */
  canAccess(allowedRoles: string[] | undefined | null): boolean {
    if (!allowedRoles || allowedRoles.length === 0) return true;
    const r = this.role();
    return r === 'SUPER_ADMIN' || (!!r && allowedRoles.includes(r));
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
