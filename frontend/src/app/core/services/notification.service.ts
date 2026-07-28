import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, of } from 'rxjs';
import { AuthService } from '../../services/auth.service';
import { AppNotification, NotificationType } from '../models/notification.model';
import { STORE_KEYS, lsRead, lsWrite, uid } from '../mock/local-store.util';

export interface AddNotificationInput {
  type: NotificationType;
  title: string;
  message?: string;
  url?: string;
  /** Omit to broadcast to every authenticated user; set to target one user's inbox. */
  userId?: number | null;
}

@Injectable({
  providedIn: 'root'
})
export class NotificationService {
  private auth = inject(AuthService);

  private state = signal<AppNotification[]>(lsRead<AppNotification[]>(STORE_KEYS.notifications, []));

  /** Notifications visible to the signed-in user: broadcast items + items addressed to them. */
  notifications = computed<AppNotification[]>(() => {
    const currentUserId = this.auth.currentUser()?.id;
    return [...this.state()]
      .filter(n => n.userId == null || n.userId === currentUserId)
      .sort((a, b) => (a.created_at > b.created_at ? -1 : 1));
  });

  unreadCount = computed<number>(() => this.notifications().filter(n => !n.read).length);

  getNotifications(): Observable<AppNotification[]> {
    return of(this.notifications());
  }

  addNotification(input: AddNotificationInput): void {
    const entry: AppNotification = {
      id: uid('ntf_'),
      userId: input.userId ?? null,
      type: input.type,
      title: input.title,
      message: input.message,
      url: input.url,
      read: false,
      created_at: new Date().toISOString(),
    };
    const next = [entry, ...this.state()];
    lsWrite(STORE_KEYS.notifications, next);
    this.state.set(next);
  }

  markAsRead(id: string): void {
    const next = this.state().map(n => (n.id === id ? { ...n, read: true } : n));
    lsWrite(STORE_KEYS.notifications, next);
    this.state.set(next);
  }
}
