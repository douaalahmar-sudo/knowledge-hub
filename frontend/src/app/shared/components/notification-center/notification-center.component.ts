import { Component, ElementRef, HostListener, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { NotificationService } from '../../../core/services/notification.service';
import { AppNotification, NOTIFICATION_TYPE_META } from '../../../core/models/notification.model';
import { IconComponent } from '../../icon/icon.component';

@Component({
  selector: 'app-notification-center',
  standalone: true,
  imports: [CommonModule, IconComponent],
  templateUrl: './notification-center.component.html',
})
export class NotificationCenterComponent {
  private notificationService = inject(NotificationService);
  private router = inject(Router);
  private host = inject(ElementRef);

  isOpen = signal(false);
  notifications = this.notificationService.notifications;
  unreadCount = this.notificationService.unreadCount;
  typeMeta = NOTIFICATION_TYPE_META;

  toggle(): void {
    this.isOpen.update(open => !open);
  }

  close(): void {
    this.isOpen.set(false);
  }

  open(n: AppNotification): void {
    this.notificationService.markAsRead(n.id);
    this.close();
    if (n.url) this.router.navigateByUrl(n.url);
  }

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (!this.host.nativeElement.contains(event.target)) {
      this.close();
    }
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.close();
  }
}
