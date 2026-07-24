import { Component, OnInit, signal, computed, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';


@Component(
{
    selector: 'app-kaizen-reports',
    standalone: true,
imports: [CommonModule, FormsModule], templateUrl: './kaizen-reports.component.html'
})


export class KaizenReportsComponent implements OnInit
{
    private http = inject(HttpClient);
    // Core Reactive Signals
    tenant = signal<
    {
        name: string
    }
    | null>(
    {
        name: 'FLESK Store #101 - Tunis'
    });
    reports = signal<any[]>([]);
    procedures = signal<any[]>([]);
    isLoading = signal<boolean>(false);
    isSubmitting = signal<boolean>(false);
    errorMessage = signal<string | null>(null);
    // Form Signals
    selectedProcedureId = signal<string>('');
    type = signal<'obsolescence' | 'erreur_metier' | 'amelioration'>('erreur_metier');
    criticality = signal<'faible' | 'moyenne' | 'critique'>('moyenne');
    description = signal<string>('');
    // Resolution Modal Signals
    activeResolvingSignal = signal<any | null>(null);
    resolutionNotes = signal<string>('');
    updatedContent = signal<string>('');
    // Computed Counters
    pendingCount = computed(() => this.reports().filter(r => r.status !== 'resolved').length);
    closedCount = computed(() => this.reports().filter(r => r.status === 'resolved').length);
    ngOnInit() : void
    {
        this.loadProcedures();
        this.loadKaizenSignals();
    }
    loadProcedures() : void
    {
        this.http.get<any>('/api/procedures').subscribe(
        {
            next: (res) => this.procedures.set(res.data || res),
            error: (err) => console.error('Failed to fetch procedures', err)
        });
    }
    loadKaizenSignals() : void
    {
        this.isLoading.set(true);
        this.http.get<any>('/api/kaizen/signals').subscribe(
        {
            next: (res) =>
            {
                this.reports.set(res.data || []);
                this.isLoading.set(false);
            },
            error: (err) =>
            {
                this.errorMessage.set('Erreur lors du chargement des signalements.');
                this.isLoading.set(false);
            }
        });
    }
    submitReport() : void
    {
        if (!this.selectedProcedureId() || !this.description())
        {
            this.errorMessage.set('Veuillez remplir tous les champs requis.');
            return;
        }
        this.isSubmitting.set(true);
        this.errorMessage.set(null);
        const payload =
        {
            procedure_id: this.selectedProcedureId(),
            type: this.type(),
            criticality: this.criticality(),
            description: this.description()
        };
        this.http.post('/api/kaizen/signals', payload).subscribe(
        {
            next: () =>
            {
                this.isSubmitting.set(false);
                this.description.set('');
                this.selectedProcedureId.set('');
                this.loadKaizenSignals();
            },
            error: (err) =>
            {
                this.isSubmitting.set(false);
                this.errorMessage.set(err.error?.message || 'Erreur lors de la création du signalement.');
            }
        });
    }
    markInReview(signalId: number) : void
    {
        this.http.patch(`/api/kaizen/signals/${signalId}/in-review`,
        {
        })
        .subscribe(
        {
            next: () => this.loadKaizenSignals(),
            error: (err) => console.error('Failed to update status', err)
        });
    }
    resolveSignal() : void
    {
        const signalToResolve = this.activeResolvingSignal();
        if (!signalToResolve || !this.resolutionNotes() || !this.updatedContent())
        {
            return;
        }
        this.isSubmitting.set(true);
        const payload =
        {
            resolution_notes: this.resolutionNotes(),
            updated_content: this.updatedContent()
        };
        this.http.post(`/api/kaizen/signals/${signalToResolve.id}/resolve`, payload).subscribe(
        {
            next: () =>
            {
                this.isSubmitting.set(false);
                this.activeResolvingSignal.set(null);
                this.resolutionNotes.set('');
                this.updatedContent.set('');
                this.loadKaizenSignals();
            },
            error: (err) =>
            {
                this.isSubmitting.set(false);
                this.errorMessage.set(err.error?.message || 'Erreur lors de la résolution.');
            }
        });
    }
    getCriticalityBg(criticality: string) : string
    {
        switch (criticality)
        {
            case 'critique': return '#fef2f2';
            case 'moyenne': return '#fffbebe';
            default: return '#f0fdf4';
        }
    }
    getCriticalityColor(criticality: string) : string
    {
        switch (criticality)
        {
            case 'critique': return '#dc2626';
            case 'moyenne': return '#d97706';
            default: return '#16a34a';
        }
    }
}