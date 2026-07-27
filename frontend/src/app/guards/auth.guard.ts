import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

/**
 * Session guard: allow only when a demo session token exists in localStorage.
 * Redirects to /login otherwise.
 */
export const authGuard: CanActivateFn = () => {
  const router = inject(Router);

  if (localStorage.getItem('auth_token')) {
    return true;
  }

  router.navigate(['/login']);
  return false;
};
