import { Injectable, inject } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';
import { AuthService } from '../../services/auth.service';
import { HrRequest, HrRequestStatus, HR_STATUS_META, HR_TYPE_META } from '../models/hr-request.model';
import { STORE_KEYS, lsRead, lsWrite, uid } from '../mock/local-store.util';
import { SEED_HR_REQUESTS } from '../mock/seed-data';
import { NotificationService } from './notification.service';

@Injectable({
  providedIn: 'root'
})
export class HrRequestService {
  private auth = inject(AuthService);
  private notifications = inject(NotificationService);

  /** Unfiltered store — writes must read/write this, never the tenant-filtered `all()`,
   *  or saving would silently drop every other tenant's requests from localStorage. */
  private allRaw(): HrRequest[] {
    return lsRead<HrRequest[]>(STORE_KEYS.hrRequests, SEED_HR_REQUESTS);
  }

  /** Missing `tenant` = visible to everyone (back-compat for data cached before this field existed). */
  private matchesTenant(item: { tenant?: string }): boolean {
    const tenant = this.auth.currentTenant()?.name;
    return !tenant || !item.tenant || item.tenant === tenant;
  }

  private all(): HrRequest[] {
    return this.allRaw().filter(r => this.matchesTenant(r));
  }

  /** The logged-in employee's own requests. */
  getEmployeeRequests(): Observable<any> {
    const userId = this.auth.currentUser()?.id;
    const list = this.all();
    return of(userId ? list.filter(r => r.user_id === userId) : list);
  }

  /** Every request in the current tenant (HR Admin view). */
  getAllRequests(): Observable<any> {
    return of(this.all());
  }

  /** Create from the modal's FormData payload. */
  createRequest(data: FormData): Observable<HrRequest> {
    const list = this.allRaw();
    const user = this.auth.currentUser();
    const attachments = data.getAll('attachments[]')
      .map((f: any) => (f && f.name ? f.name : String(f)))
      .filter(Boolean);

    const request: HrRequest = {
      id: uid('hr_'),
      user_id: user?.id,
      user_name: user?.name ?? 'Employé',
      tenant: this.auth.currentTenant()?.name,
      type: (data.get('type') as any) ?? 'CUSTOM',
      title: (data.get('title') as string) ?? 'Demande',
      description: (data.get('description') as string) || '',
      start_date: (data.get('start_date') as string) || null,
      end_date: (data.get('end_date') as string) || null,
      attachments,
      status: 'PENDING',
      admin_note: null,
      pdf_url: null,
      created_at: new Date().toISOString(),
    };
    lsWrite(STORE_KEYS.hrRequests, [request, ...list]);
    return of(request);
  }

  /** HR approve/reject/mark-ready + (demo) PDF placeholder. */
  updateRequestStatus(
    id: string | number,
    status: HrRequestStatus,
    admin_note?: string | null,
    pdfFile?: File | null
  ): Observable<HrRequest> {
    const list = this.allRaw();
    const idx = list.findIndex(r => String(r.id) === String(id) && this.matchesTenant(r));
    if (idx < 0) return throwError(() => ({ error: { message: 'Demande introuvable.' } }));

    list[idx] = {
      ...list[idx],
      status,
      admin_note: admin_note ?? list[idx].admin_note,
      // No real upload in the demo — mark a downloadable placeholder when a PDF is attached.
      pdf_url: pdfFile ? '#' : list[idx].pdf_url,
      updated_at: new Date().toISOString(),
    };
    lsWrite(STORE_KEYS.hrRequests, list);

    // Notify the requester their request's status changed (spec 4.3).
    const updated = list[idx];
    const statusLabel = HR_STATUS_META[status]?.label ?? status;
    this.notifications.addNotification({
      type: 'hr_request',
      title: status === 'READY_FOR_DOWNLOAD'
        ? `Votre demande "${updated.title}" est prête à télécharger`
        : `Votre demande "${updated.title}" est maintenant : ${statusLabel}`,
      message: HR_TYPE_META[updated.type]?.label,
      url: '/dashboard/hr-requests',
      userId: updated.user_id ?? null,
    });

    return of(updated);
  }
}
