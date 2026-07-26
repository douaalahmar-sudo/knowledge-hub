import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';




export const authGuard: CanActivateFn = (route, state) =>
{
    // ⚠️ TEMPORARY DEV OVERRIDE: Bypasses auth checks while building UI
    return true;
    /* Real Auth Check (Uncomment when Login flow is complete):
    const authService = inject(AuthService);
    const router = inject(Router);
    if (authService.token() && authService.currentTenant())
    {
        return true;
    }
    router.navigate(['/login']);
    return false;
    */
};