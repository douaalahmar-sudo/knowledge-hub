import { APP_INITIALIZER, ApplicationConfig, inject, provideZoneChangeDetection } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import { routes } from './app.routes';
import { authInterceptor } from './interceptors/auth.interceptor';
import { AuthService } from './services/auth.service';




export const appConfig: ApplicationConfig =
{
    providers: [
    provideZoneChangeDetection(
    {
        eventCoalescing: true
    })
    ,
    provideRouter(routes),
    provideHttpClient(withFetch(), withInterceptors([authInterceptor])), // Enables modern, fast API fetching
    // Repairs a session cached before `access_role` was persisted, so those
    // users don't have to log out and back in to regain the article-workflow
    // UI. The factory returns a void function rather than a Promise/Observable
    // on purpose: bootstrap is never held up waiting on /me. Self-guarding —
    // a session that already carries access_role issues no request at all.
    {
        provide: APP_INITIALIZER,
        multi: true,
        useFactory: () =>
        {
            const auth = inject(AuthService);
            return () => auth.backfillAccessRole();
        }
    }
    ]
};