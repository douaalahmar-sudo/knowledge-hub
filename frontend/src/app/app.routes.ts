import { Routes } from '@angular/router';
import { authGuard } from './guards/auth.guard';
import { ArticleListComponent } from './features/articles/article-list/article-list.component';
import { ArticleEditorComponent } from './features/articles/article-editor/article-editor.component';
import { ArticleDetailComponent } from './features/articles/article-detail/article-detail.component';




export const routes: Routes = [
// 1. Redirect root URL straight to login screen
{
    path: '',
    redirectTo: 'login',
    pathMatch: 'full'
},
// 2. Public Auth Views (Lazy Loaded Standalone Components)
{
    path: 'login',
    loadComponent: () => import('./pages/login/login.component').then(m => m.LoginComponent)
},
{
    path: 'register',
    loadComponent: () => import('./pages/register/register.component').then(m => m.RegisterComponent)
},
// 3. Protected Main Application Wrapper
{
    path: 'dashboard',
    canActivate: [authGuard],
    loadComponent: () => import('./layouts/dashboard-layout/dashboard-layout.component').then(m => m.DashboardLayoutComponent),
    children: [
    {
        path: '',
        loadComponent: () => import('./pages/procedures-list/procedures-list.component').then(m => m.ProceduresListComponent)
    },
    {
        path: 'kaizen',
        loadComponent: () => import('./pages/kaizen-reports/kaizen-reports.component').then(m => m.KaizenReportsComponent)
    },
    // Knowledge Base Module Routes (Rendered inside Dashboard Layout)
    {
        path: 'knowledge-base',
        component: ArticleListComponent
    },
    {
        path: 'knowledge-base/new',
        component: ArticleEditorComponent
    },
    {
        path: 'knowledge-base/:slug',
        component: ArticleDetailComponent
    },
    {
        path: 'knowledge-base/edit/:slug',
        component: ArticleEditorComponent
    }
    ]
},
// 4. Wildcard Catch-All (Redirect unknown paths back to login)
{
    path: '**',
    redirectTo: 'login'
}
];