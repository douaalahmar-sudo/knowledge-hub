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
    type = signal('erreur_metier');
    criticality = signal('medium');
    description = signal('');
    processOwnerId = signal('');
    resolutionNotes = signal('');
    updatedContent = signal('');
    activeResolvingSignal = signal<any | null>(null);

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
            type: this.type(),
            criticality: this.criticality(),
            description: this.description(),
            process_owner_id: this.processOwnerId() ? Number(this.processOwnerId()) : null
        };

        this.kaizenService.createReport(payload).subscribe({
            next: (createdReport) => {
                this.kaizenService.reports.update(list => [createdReport, ...list]);
                this.isSubmitting.set(false);
                this.selectedProcedureId.set('');
                this.type.set('erreur_metier');
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

    markInReview(signalId: number): void {
        const signal = this.reports().find(report => report.id === signalId);

        if (!signal) {
            return;
        }

        this.activeResolvingSignal.set(signal);
    }

    resolveSignal(): void {
        const signalToResolve = this.activeResolvingSignal();

        if (!signalToResolve || !this.resolutionNotes().trim() || !this.updatedContent().trim()) {
            this.errorMessage.set('Veuillez compléter les notes de résolution et le contenu mis à jour.');
            return;
        }

        this.isSubmitting.set(true);
        this.errorMessage.set(null);

        this.kaizenService.createReport({
            procedure_id: signalToResolve.procedure_id ?? signalToResolve.procedure?.id,
            criticality: signalToResolve.criticality ?? 'medium',
            description: this.updatedContent().trim(),
            process_owner_id: signalToResolve.process_owner_id ?? null
        }).subscribe({
            next: () => {
                this.isSubmitting.set(false);
                this.activeResolvingSignal.set(null);
                this.resolutionNotes.set('');
                this.updatedContent.set('');
            },
            error: (error) => {
                this.isSubmitting.set(false);
                this.errorMessage.set(error.error?.message || 'La résolution du signalement a échoué.');
            }
        });
    }

    getCriticalityBg(criticality: string): string {
        switch (criticality) {
            case 'critique':
            case 'critical':
                return '#fee2e2';
            case 'moyenne':
            case 'medium':
                return '#fef3c7';
            case 'faible':
            case 'low':
                return '#dcfce7';
            default:
                return '#e2e8f0';
        }
    }

    getCriticalityColor(criticality: string): string {
        switch (criticality) {
            case 'critique':
            case 'critical':
                return '#b91c1c';
            case 'moyenne':
            case 'medium':
                return '#92400e';
            case 'faible':
            case 'low':
                return '#15803d';
            default:
                return '#334155';
        }
    }
}