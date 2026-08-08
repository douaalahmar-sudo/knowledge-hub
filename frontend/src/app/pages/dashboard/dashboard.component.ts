import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { AuthService } from '../../services/auth.service';
import { IconComponent } from '../../shared/icon/icon.component';

/** One of the four category tiles at the top of the page. */
interface CategoryCard {
  label: string;
  count: number;
  updatedLabel: string;
  /** Served from public/images/departments/ — see angular.json's asset root. */
  image: string;
}

/** One "Procédures populaires" card. */
interface PopularProcedure {
  title: string;
  kind: string;
  format: 'pdf' | 'video' | 'other';
  views: number;
  updatedLabel: string;
}

/** One row in the "Dernières mises à jour" timeline. */
interface UpdateEntry {
  title: string;
  badge: 'Nouveau' | 'Vidéo' | 'Golden Rule' | null;
  when: string;
  module: string;
}

/** One "Actualités" entry. */
interface NewsEntry {
  title: string;
  detail: string;
  when: string;
  icon: 'megaphone' | 'calendar' | 'check-circle';
}

/**
 * /dashboard's landing page — was a bare placeholder ("Le tableau de bord
 * complet arrive bientôt"); this replaces it with the full homepage.
 *
 * Everything below the greeting is mock data, deliberately: no backend
 * endpoint for a personalized homepage feed exists yet, and building one is
 * a separate piece of work. The greeting name is the one exception — it
 * reads the real signed-in user, the same way LayoutShellComponent's own
 * sidebar footer does, rather than hardcoding a name.
 *
 * Two things in the mockup this was built from are deliberately NOT here —
 * see the chat message this shipped with for why.
 */
@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [CommonModule, RouterModule, IconComponent],
  templateUrl: './dashboard.component.html',
  styleUrls: ['./dashboard.component.scss']
})
export class DashboardComponent {
  private auth = inject(AuthService);

  /** First name only, for "Bonjour {X}" — the full name is more than the greeting needs. */
  firstName = computed<string>(() => {
    const name = this.auth.currentUser()?.name ?? 'Utilisateur';
    return name.split(' ')[0];
  });

  readonly categoryCards: CategoryCard[] = [
    { label: 'Magasin', count: 230, updatedLabel: "Mise à jour : aujourd'hui", image: 'images/departments/magasin.jpg' },
    { label: 'Logistique', count: 180, updatedLabel: 'Mise à jour : hier', image: 'images/departments/logistique.jpg' },
    { label: 'Siège', count: 120, updatedLabel: 'Mise à jour : 2 jours', image: 'images/departments/siege.jpg' },
    { label: 'Ressources Humaines', count: 42, updatedLabel: 'Mise à jour : hier', image: 'images/departments/rh.jpg' },
  ];

  readonly popularProcedures: PopularProcedure[] = [
    { title: 'Encaissement', kind: 'Procédure caisse', format: 'pdf', views: 1850, updatedLabel: 'Mis à jour hier' },
    { title: 'Inventaire magasin', kind: 'Procédure', format: 'pdf', views: 1250, updatedLabel: 'Mis à jour il y a 2 jours' },
    { title: 'Retour client', kind: 'Procédure', format: 'other', views: 890, updatedLabel: 'Mis à jour hier' },
    { title: 'Réception marchandise', kind: 'Procédure', format: 'video', views: 760, updatedLabel: 'Mis à jour il y a 3 jours' },
  ];

  readonly recentUpdates: { day: string; entries: UpdateEntry[] }[] = [
    {
      day: "Aujourd'hui",
      entries: [
        { title: 'Nouvelle procédure - Gestion des retours clients', badge: 'Nouveau', when: 'Il y a 2h', module: 'Magasin' },
        { title: 'Nouvelle vidéo - Formation encaissement', badge: 'Vidéo', when: 'Il y a 4h', module: 'Académie' },
        { title: 'Mise à jour - Golden Rule : Accueil client', badge: 'Golden Rule', when: 'Il y a 5h', module: 'Golden Rules' },
      ],
    },
    {
      day: 'Hier',
      entries: [
        { title: 'Nouvelle procédure - Demande de congé', badge: null, when: 'Hier', module: 'RH' },
        { title: 'Nouvelle formation - Sécurité en magasin', badge: null, when: 'Hier', module: 'Académie' },
      ],
    },
  ];

  readonly news: NewsEntry[] = [
    { title: 'Promotion Nationale', detail: 'Découvrez notre nouvelle campagne !', when: 'Il y a 1j', icon: 'megaphone' },
    { title: 'Inventaire Annuel', detail: 'Préparation inventaire du 15 au 30 juin.', when: 'Il y a 2j', icon: 'calendar' },
    { title: 'Nouvelle procédure', detail: 'Consultez la nouvelle procédure de sécurité.', when: 'Il y a 3j', icon: 'check-circle' },
  ];

  readonly academyProgress = 82;
  readonly academyQuizCount = 5;
  readonly kaizenPendingCount = 3;

  /**
   * "Personnaliser" opens this rather than doing nothing — there's no real
   * dashboard-customization feature to open yet (no widget reordering, no
   * show/hide), so this is an honest "coming soon" rather than a button that
   * looks live and silently does nothing when pressed.
   */
  showPersonalizePanel = signal(false);
}
