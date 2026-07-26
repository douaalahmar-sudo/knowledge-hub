import { Component, signal } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive, NavigationStart, Router } from '@angular/router';


@Component(
{
    selector: 'app-layout-shell',
    standalone: true,
    imports: [RouterOutlet, RouterLink, RouterLinkActive],
    templateUrl: './layout-shell.component.html',
    styleUrls: ['./layout-shell.component.scss']
})


export class LayoutShellComponent
{
    // Local display-only workspace/tenant indicator for the sidebar selector + header.
    currentTenant = signal<string>('FLESK Store #101 - Tunis');

    // Mobile/tablet sidebar: hidden by default, opened via the header hamburger.
    isMobileMenuOpen = signal(false);

    constructor(router: Router)
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

    toggleMobileMenu() : void
    {
        this.isMobileMenuOpen.update(open => !open);
    }
}
