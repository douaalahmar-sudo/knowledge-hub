import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, of } from 'rxjs';
import { catchError, tap } from 'rxjs/operators';
import { Procedure, KaizenSignal, PersonnelUser, MOCK_PROCEDURES, MOCK_KAIZENS } from '../models/workspace.models';


@Injectable(
{
    providedIn: 'root'
})


export class WorkspaceService
{
    private http = inject(HttpClient);
    private apiUrl = 'https://api.yourdomain.com/api/v1'; // Replace with your backend URL (e.g., Laravel / Node API)
    // Signals for global app state
    procedures = signal<Procedure[]>(MOCK_PROCEDURES);
    kaizens = signal<KaizenSignal[]>(MOCK_KAIZENS);
    // --- API Methods ---
    fetchProcedures(tenantId: string) : Observable<Procedure[]>
    {
        return this.http.get<Procedure[]>(`${this.apiUrl}/tenants/${tenantId}/procedures`).pipe(
        tap(data => this.procedures.set(data)),
        catchError(() => of(MOCK_PROCEDURES)) // Fallback to mock data if API is offline
        );
    }
    fetchKaizens(tenantId: string) : Observable<KaizenSignal[]>
    {
        return this.http.get<KaizenSignal[]>(`${this.apiUrl}/tenants/${tenantId}/kaizens`).pipe(
        tap(data => this.kaizens.set(data)),
        catchError(() => of(MOCK_KAIZENS))
        );
    }
    createKaizen(tenantId: string, payload: Partial<KaizenSignal>) : Observable<KaizenSignal>
    {
        return this.http.post<KaizenSignal>(`${this.apiUrl}/tenants/${tenantId}/kaizens`, payload).pipe(
        tap(newSignal =>
        {
            this.kaizens.update(list => [newSignal, ...list]);
        })
        );
    }
}