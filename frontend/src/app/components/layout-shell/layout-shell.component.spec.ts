import { Component, signal } from '@angular/common';
import { CommonModule } from '@angular/common';


@Component(
{
    selector: 'app-layout-shell',
    standalone: true,
imports: [CommonModule], templateUrl: './layout-shell.component.html',
    styleUrl: './layout-shell.component.css'
})


export class LayoutShellComponent
{
    // Navigation State
    activeNav = signal<'dashboard' | 'procedures' | 'kaizen' | 'personnel'>('dashboard');
    // Right Panel Drawer State
    isRightDrawerOpen = signal<boolean>(true);
    // Mock Active Tenant State
    currentTenant = signal<string>('FLESK Store #101 - Tunis');
    // Toggle methods
    setActiveNav(nav: 'dashboard' | 'procedures' | 'kaizen' | 'personnel')
    {
        this.activeNav.set(nav);
    }


    toggleRightDrawer()
    {
        this.isRightDrawerOpen.update(val => !val);
    }
}