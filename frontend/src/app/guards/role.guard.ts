import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService, PermissionGroup } from '../services/auth.service';

/**
 * Restricts a route by its `data.roles` (fine-grained, one of the 9 real roles)
 * and/or its `data.roleGroups` (coarse Module 4 vocabulary: EMPLOYEE/MANAGER/
 * HR_ADMIN/SUPER_ADMIN). Both are optional and additive — a route can set
 * either, both, or neither:
 * - No token → redirect to /login.
 * - Neither constraint set → any authenticated user may enter.
 * - Only one constraint set → that one alone gates access (the other defaults
 *   to "pass"), so every existing route using only `data.roles` is unaffected.
 * - Both set → the route must pass BOTH.
 * Admins always pass (handled by AuthService.canAccess / inGroup).
 */
export const roleGuard: CanActivateFn = (route) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (!localStorage.getItem('auth_token')) {
    router.navigate(['/login']);
    return false;
  }

  const allowedRoles = route.data?.['roles'] as string[] | undefined;
  const allowedGroups = route.data?.['roleGroups'] as PermissionGroup[] | undefined;

  const passesRoles = auth.canAccess(allowedRoles);
  const passesGroups = !allowedGroups || allowedGroups.length === 0 || allowedGroups.some(g => auth.inGroup(g));

  if (passesRoles && passesGroups) {
    return true;
  }

  router.navigate(['/dashboard']);
  return false;
};
