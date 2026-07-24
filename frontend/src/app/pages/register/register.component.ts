import { Component, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../services/auth.service';


@Component(
{
    selector: 'app-register',
    standalone: true,
imports: [FormsModule, RouterLink], templateUrl: './register.component.html',
    styleUrl: './register.component.scss'
})


export class RegisterComponent
{
    private authService = inject(AuthService);
    private router = inject(Router);
    companyName = signal('');
    name = signal('');
    email = signal('');
    matricule = signal('');
    password = signal('');
    passwordConfirmation = signal('');
    isLoading = signal(false);
    errorMessage = signal<string | null>(null);
    handleRegister() : void
    {
        if (
        !this.companyName() ||
        !this.name() ||
        !this.email() ||
        !this.matricule() ||
        !this.password() ||
        !this.passwordConfirmation()
        )
        {
            this.errorMessage.set('Veuillez remplir tous les champs obligatoires.');
            return;
        }
        if (this.password() !== this.passwordConfirmation())
        {
            this.errorMessage.set('Les mots de passe ne correspondent pas.');
            return;
        }
        this.isLoading.set(true);
        this.errorMessage.set(null);
        const registrationData =
        {
            company_name: this.companyName(),
            name: this.name(),
            email: this.email(),
            matricule: this.matricule(),
            password: this.password(),
            password_confirmation: this.passwordConfirmation()
        };
        this.authService.register(registrationData).subscribe(
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
                err.error?.message || 'Une erreur est survenue lors de l\'inscription.'
                );
            }
        });
    }
}