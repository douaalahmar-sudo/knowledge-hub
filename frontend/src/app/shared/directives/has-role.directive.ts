import { Directive, Input, TemplateRef, ViewContainerRef, effect, inject } from '@angular/core';
import { AuthService, PermissionGroup } from '../../services/auth.service';

/**
 * Structural directive gating a template block by the coarse Module 4 permission
 * groups (EMPLOYEE/MANAGER/HR_ADMIN/SUPER_ADMIN) — a template-level alternative to
 * calling `auth.inGroup()` from a component. Does not affect route-level guarding
 * (see role.guard.ts) or any existing fine-grained `canSee()` checks.
 *
 * Usage: <div *appHasRole="'HR_ADMIN'">...</div> or *appHasRole="['HR_ADMIN','SUPER_ADMIN']"
 */
@Directive({
  selector: '[appHasRole]',
  standalone: true,
})
export class HasRoleDirective {
  private templateRef = inject(TemplateRef<unknown>);
  private viewContainer = inject(ViewContainerRef);
  private auth = inject(AuthService);

  private groups: PermissionGroup[] = [];
  private hasView = false;

  @Input() set appHasRole(groups: PermissionGroup | PermissionGroup[]) {
    this.groups = Array.isArray(groups) ? groups : [groups];
    this.updateView();
  }

  constructor() {
    // Re-evaluate whenever the signed-in user/role changes (e.g. after login/logout).
    effect(() => {
      this.auth.currentUser();
      this.updateView();
    });
  }

  private updateView(): void {
    const allowed = this.groups.length === 0 || this.groups.some(g => this.auth.inGroup(g));
    if (allowed && !this.hasView) {
      this.viewContainer.createEmbeddedView(this.templateRef);
      this.hasView = true;
    } else if (!allowed && this.hasView) {
      this.viewContainer.clear();
      this.hasView = false;
    }
  }
}
