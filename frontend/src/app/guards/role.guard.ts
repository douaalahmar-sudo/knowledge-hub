import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * Restricts a route to the roles listed in its `data.roles`.
 * - No token → redirect to /login.
 * - Role not allowed → redirect to /dashboard (accessible to everyone).
 * - `data.roles` absent/empty → any authenticated user may enter.
 * Admins always pass (handled by AuthService.canAccess).
 */
export const roleGuard: CanActivateFn = (route) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!localStorage.getItem('auth_token')) {
    router.navigate(['/login']);
    return false;
  }

  const allowed = route.data?.['roles'] as string[] | undefined;
  if (auth.canAccess(allowed)) {
    return true;
  }

  router.navigate(['/dashboard']);
  return false;
};
