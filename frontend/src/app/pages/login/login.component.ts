import { Component, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AuthService } from '../../services/auth.service';


@Component(
{
    selector: 'app-login',
    standalone: true,
imports: [ReactiveFormsModule, RouterLink], templateUrl: './login.component.html',
    styleUrl: './login.component.scss'
})


export class LoginComponent
{
    private authService = inject(AuthService);
    private router = inject(Router);
    private fb = inject(FormBuilder);

    isLoading = signal(false);
    errorMessage = signal<string | null>(null);

    form = this.fb.nonNullable.group({
        email: ['', [Validators.required, Validators.email]],
        password: ['', [Validators.required]],
    });

    get email() { return this.form.controls.email; }
    get password() { return this.form.controls.password; }

    handleLogin() : void
    {
        if (this.form.invalid)
        {
            this.form.markAllAsTouched();
            this.errorMessage.set('Veuillez corriger les champs invalides.');
            return;
        }
        this.isLoading.set(true);
        this.errorMessage.set(null);
        // Frontend-only demo auth (AuthService) — see auth.service.ts DEMO_ACCOUNTS.
        this.authService.login(this.form.getRawValue())
        .subscribe(
        {
            next: () =>
            {
                this.isLoading.set(false);
                this.router.navigate(['/dashboard']);
            },
            error: (err) =>
            {
                this.isLoading.set(false);
                this.errorMessage.set(
                err.error?.message || 'Identifiants invalides. Veuillez réessayer.'
                );
            }
        });
    }
}
