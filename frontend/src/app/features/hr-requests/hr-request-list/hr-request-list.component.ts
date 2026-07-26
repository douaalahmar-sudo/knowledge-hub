import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HrRequestService } from '../../../core/services/hr-request.service';
import { HrRequest, HR_STATUS_META, HR_TYPE_META } from '../../../core/models/hr-request.model';
import { HrRequestModalComponent } from '../hr-request-modal/hr-request-modal.component';
import { IconComponent } from '../../../shared/icon/icon.component';


@Component({
  selector: 'app-hr-request-list',
  standalone: true,
  imports: [CommonModule, HrRequestModalComponent, IconComponent],
  templateUrl: './hr-request-list.component.html'
})
export class HrRequestListComponent implements OnInit {
  private hrService = inject(HrRequestService);

  requests = signal<HrRequest[]>([]);
  isLoading = signal(true);
  isModalOpen = signal(false);

  statusMeta = HR_STATUS_META;
  typeMeta = HR_TYPE_META;

  pendingCount = computed(() => this.requests().filter(r => r.status === 'PENDING').length);
  activeCount = computed(() => this.requests().filter(r => r.status === 'IN_PROGRESS').length);
  readyCount = computed(() =>
    this.requests().filter(r => r.status === 'READY_FOR_DOWNLOAD' || r.status === 'APPROVED').length
  );

  ngOnInit(): void {
    this.loadRequests();
  }

  loadRequests(): void {
    this.isLoading.set(true);
    this.hrService.getEmployeeRequests().subscribe({
      next: (res: any) => {
        this.requests.set(res?.data ?? res ?? []);
        this.isLoading.set(false);
      },
      error: (err) => {
        console.error('Error loading HR requests', err);
        this.requests.set([]);
        this.isLoading.set(false);
      }
    });
  }

  openModal(): void {
    this.isModalOpen.set(true);
  }

  onModalClosed(): void {
    this.isModalOpen.set(false);
  }

  onRequestCreated(): void {
    this.isModalOpen.set(false);
    this.loadRequests();
  }

  isDownloadable(r: HrRequest): boolean {
    return (r.status === 'READY_FOR_DOWNLOAD' || r.status === 'APPROVED') && !!r.pdf_url;
  }
}
