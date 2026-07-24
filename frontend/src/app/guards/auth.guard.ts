import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

export const authGuard: CanActivateFn = (route, state) => {
  const authService = inject(AuthService);
  const router = inject(Router);

  // If the signal contains a token and a tenant context, allow access to the protected route
  if (authService.token() && authService.currentTenant()) {
    return true;
  }

  // Otherwise, kick the user back to the login view
  router.navigate(['/login']);
  return false;
};