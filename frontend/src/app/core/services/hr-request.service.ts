import { Injectable, inject } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';
import { AuthService } from '../../services/auth.service';
import { HrRequest, HrRequestStatus } from '../models/hr-request.model';
import { STORE_KEYS, lsRead, lsWrite, uid } from '../mock/local-store.util';
import { SEED_HR_REQUESTS } from '../mock/seed-data';

@Injectable({
  providedIn: 'root'
})
export class HrRequestService {
  private auth = inject(AuthService);

  private all(): HrRequest[] {
    return lsRead<HrRequest[]>(STORE_KEYS.hrRequests, SEED_HR_REQUESTS);
  }

  /** The logged-in employee's own requests. */
  getEmployeeRequests(): Observable<any> {
    const userId = this.auth.currentUser()?.id;
    const list = this.all();
    return of(userId ? list.filter(r => r.user_id === userId) : list);
  }

  /** Every request (HR Admin view). */
  getAllRequests(): Observable<any> {
    return of(this.all());
  }

  /** Create from the modal's FormData payload. */
  createRequest(data: FormData): Observable<HrRequest> {
    const list = this.all();
    const user = this.auth.currentUser();
    const attachments = data.getAll('attachments[]')
      .map((f: any) => (f && f.name ? f.name : String(f)))
      .filter(Boolean);

    const request: HrRequest = {
      id: uid('hr_'),
      user_id: user?.id,
      user_name: user?.name ?? 'Employé',
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
    const list = this.all();
    const idx = list.findIndex(r => String(r.id) === String(id));
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
    return of(list[idx]);
  }
}
