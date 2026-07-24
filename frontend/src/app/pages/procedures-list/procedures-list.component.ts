import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ProcedureService } from '../../services/procedure.service';
import { AuthService } from '../../services/auth.service';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';


@Component(
{
    selector: 'app-procedures-list',
    standalone: true,
    imports: [CommonModule, FormsModule],
    templateUrl: './procedures-list.component.html',
    styleUrl: './procedures-list.component.scss'
})


export class ProceduresListComponent implements OnInit
{
    private procedureService = inject(ProcedureService);
    private authService = inject(AuthService);
    procedures = this.procedureService.proceduresList; // Directly leverage service array signal
    tenant = this.authService.currentTenant;
    tenantName = computed(() => this.tenant()?.name || 'Tenant inconnu');
    tenantPlan = computed(() => this.tenant()?.plan || 'free');
    totalProcedures = computed(() => this.procedures().length);
    pendingProcedures = computed(() => this.procedures().filter(item => item.status === 'En attente').length);
    validatedProcedures = computed(() => this.procedures().filter(item => item.status === 'Validé').length);
    isLoading = signal(true);
    errorMessage = signal<string | null>(null);
    isModalOpen = signal(false);
    isSubmitting = signal(false);
    isVersionModalOpen = signal(false);
    isVersionSubmitting = signal(false);
    formReference = signal('');
    formName = signal('');
    formModule = signal('');
    formStatus = signal('En attente');
    versionProcedure = signal<any>(null);
    versionDocument = signal<File | null>(null);
    ngOnInit() : void
    {
        this.fetchProcedures();
    }
    fetchProcedures() : void
    {
        this.procedureService.getProcedures().subscribe(
        {
            next: () => this.isLoading.set(false),
            error: () =>
            {
                this.errorMessage.set('Impossible de charger les procédures.');
                this.isLoading.set(false);
            }
        });
    }
    openModal() : void
    {
        const autoRef = 'PR-' + new Date().getFullYear() + '-' + Math.floor(100 + Math.random() * 900);
        this.formReference.set(autoRef);
        this.formName.set('');
        this.formModule.set('');
        this.formStatus.set('En attente');
        this.isModalOpen.set(true);
    }
    closeModal() : void
    {
        this.isModalOpen.set(false);
        this.isSubmitting.set(false);
        this.formReference.set('');
        this.formName.set('');
        this.formModule.set('');
        this.formStatus.set('En attente');
    }
    openVersionModal(procedure: any) : void
    {
        this.versionProcedure.set(procedure);
        this.versionDocument.set(null);
        this.isVersionModalOpen.set(true);
    }
    closeVersionModal() : void
    {
        this.isVersionModalOpen.set(false);
        this.isVersionSubmitting.set(false);
        this.versionProcedure.set(null);
        this.versionDocument.set(null);
    }
    onVersionFileSelected(event: Event) : void
    {
        const input = event.target as HTMLInputElement;
        this.versionDocument.set(input.files && input.files[0] ? input.files[0] : null);
    }
    submitVersionUpload() : void
    {
        if (!this.versionProcedure() || !this.versionDocument()) return;

        this.isVersionSubmitting.set(true);

        this.procedureService.uploadProcedureVersion(this.versionProcedure().id, this.versionDocument() as File).subscribe({
            next: () =>
            {
                this.isVersionSubmitting.set(false);
                this.closeVersionModal();
                this.fetchProcedures();
            },
            error: (err) =>
            {
                this.isVersionSubmitting.set(false);
                alert(err.error?.message || "Une erreur est survenue lors de l'import de la version.");
            }
        });
    }
    submitProcedure() : void
    {
        if (!this.formName() || !this.formModule() || !this.formReference()) return;
        this.isSubmitting.set(true);
        const payload =
        {
            reference: this.formReference(),
            name: this.formName(),
            module: this.formModule(),
            status: this.formStatus()
        };
        this.procedureService.createProcedure(payload).subscribe(
        {
            next: (createdProcedure) =>
            {
                this.isSubmitting.set(false);
                this.procedures.update(list => [
                    ...list,
                    createdProcedure
                ]);
                this.closeModal(); // Clean closure on successful completion response
            },
            error: (err) =>
            {
                this.isSubmitting.set(false);
                alert(err.error?.message || "Une erreur est survenue lors de l'enregistrement.");
            }
        });
    }
}