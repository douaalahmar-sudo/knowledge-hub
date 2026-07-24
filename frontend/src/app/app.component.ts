import { Component } from '@angular/core';
import { LayoutShellComponent } from './components/layout-shell/layout-shell.component';


@Component(
{
    selector: 'app-root',
    standalone: true,
imports: [LayoutShellComponent], templateUrl: './app.component.html',
})


export class AppComponent
{
}