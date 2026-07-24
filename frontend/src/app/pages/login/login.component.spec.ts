import { Component, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss' // Changed to match your SCSS setup
})
export class LoginComponent {
  private authService = inject(AuthService);
  private router = inject(Router);

  // Form Field Trackers
  email = '';
  password = '';
  
  // Status Tracking Signals
  isLoading = signal(false);
  errorMessage = signal<string | null>(null);

  handleLogin() {
    if (!this.email || !this.password) {
      this.errorMessage.set('Veuillez remplir tous les champs.');
      return;
    }

    this.isLoading.set(true);
    this.errorMessage.set(null);

    this.authService.login({ email: this.email, password: this.password }).subscribe({
      next: (res) => {
        this.isLoading.set(false);
        // Successful login automatically routes to our protected layout dashboard
        this.router.navigate(['/dashboard']);
      },
      error: (err) => {
        this.isLoading.set(false);
        this.errorMessage.set(
          err.error?.message || 'Identifiants invalides. Veuillez réessayer.'
        );
      }
    });
  }
}