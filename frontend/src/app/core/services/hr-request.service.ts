import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { HrRequest, HrRequestStatus } from '../models/hr-request.model';

@Injectable({
  providedIn: 'root'
})
export class HrRequestService {
  private http = inject(HttpClient);
  // Auth token is attached automatically by the authInterceptor.
  private apiUrl = `${environment.apiUrl}/v1/hr-requests`;

  /** Requests submitted by the currently logged-in employee. */
  getEmployeeRequests(): Observable<any> {
    return this.http.get<any>(`${this.apiUrl}/mine`);
  }

  /** All requests across the tenant/store (HR Admin view). */
  getAllRequests(): Observable<any> {
    return this.http.get<any>(this.apiUrl);
  }

  /** Create a new document/leave request (multipart — supports file attachments). */
  createRequest(data: FormData): Observable<HrRequest> {
    return this.http.post<HrRequest>(this.apiUrl, data);
  }

  /**
   * HR approve/reject/mark-ready + optional processed PDF upload.
   * Sent as multipart POST with a _method override (PHP can't parse multipart PUT).
   */
  updateRequestStatus(
    id: string | number,
    status: HrRequestStatus,
    admin_note?: string | null,
    pdfFile?: File | null
  ): Observable<HrRequest> {
    const fd = new FormData();
    fd.append('_method', 'PUT');
    fd.append('status', status);
    if (admin_note != null) fd.append('admin_note', admin_note);
    if (pdfFile) fd.append('pdf', pdfFile);
    return this.http.post<HrRequest>(`${this.apiUrl}/${id}`, fd);
  }
}
