import { Injectable, inject, signal } from '@angular/core';
import { Observable, defer, of, throwError } from 'rxjs';
import { STORE_KEYS, lsRead, lsWrite } from '../core/mock/local-store.util';
import { SEED_PROCEDURES } from '../core/mock/seed-data';
import { AuthService } from './auth.service';

@Injectable({
  providedIn: 'root'
})
export class ProcedureService {
  private auth = inject(AuthService);

  // Global state signals consumed directly by the procedures/kaizen pages.
  proceduresList = signal<any[]>([]);
  currentProcedure = signal<any>(null);

  /** Unfiltered store — writes must read/write this, never the tenant-filtered `all()`,
   *  or saving would silently drop every other tenant's procedures from localStorage. */
  private allRaw(): any[] {
    return lsRead<any[]>(STORE_KEYS.procedures, SEED_PROCEDURES);
  }

  /** Missing `tenant` = visible to everyone (back-compat for data cached before this field existed). */
  private matchesTenant(item: { tenant?: string }): boolean {
    const tenant = this.auth.currentTenant()?.name;
    return !tenant || !item.tenant || item.tenant === tenant;
  }

  private all(): any[] {
    return this.allRaw().filter(p => this.matchesTenant(p));
  }

  // 1. Fetch all procedures.
  // `defer` keeps the store read INSIDE the observable: if it throws, subscribers
  // receive an error notification they can handle, instead of a synchronous
  // exception escaping past `.subscribe({ error })` and stranding loading flags.
  getProcedures(): Observable<any[]> {
    return defer(() => {
      const list = this.all();
      this.proceduresList.set(list);
      return of(list);
    });
  }

  // 2. Fetch a specific procedure.
  getProcedureById(id: number): Observable<any> {
    return defer(() => {
      const found = this.all().find(p => p.id === id) ?? null;
      this.currentProcedure.set(found);
      return of(found);
    });
  }

  // 3. Create a new procedure. Persists to the store; the caller prepends to the signal.
  createProcedure(data: { reference: string; name: string; module: string; status?: string }): Observable<any> {
    const list = this.allRaw();
    const procedure = {
      id: Date.now(),
      reference: data.reference,
      name: data.name,
      title: data.name,
      module: data.module,
      version: 'v1.0',
      status: data.status || 'En attente',
      tenant: this.auth.currentTenant()?.name,
      created_at: new Date().toISOString(),
    };
    lsWrite(STORE_KEYS.procedures, [...list, procedure]);
    return of(procedure);
  }

  // 4. Upload a new version — demo no-op that resolves successfully.
  uploadProcedureVersion(procedureId: number, _document: File): Observable<any> {
    const list = this.allRaw();
    const idx = list.findIndex(p => p.id === procedureId && this.matchesTenant(p));
    if (idx >= 0) {
      const [major, minor] = String(list[idx].version || 'v1.0').replace('v', '').split('.').map(Number);
      list[idx] = { ...list[idx], version: `v${major}.${(minor || 0) + 1}` };
      lsWrite(STORE_KEYS.procedures, list);
      this.proceduresList.set(list.filter(p => this.matchesTenant(p)));
    }
    return of({ message: 'Version importée (démo).', data: list[idx] });
  }

  // 5. Update a procedure.
  updateProcedureVersion(id: number, versionData: { name?: string; module?: string; status?: string }): Observable<any> {
    const list = this.allRaw();
    const idx = list.findIndex(p => p.id === id && this.matchesTenant(p));
    if (idx < 0) return throwError(() => ({ error: { message: 'Procédure introuvable.' } }));
    list[idx] = { ...list[idx], ...versionData };
    lsWrite(STORE_KEYS.procedures, list);
    this.proceduresList.set(list.filter(p => this.matchesTenant(p)));
    this.currentProcedure.set(list[idx]);
    return of(list[idx]);
  }

  // 6. Delete a procedure.
  deleteProcedure(id: number): Observable<any> {
    const list = this.allRaw().filter(p => !(p.id === id && this.matchesTenant(p)));
    lsWrite(STORE_KEYS.procedures, list);
    this.proceduresList.set(list.filter(p => this.matchesTenant(p)));
    if (this.currentProcedure()?.id === id) this.currentProcedure.set(null);
    return of({ message: 'Procédure supprimée.' });
  }
}
