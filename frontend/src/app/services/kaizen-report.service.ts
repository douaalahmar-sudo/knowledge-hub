import { Injectable, inject, signal } from '@angular/core';
import { Observable, defer, of } from 'rxjs';
import { AuthService } from './auth.service';
import { STORE_KEYS, lsRead, lsWrite } from '../core/mock/local-store.util';
import { SEED_KAIZEN, SEED_PROCEDURES } from '../core/mock/seed-data';
import { NotificationService } from '../core/services/notification.service';

@Injectable({
  providedIn: 'root'
})
export class KaizenReportService {
  private auth = inject(AuthService);
  private notifications = inject(NotificationService);

  reports = signal<any[]>([]);

  /** Unfiltered store — writes must read/write this, never the tenant-filtered `all()`,
   *  or saving would silently drop every other tenant's Kaizen reports from localStorage. */
  private allRaw(): any[] {
    return lsRead<any[]>(STORE_KEYS.kaizen, SEED_KAIZEN);
  }

  /** Missing `tenant` = visible to everyone (back-compat for data cached before this field existed). */
  private matchesTenant(item: { tenant?: string }): boolean {
    const tenant = this.auth.currentTenant()?.name;
    return !tenant || !item.tenant || item.tenant === tenant;
  }

  private all(): any[] {
    return this.allRaw().filter(k => this.matchesTenant(k));
  }

  // `defer` keeps the store read inside the observable so a storage failure
  // surfaces as an error notification rather than a synchronous throw.
  getReports(): Observable<any> {
    return defer(() => {
      const list = this.all();
      this.reports.set(list);
      return of(list);
    });
  }

  /** Create a Kaizen signal. Persists to the store; the caller prepends to the signal. */
  createReport(reportData: {
    procedure_id: number;
    criticality: string;
    description: string;
    type?: string;
    process_owner_id?: number | null;
  }): Observable<any> {
    const list = this.allRaw();
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
      tenant: this.auth.currentTenant()?.name,
      created_at: new Date().toISOString(),
    };
    lsWrite(STORE_KEYS.kaizen, [report, ...list]);

    // Broadcast to whoever has Kaizen access — no single owner for a new signal (spec 4.3).
    this.notifications.addNotification({
      type: 'kaizen',
      title: 'Nouveau rapport Kaizen signalé',
      message: report.description?.slice(0, 120),
      url: '/dashboard/kaizen',
    });

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
