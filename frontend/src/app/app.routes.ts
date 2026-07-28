import { Routes } from '@angular/router';
import { authGuard } from './guards/auth.guard';
import { roleGuard } from './guards/role.guard';


export const routes: Routes = [
    // 1. Redirect root URL straight to the dashboard shell
    {
        path: '',
        redirectTo: 'dashboard',
        pathMatch: 'full'
    },
    // Public authentication routes
    {
        path: 'login',
        loadComponent: () => import('./pages/login/login.component').then(m => m.LoginComponent)
    },
    {
        path: 'register',
        loadComponent: () => import('./pages/register/register.component').then(m => m.RegisterComponent)
    },
    // 2. Primary Application Shell (single sidebar + header wrapper).
    //    All child views render inside LayoutShellComponent's <router-outlet>.
    {
        path: 'dashboard',
        canActivate: [authGuard],
        loadComponent: () => import('./components/layout-shell/layout-shell.component').then(m => m.LayoutShellComponent),
        children: [
            // /dashboard -> Executive dashboard & procedures
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () => import('./pages/procedures-list/procedures-list.component').then(m => m.ProceduresListComponent)
            },
            // /dashboard/procedures -> Procedures list (alias used by "related procedures" links)
            {
                path: 'procedures',
                loadComponent: () => import('./pages/procedures-list/procedures-list.component').then(m => m.ProceduresListComponent)
            },
            // ---- Triptych authoring & viewing (Group C) ----
            // Authoring roles mirror the backend `manage-procedures` Gate
            // (AppServiceProvider.php): admin + process_owner. `new` and `:id/edit`
            // must be declared BEFORE ':id' or the literal segments match it first.
            {
                path: 'procedures/new',
                canActivate: [roleGuard],
                data: { roles: ['admin', 'process_owner'] },
                loadComponent: () => import('./features/procedures/procedure-form/procedure-form.component').then(m => m.ProcedureFormComponent)
            },
            {
                path: 'procedures/:id/edit',
                canActivate: [roleGuard],
                data: { roles: ['admin', 'process_owner'] },
                loadComponent: () => import('./features/procedures/procedure-form/procedure-form.component').then(m => m.ProcedureFormComponent)
            },
            // /dashboard/procedures/:id -> 3-tab triptych viewer (everyone can read)
            {
                path: 'procedures/:id',
                loadComponent: () => import('./features/procedures/procedure-detail/procedure-detail.component').then(m => m.ProcedureDetailComponent)
            },
            // /dashboard/search -> Global search results (Module 3)
            {
                path: 'search',
                loadComponent: () => import('./features/search/search-results.component').then(m => m.SearchResultsComponent)
            },
            // /dashboard/kaizen -> Kaizen reports & écarts.
            // Roles mirror the backend's `submit-kaizen` Gate (AppServiceProvider.php):
            // admin, process_owner, operator. Manager/validator/expert_metier/dept
            // manager/HR roles are excluded — flag this if you want a broader/narrower set.
            {
                path: 'kaizen',
                canActivate: [roleGuard],
                data: { roles: ['admin', 'process_owner', 'operator'] },
                loadComponent: () => import('./pages/kaizen-reports/kaizen-reports.component').then(m => m.KaizenReportsComponent)
            },
            // ---- HR Self-Service module (Module 2) ----
            // /dashboard/hr-requests -> Employee "Mes Demandes" (any authenticated user)
            {
                path: 'hr-requests',
                loadComponent: () => import('./features/hr-requests/hr-request-list/hr-request-list.component').then(m => m.HrRequestListComponent)
            },
            // /dashboard/hr-admin -> HR Admin processing portal (HR admins only)
            {
                path: 'hr-admin',
                canActivate: [roleGuard],
                data: { roles: ['hr_admin'] },
                loadComponent: () => import('./features/hr-requests/hr-admin-portal/hr-admin-portal.component').then(m => m.HrAdminPortalComponent)
            },
            // ---- Knowledge Base module ----
            // /dashboard/knowledge-base -> KB portal (everyone can read)
            {
                path: 'knowledge-base',
                loadComponent: () => import('./features/articles/article-list/article-list.component').then(m => m.ArticleListComponent)
            },
            // /dashboard/knowledge-base/new -> create article (authors only; must precede :slug)
            // JUDGMENT CALL: no backend gate exists for KB authoring. Kept hr_admin
            // (prior behavior) and added expert_metier, whose seeded description is
            // literally "rédige les procédures & articles" (writes articles). Revise
            // if article authorship should be scoped differently.
            {
                path: 'knowledge-base/new',
                canActivate: [roleGuard],
                data: { roles: ['hr_admin', 'expert_metier'] },
                loadComponent: () => import('./features/articles/article-editor/article-editor.component').then(m => m.ArticleEditorComponent)
            },
            // /dashboard/knowledge-base/edit/:slug -> edit article (authors only)
            {
                path: 'knowledge-base/edit/:slug',
                canActivate: [roleGuard],
                data: { roles: ['hr_admin', 'expert_metier'] },
                loadComponent: () => import('./features/articles/article-editor/article-editor.component').then(m => m.ArticleEditorComponent)
            },
            // /dashboard/knowledge-base/:slug -> article reader
            {
                path: 'knowledge-base/:slug',
                loadComponent: () => import('./features/articles/article-detail/article-detail.component').then(m => m.ArticleDetailComponent)
            }
        ]
    },
    // 3. Catch-all
    {
        path: '**',
        redirectTo: 'dashboard'
    }
];
