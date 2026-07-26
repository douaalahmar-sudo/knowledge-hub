import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HrRequestService } from '../../../core/services/hr-request.service';
import { HrRequest, HrRequestStatus, HR_STATUS_META, HR_TYPE_META } from '../../../core/models/hr-request.model';
import { IconComponent } from '../../../shared/icon/icon.component';


@Component({
  selector: 'app-hr-admin-portal',
  standalone: true,
  imports: [CommonModule, FormsModule, IconComponent],
  templateUrl: './hr-admin-portal.component.html'
})
export class HrAdminPortalComponent implements OnInit {
  private hrService = inject(HrRequestService);

  requests = signal<HrRequest[]>([]);
  isLoading = signal(true);

  // Action drawer state
  selectedRequest = signal<HrRequest | null>(null);
  adminNote = signal('');
  pdfFile = signal<File | null>(null);
  isSaving = signal(false);
  actionError = signal<string | null>(null);

  statusMeta = HR_STATUS_META;
  typeMeta = HR_TYPE_META;

  pendingCount = computed(() => this.requests().filter(r => r.status === 'PENDING').length);

  // Queue sorted by priority: PENDING first, then IN_PROGRESS, then the rest; newest first within a group.
  private statusPriority: Record<HrRequestStatus, number> = {
    PENDING: 0,
    IN_PROGRESS: 1,
    READY_FOR_DOWNLOAD: 2,
    APPROVED: 3,
    REJECTED: 4
  };

  sortedRequests = computed(() =>
    [...this.requests()].sort((a, b) => {
      const p = this.statusPriority[a.status] - this.statusPriority[b.status];
      if (p !== 0) return p;
      return new Date(b.created_at || 0).getTime() - new Date(a.created_at || 0).getTime();
    })
  );

  ngOnInit(): void {
    this.loadRequests();
  }

  loadRequests(): void {
    this.isLoading.set(true);
    this.hrService.getAllRequests().subscribe({
      next: (res: any) => {
        this.requests.set(res?.data ?? res ?? []);
        this.isLoading.set(false);
      },
      error: (err) => {
        console.error('Error loading HR queue', err);
        this.requests.set([]);
        this.isLoading.set(false);
      }
    });
  }

  openReview(req: HrRequest): void {
    this.selectedRequest.set(req);
    this.adminNote.set(req.admin_note || '');
    this.pdfFile.set(null);
    this.actionError.set(null);
  }

  closeReview(): void {
    this.selectedRequest.set(null);
  }

  onPdfSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.pdfFile.set(input.files && input.files[0] ? input.files[0] : null);
  }

  applyStatus(status: HrRequestStatus): void {
    const req = this.selectedRequest();
    if (!req || req.id == null) return;

    if (status === 'REJECTED' && !this.adminNote().trim()) {
      this.actionError.set('Un motif est requis pour refuser une demande.');
      return;
    }
    if (status === 'READY_FOR_DOWNLOAD' && !this.pdfFile()) {
      this.actionError.set('Veuillez joindre le document PDF avant de marquer comme prêt.');
      return;
    }

    this.isSaving.set(true);
    this.actionError.set(null);
    this.hrService.updateRequestStatus(req.id, status, this.adminNote(), this.pdfFile()).subscribe({
      next: () => {
        this.isSaving.set(false);
        this.closeReview();
        this.loadRequests();
      },
      error: (err) => {
        this.isSaving.set(false);
        this.actionError.set(err?.error?.message || 'Une erreur est survenue lors de la mise à jour.');
      }
    });
  }
}
