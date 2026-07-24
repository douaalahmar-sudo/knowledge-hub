import { CommonModule } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { KaizenReportService } from '../../services/kaizen-report.service';
import { ProcedureService } from '../../services/procedure.service';
import { AuthService } from '../../services/auth.service';

@Component({
    selector: 'app-kaizen-reports',
    standalone: true,
    imports: [CommonModule, FormsModule],
    templateUrl: './kaizen-reports.component.html',
    styleUrl: './kaizen-reports.component.scss'
})
export class KaizenReportsComponent implements OnInit {
    private kaizenService = inject(KaizenReportService);
    private procedureService = inject(ProcedureService);
    private authService = inject(AuthService);

    reports = this.kaizenService.reports;
    procedures = this.procedureService.proceduresList;
    tenant = this.authService.currentTenant;

    pendingCount = computed(() => this.reports().filter(report => report.status === 'open').length);
    closedCount = computed(() => this.reports().filter(report => report.status !== 'open').length);

    isLoading = signal(true);
    isSubmitting = signal(false);
    errorMessage = signal<string | null>(null);

    selectedProcedureId = signal('');
    criticality = signal('medium');
    description = signal('');
    processOwnerId = signal('');

    ngOnInit(): void {
        this.fetchData();
    }

    fetchData(): void {
        this.procedureService.getProcedures().subscribe({
            next: () => this.isLoading.set(false),
            error: () => {
                this.errorMessage.set('Impossible de charger les procédures.');
                this.isLoading.set(false);
            }
        });

        this.kaizenService.getReports().subscribe({
            next: () => undefined,
            error: () => this.errorMessage.set('Impossible de charger les signalements Kaizen.')
        });
    }

    submitReport(): void {
        if (!this.selectedProcedureId() || !this.criticality() || !this.description()) {
            this.errorMessage.set('Veuillez remplir les champs obligatoires.');
            return;
        }

        this.isSubmitting.set(true);
        this.errorMessage.set(null);

        const payload = {
            procedure_id: Number(this.selectedProcedureId()),
            criticality: this.criticality(),
            description: this.description(),
            process_owner_id: this.processOwnerId() ? Number(this.processOwnerId()) : null
        };

        this.kaizenService.createReport(payload).subscribe({
            next: (createdReport) => {
                this.kaizenService.reports.update(list => [createdReport, ...list]);
                this.isSubmitting.set(false);
                this.selectedProcedureId.set('');
                this.criticality.set('medium');
                this.description.set('');
                this.processOwnerId.set('');
            },
            error: (error) => {
                this.isSubmitting.set(false);
                this.errorMessage.set(error.error?.message || 'La soumission Kaizen a échoué.');
            }
        });
    }
}