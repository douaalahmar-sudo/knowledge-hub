import { Component, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../services/auth.service';


@Component(
{
    selector: 'app-login',
    standalone: true,
imports: [FormsModule, RouterLink], templateUrl: './login.component.html',
    styleUrl: './login.component.scss'
})


export class LoginComponent
{
    private authService = inject(AuthService);
    private router = inject(Router);
    email = signal('');
    password = signal('');
    isLoading = signal(false);
    errorMessage = signal<string | null>(null);


    handleLogin() : void
    {
        if (!this.email() || !this.password())
        {
            this.errorMessage.set('Veuillez remplir tous les champs.');
            return;
        }
        this.isLoading.set(true);
        this.errorMessage.set(null);
        // Using 'login' as the key to perfectly match your Laravel backend requirements
        this.authService.login(
        {
            login: this.email(), password: this.password()
        })
        .subscribe(
        {
            next: (res) =>
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