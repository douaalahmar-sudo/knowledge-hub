import { Component, signal } from '@angular/core';
import { RouterOutlet, RouterLink, RouterLinkActive } from '@angular/router';


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
}
