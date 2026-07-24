import { Injectable, inject, signal } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { AuthService } from './auth.service';


@Injectable(
{
    providedIn: 'root'
})


export class ProcedureService
{
    private http = inject(HttpClient);
    private authService = inject(AuthService);
    private apiUrl = 'http://127.0.0.1:8000/api/procedures';
    // Global State Management via Signals
    proceduresList = signal<any[]>([]);
    currentProcedure = signal<any>(null);
    /**
    * Private helper to cleanly generate necessary Sanctum Bearer headers
    */
    private getHeaders() : HttpHeaders
    {
        return new HttpHeaders( {
            
            'Authorization': `Bearer ${this.authService.token()}`
        });
    }
    // 1. Fetch all procedures from Laravel
    getProcedures() : Observable<any[]>
    {
        return this.http.get<any[]>(this.apiUrl, {
            
            headers: this.getHeaders()
        })
        .pipe(
        tap(data => this.proceduresList.set(data))
        );
    }
    // 2. Fetch a specific procedure with its complete version history
    getProcedureById(id: number) : Observable<any>
    {
        return this.http.get<any>(`${this.apiUrl}/${id}`,
        {
            headers: this.getHeaders()
        })
        .pipe(
        tap(data => this.currentProcedure.set(data))
        );
    }
    // 3. Create a new procedure (Creates parent record + Version 1 entry simultaneously)
    createProcedure(procedureData:
    {
        reference: string;
        name: string;
        module: string;
        status?: string;
    })
    : Observable<any>
    {
        return this.http.post<any>(this.apiUrl, procedureData, {
            
            headers: this.getHeaders()
        });
    }
    uploadProcedureVersion(procedureId: number, document: File) : Observable<any>
    {
        const formData = new FormData();
        formData.append('document', document);

        return this.http.post<any>(`${this.apiUrl}/${procedureId}/versions`, formData, {
            headers: this.getHeaders()
        });
    }
    // 4. Update a procedure (Enforces Zero-Doublon by pushing a new version row)
    updateProcedureVersion(id: number, versionData:
    {
        name?: string;
        module?: string;
        status?: string;
    })
    : Observable<any>
    {
        return this.http.put<any>(`${this.apiUrl}/${id}`, versionData,
        {
            headers: this.getHeaders()
        })
        .pipe(
        tap(updatedProc =>
        {
            // Refresh local current procedure state
            this.currentProcedure.set(updatedProc);
            // Sync the change back into the main list array signal
            this.proceduresList.update(list => list.map(p => p.id === id ? updatedProc : p));
        })
        );
    }
    // 5. Delete a procedure completely
    deleteProcedure(id: number) : Observable<any>
    {
        return this.http.delete(`${this.apiUrl}/${id}`,
        {
            headers: this.getHeaders()
        })
        .pipe(
        tap(() =>
        {
            // Remove item from local state management array cleanly
            this.proceduresList.update(list => list.filter(p => p.id !== id));
            if (this.currentProcedure()?.id === id)
            {
                this.currentProcedure.set(null);
            }
        })
        );
    }
}