import { Injectable, inject, signal } from '@angular/core';
import { Observable, of } from 'rxjs';
import { AuthService } from './auth.service';
import { STORE_KEYS, lsRead, lsWrite } from '../core/mock/local-store.util';
import { SEED_KAIZEN, SEED_PROCEDURES } from '../core/mock/seed-data';

@Injectable({
  providedIn: 'root'
})
export class KaizenReportService {
  private auth = inject(AuthService);

  reports = signal<any[]>([]);

  private all(): any[] {
    return lsRead<any[]>(STORE_KEYS.kaizen, SEED_KAIZEN);
  }

  getReports(): Observable<any> {
    const list = this.all();
    this.reports.set(list);
    return of(list);
  }

  /** Create a Kaizen signal. Persists to the store; the caller prepends to the signal. */
  createReport(reportData: {
    procedure_id: number;
    criticality: string;
    description: string;
    type?: string;
    process_owner_id?: number | null;
  }): Observable<any> {
    const list = this.all();
    const procs = lsRead<any[]>(STORE_KEYS.procedures, SEED_PROCEDURES);
    const proc = procs.find(p => p.id == reportData.procedure_id);

    const report = {
      id: Date.now(),
      procedure_id: reportData.procedure_id,
      procedure: proc
        ? { title: proc.title || proc.name, version: proc.version || 'v1.0' }
        : { title: 'Procédure', version: 'v1.0' },
      submitter: { name: this.auth.currentUser()?.name || 'Utilisateur' },
      type: reportData.type || 'erreur_metier',
      criticality: reportData.criticality || 'moyenne',
      status: 'open',
      description: reportData.description,
      sla_due_at: this.computeSla(reportData.criticality),
      created_at: new Date().toISOString(),
    };
    lsWrite(STORE_KEYS.kaizen, [report, ...list]);
    return of(report);
  }

  /** SLA deadline: 24h critique, 72h moyenne, 7j faible. */
  private computeSla(criticality: string): string {
    const hours = criticality === 'critique' || criticality === 'critical' ? 24
      : criticality === 'faible' || criticality === 'low' ? 24 * 7
      : 72;
    return new Date(Date.now() + hours * 3600 * 1000).toISOString();
  }
}
