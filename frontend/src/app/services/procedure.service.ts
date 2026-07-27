import { Injectable, signal } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';
import { STORE_KEYS, lsRead, lsWrite } from '../core/mock/local-store.util';
import { SEED_PROCEDURES } from '../core/mock/seed-data';

@Injectable({
  providedIn: 'root'
})
export class ProcedureService {
  // Global state signals consumed directly by the procedures/kaizen pages.
  proceduresList = signal<any[]>([]);
  currentProcedure = signal<any>(null);

  private all(): any[] {
    return lsRead<any[]>(STORE_KEYS.procedures, SEED_PROCEDURES);
  }

  // 1. Fetch all procedures.
  getProcedures(): Observable<any[]> {
    const list = this.all();
    this.proceduresList.set(list);
    return of(list);
  }

  // 2. Fetch a specific procedure.
  getProcedureById(id: number): Observable<any> {
    const found = this.all().find(p => p.id === id) ?? null;
    this.currentProcedure.set(found);
    return of(found);
  }

  // 3. Create a new procedure. Persists to the store; the caller prepends to the signal.
  createProcedure(data: { reference: string; name: string; module: string; status?: string }): Observable<any> {
    const list = this.all();
    const procedure = {
      id: Date.now(),
      reference: data.reference,
      name: data.name,
      title: data.name,
      module: data.module,
      version: 'v1.0',
      status: data.status || 'En attente',
      created_at: new Date().toISOString(),
    };
    lsWrite(STORE_KEYS.procedures, [...list, procedure]);
    return of(procedure);
  }

  // 4. Upload a new version — demo no-op that resolves successfully.
  uploadProcedureVersion(procedureId: number, _document: File): Observable<any> {
    const list = this.all();
    const idx = list.findIndex(p => p.id === procedureId);
    if (idx >= 0) {
      const [major, minor] = String(list[idx].version || 'v1.0').replace('v', '').split('.').map(Number);
      list[idx] = { ...list[idx], version: `v${major}.${(minor || 0) + 1}` };
      lsWrite(STORE_KEYS.procedures, list);
      this.proceduresList.set(list);
    }
    return of({ message: 'Version importée (démo).', data: list[idx] });
  }

  // 5. Update a procedure.
  updateProcedureVersion(id: number, versionData: { name?: string; module?: string; status?: string }): Observable<any> {
    const list = this.all();
    const idx = list.findIndex(p => p.id === id);
    if (idx < 0) return throwError(() => ({ error: { message: 'Procédure introuvable.' } }));
    list[idx] = { ...list[idx], ...versionData };
    lsWrite(STORE_KEYS.procedures, list);
    this.proceduresList.set(list);
    this.currentProcedure.set(list[idx]);
    return of(list[idx]);
  }

  // 6. Delete a procedure.
  deleteProcedure(id: number): Observable<any> {
    const list = this.all().filter(p => p.id !== id);
    lsWrite(STORE_KEYS.procedures, list);
    this.proceduresList.set(list);
    if (this.currentProcedure()?.id === id) this.currentProcedure.set(null);
    return of({ message: 'Procédure supprimée.' });
  }
}
