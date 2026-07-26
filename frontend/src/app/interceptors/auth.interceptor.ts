import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../services/auth.service';




export const authInterceptor: HttpInterceptorFn = (req, next) =>
{
    const authService = inject(AuthService);
    const router = inject(Router);
    const token = authService.token();
    // If a token exists, clone the request and inject the Bearer header
    if (token)
    {
        req = req.clone(
        {
            setHeaders:
            {
                Authorization: `Bearer ${token}`
            }
        });
    }
    // Pass request down the chain and handle HTTP errors gracefully
    return next(req).pipe(
    catchError((error: HttpErrorResponse) =>
    {
        if (error.status === 401)
        {
            console.warn('Unauthorized request (401). Token missing or expired.');
            // Uncomment when a dedicated login route is active:
            // router.navigate(['/login']);
        }
        return throwError(() => error);
    })
    );
};