import { Routes } from '@angular/router';


export const routes: Routes = [
    // 1. Redirect root URL straight to the dashboard shell
    {
        path: '',
        redirectTo: 'dashboard',
        pathMatch: 'full'
    },
    // 2. Primary Application Shell (single sidebar + header wrapper).
    //    All child views render inside LayoutShellComponent's <router-outlet>.
    {
        path: 'dashboard',
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
            // /dashboard/kaizen -> Kaizen reports & écarts
            {
                path: 'kaizen',
                loadComponent: () => import('./pages/kaizen-reports/kaizen-reports.component').then(m => m.KaizenReportsComponent)
            },
            // ---- HR Self-Service module (Module 2) ----
            // /dashboard/hr-requests -> Employee "Mes Demandes"
            {
                path: 'hr-requests',
                loadComponent: () => import('./features/hr-requests/hr-request-list/hr-request-list.component').then(m => m.HrRequestListComponent)
            },
            // /dashboard/hr-admin -> HR Admin processing portal
            {
                path: 'hr-admin',
                loadComponent: () => import('./features/hr-requests/hr-admin-portal/hr-admin-portal.component').then(m => m.HrAdminPortalComponent)
            },
            // ---- Knowledge Base module ----
            // /dashboard/knowledge-base -> KB portal
            {
                path: 'knowledge-base',
                loadComponent: () => import('./features/articles/article-list/article-list.component').then(m => m.ArticleListComponent)
            },
            // /dashboard/knowledge-base/new -> create article (must precede :slug)
            {
                path: 'knowledge-base/new',
                loadComponent: () => import('./features/articles/article-editor/article-editor.component').then(m => m.ArticleEditorComponent)
            },
            // /dashboard/knowledge-base/edit/:slug -> edit article
            {
                path: 'knowledge-base/edit/:slug',
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
