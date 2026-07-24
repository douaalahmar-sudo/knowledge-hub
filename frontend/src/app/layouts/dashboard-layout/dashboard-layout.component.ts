import { Component, inject } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive, Router } from '@angular/router';
import { AuthService } from '../../services/auth.service';


@Component(
{
    selector: 'app-dashboard-layout',
    standalone: true,
imports: [RouterOutlet, RouterLink, RouterLinkActive], templateUrl: './dashboard-layout.component.html',
    styleUrl: './dashboard-layout.component.scss'
})


export class DashboardLayoutComponent
{
    authService = inject(AuthService);
    private router = inject(Router);
    // Directly access the global signal state for the current logged-in user
    user = this.authService.currentUser;
    tenant = this.authService.currentTenant;


    handleLogout()
    {
        this.authService.logout().subscribe(
        {
            next: () =>
            {
                this.router.navigate(['/login']);
            },
            error: (err) =>
            {
                console.error('Logout failed:', err);
                // Fallback redirection if the token is already expired/invalid on the server
                this.router.navigate(['/login']);
            }
        });
    }
}