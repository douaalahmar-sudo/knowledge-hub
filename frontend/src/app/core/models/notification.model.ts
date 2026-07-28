export type NotificationType = 'kaizen' | 'hr_request' | 'system';

export interface AppNotification {
  id: string;
  /** Omit/null to broadcast to every authenticated user; set to target one user's inbox. */
  userId?: number | null;
  type: NotificationType;
  title: string;
  message?: string;
  /** Route to navigate to when the notification is clicked. */
  url?: string;
  read: boolean;
  created_at: string;
}

export const NOTIFICATION_TYPE_META: Record<NotificationType, { icon: string; iconClass: string }> = {
  kaizen: { icon: 'alert', iconClass: 'bg-amber-50 text-amber-600' },
  hr_request: { icon: 'inbox', iconClass: 'bg-indigo-50 text-indigo-600' },
  system: { icon: 'check-circle', iconClass: 'bg-emerald-50 text-emerald-600' },
};
