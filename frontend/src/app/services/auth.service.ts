import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';


@Injectable(
{
    providedIn: 'root'
})


export class AuthService
{
    private http = inject(HttpClient);
    private apiUrl = 'http://127.0.0.1:8000/api';
    // Global Signals for State Management
    currentUser = signal<any>(null);
    currentTenant = signal<any>(null);
    token = signal<string | null>(localStorage.getItem('kh_token'));

    constructor()
    {
        // If a token exists on startup, we can assume the user might be logged in
        const savedUser = localStorage.getItem('kh_user');
        const savedTenant = localStorage.getItem('kh_tenant');

        if (savedUser)
        {
            this.currentUser.set(JSON.parse(savedUser));
        }

        if (savedTenant)
        {
            this.currentTenant.set(JSON.parse(savedTenant));
        }
    }
    register(userData: any) : Observable<any>
    {
        return this.http.post(`${this.apiUrl}/register`, userData).pipe(
        tap((res: any) => this.handleAuthSuccess(res))
        );
    }
    login(credentials: any) : Observable<any>
    {
        return this.http.post(`${this.apiUrl}/login`, credentials).pipe(
        tap((res: any) => this.handleAuthSuccess(res))
        );
    }
    logout() : Observable<any>
    {
        return this.http.post(`${this.apiUrl}/logout`,
        {
        },
        {
            headers:
            {
                Authorization: `Bearer ${this.token()}`
            }
        })
        .pipe(
        tap(() =>
        {
            this.currentUser.set(null);
            this.currentTenant.set(null);
            this.token.set(null);
            localStorage.removeItem('kh_token');
            localStorage.removeItem('kh_user');
            localStorage.removeItem('kh_tenant');
        })
        );
    }
    private handleAuthSuccess(res: any)
    {
        this.currentUser.set(res.user);
        this.currentTenant.set(res.tenant ?? res.user?.tenant ?? null);
        this.token.set(res.token);
        localStorage.setItem('kh_token', res.token);
        localStorage.setItem('kh_user', JSON.stringify(res.user));
        localStorage.setItem('kh_tenant', JSON.stringify(res.tenant ?? res.user?.tenant ?? null));
    }
}