import { Injectable, inject, signal } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { AuthService } from './auth.service';

@Injectable({
    providedIn: 'root'
})
export class KaizenReportService {
    private http = inject(HttpClient);
    private authService = inject(AuthService);
    private apiUrl = 'http://127.0.0.1:8000/api/kaizen-reports';

    reports = signal<any[]>([]);

    private getHeaders(): HttpHeaders {
        return new HttpHeaders({
            Authorization: `Bearer ${this.authService.token()}`
        });
    }

    getReports(): Observable<any[]> {
        return this.http.get<any[]>(this.apiUrl, {
            headers: this.getHeaders()
        }).pipe(
            tap(reports => this.reports.set(reports))
        );
    }

    createReport(reportData: {
        procedure_id: number;
        criticality: string;
        description: string;
        process_owner_id?: number | null;
    }): Observable<any> {
        return this.http.post<any>(this.apiUrl, reportData, {
            headers: this.getHeaders()
        });
    }
}