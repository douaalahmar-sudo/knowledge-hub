import { Component, computed, inject, signal } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive, NavigationStart, Router } from '@angular/router';
import { GlobalSearchComponent } from '../../shared/components/global-search/global-search.component';
import { NotificationCenterComponent } from '../../shared/components/notification-center/notification-center.component';
import { AuthService, ROLE_LABELS, DemoRole } from '../../services/auth.service';


@Component(
{
    selector: 'app-layout-shell',
    standalone: true,
    imports: [RouterOutlet, RouterLink, RouterLinkActive, GlobalSearchComponent, NotificationCenterComponent],
    templateUrl: './layout-shell.component.html',
    styleUrls: ['./layout-shell.component.scss']
})


export class LayoutShellComponent
{
    private auth = inject(AuthService);

    // Signed-in user + their tenant/role for the sidebar footer.
    user = this.auth.currentUser;
    role = this.auth.role;
    tenantName = computed<string>(() => this.auth.currentTenant()?.name ?? 'FLESK Store #101 - Tunis');
    userName = computed<string>(() => this.auth.currentUser()?.name ?? 'Utilisateur');
    roleLabel = computed<string>(() => {
        const r = this.auth.role() as DemoRole | null;
        return (r && ROLE_LABELS[r]) ?? 'Invité';
    });
    initials = computed<string>(() =>
        this.userName().split(' ').map(p => p[0] ?? '').join('').slice(0, 2).toUpperCase() || 'U'
    );

    // Mobile/tablet sidebar: hidden by default, opened via the header hamburger.
    isMobileMenuOpen = signal(false);

    constructor(private router: Router)
    {
        // Auto-close the drawer whenever a navigation is triggered (link click, back/forward...).
        router.events.subscribe(event =>
        {
            if (event instanceof NavigationStart)
            {
                this.isMobileMenuOpen.set(false);
            }
        });
    }

    /** Whether the current role may see a nav item restricted to `roles`. */
    canSee(roles: string[]): boolean
    {
        return this.auth.canAccess(roles);
    }

    handleLogout(): void
    {
        this.auth.logout().subscribe({
            next: () => this.router.navigate(['/login']),
            error: () => this.router.navigate(['/login'])
        });
    }

    toggleMobileMenu() : void
    {
        this.isMobileMenuOpen.update(open => !open);
    }
}
