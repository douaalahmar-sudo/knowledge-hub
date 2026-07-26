import { Component, EventEmitter, Output, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { HrRequestService } from '../../../core/services/hr-request.service';
import { HrRequest, HrRequestType, HR_TYPE_META, LEAVE_TYPES } from '../../../core/models/hr-request.model';
import { IconComponent } from '../../../shared/icon/icon.component';


interface TypeCard {
  value: HrRequestType;
  label: string;
  icon: string;
  description: string;
}


@Component({
  selector: 'app-hr-request-modal',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, IconComponent],
  templateUrl: './hr-request-modal.component.html'
})
export class HrRequestModalComponent {
  private fb = inject(FormBuilder);
  private hrService = inject(HrRequestService);

  @Output() close = new EventEmitter<void>();
  @Output() created = new EventEmitter<HrRequest>();

  form!: FormGroup;
  selectedType = signal<HrRequestType | null>(null);
  attachments = signal<File[]>([]);
  isDragging = signal(false);
  isSubmitting = signal(false);
  errorMessage = signal<string | null>(null);

  leaveTypes = LEAVE_TYPES;
  months = [
    'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
    'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'
  ];
  years: number[] = [];

  typeCards: TypeCard[] = (Object.keys(HR_TYPE_META) as HrRequestType[]).map(t => ({
    value: t,
    label: HR_TYPE_META[t].label,
    icon: HR_TYPE_META[t].icon,
    description: HR_TYPE_META[t].description
  }));

  constructor() {
    const currentYear = new Date().getFullYear();
    this.years = [currentYear, currentYear - 1, currentYear - 2, currentYear - 3];
    this.form = this.fb.group({
      // Shared / type-specific controls (validated conditionally on submit).
      payslipMonth: [this.months[new Date().getMonth()]],
      payslipYear: [currentYear],
      purpose: [''],
      leaveType: [this.leaveTypes[0]],
      start_date: [''],
      end_date: [''],
      subject: [''],
      description: ['']
    });
  }

  selectType(type: HrRequestType): void {
    this.selectedType.set(type);
    this.errorMessage.set(null);
  }

  // ---------- File dropzone ----------
  onDragOver(event: DragEvent): void {
    event.preventDefault();
    this.isDragging.set(true);
  }

  onDragLeave(event: DragEvent): void {
    event.preventDefault();
    this.isDragging.set(false);
  }

  onDrop(event: DragEvent): void {
    event.preventDefault();
    this.isDragging.set(false);
    if (event.dataTransfer?.files) {
      this.addFiles(Array.from(event.dataTransfer.files));
    }
  }

  onFilesSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (input.files) this.addFiles(Array.from(input.files));
    input.value = '';
  }

  private addFiles(files: File[]): void {
    this.attachments.update(list => [...list, ...files]);
  }

  removeFile(index: number): void {
    this.attachments.update(list => list.filter((_, i) => i !== index));
  }

  formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  // ---------- Submit ----------
  submit(): void {
    const type = this.selectedType();
    if (!type) {
      this.errorMessage.set('Veuillez sélectionner un type de demande.');
      return;
    }

    const raw = this.form.value;
    let title = '';
    let description: string = raw.description || '';
    let startDate: string | null = null;
    let endDate: string | null = null;

    switch (type) {
      case 'PAYSLIP':
        title = `Fiche de paie – ${raw.payslipMonth} ${raw.payslipYear}`;
        break;
      case 'WORK_CERTIFICATE':
        title = 'Attestation de travail';
        description = raw.purpose || '';
        if (!description.trim()) {
          this.errorMessage.set('Veuillez préciser l’objet de l’attestation.');
          return;
        }
        break;
      case 'LEAVE_REQUEST':
        title = `Demande de congé – ${raw.leaveType}`;
        startDate = raw.start_date || null;
        endDate = raw.end_date || null;
        if (!startDate || !endDate) {
          this.errorMessage.set('Veuillez renseigner les dates de début et de fin.');
          return;
        }
        if (endDate < startDate) {
          this.errorMessage.set('La date de fin doit être postérieure à la date de début.');
          return;
        }
        break;
      case 'CUSTOM':
        title = (raw.subject || '').trim();
        if (!title) {
          this.errorMessage.set('Veuillez saisir un objet pour votre demande.');
          return;
        }
        break;
    }

    const fd = new FormData();
    fd.append('type', type);
    fd.append('title', title);
    fd.append('description', description);
    if (startDate) fd.append('start_date', startDate);
    if (endDate) fd.append('end_date', endDate);
    this.attachments().forEach(file => fd.append('attachments[]', file));

    this.isSubmitting.set(true);
    this.errorMessage.set(null);
    this.hrService.createRequest(fd).subscribe({
      next: (req) => {
        this.isSubmitting.set(false);
        this.created.emit(req);
      },
      error: (err) => {
        this.isSubmitting.set(false);
        this.errorMessage.set(err?.error?.message || 'Une erreur est survenue lors de l’envoi de la demande.');
      }
    });
  }

  onClose(): void {
    this.close.emit();
  }
}
