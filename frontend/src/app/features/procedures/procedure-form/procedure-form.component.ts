import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ProcedureApiService } from '../../../core/services/procedure-api.service';
import {
  FormatSpec,
  Procedure,
  ProcedurePayload,
  TRIPTYCH_ORDER,
  TRIPTYCH_SPECS,
  TriptychFormat,
  formatBytes,
  validateFile,
} from '../../../core/models/procedure.model';

/** Per-slot UI state for one of the three upload zones. */
interface SlotState {
  /** File chosen but not yet sent. */
  file: File | null;
  /** 0-100 while uploading; null when idle. */
  progress: number | null;
  /** Validation or server error for this slot. */
  error: string | null;
  /** Path returned by the API once the upload succeeded. */
  uploadedPath: string | null;
  /** True while a drag is hovering this zone. */
  dragging: boolean;
  /** Asset already stored on the procedure (edit mode). */
  existingUrl: string | null;
}

const emptySlot = (): SlotState => ({
  file: null,
  progress: null,
  error: null,
  uploadedPath: null,
  dragging: false,
  existingUrl: null,
});

@Component({
  selector: 'app-procedure-form',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterLink, IconComponent],
  templateUrl: './procedure-form.component.html',
  styleUrl: './procedure-form.component.scss',
})
export class ProcedureFormComponent implements OnInit {
  private fb = inject(FormBuilder);
  private api = inject(ProcedureApiService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);

  readonly formats = TRIPTYCH_ORDER;
  readonly specs = TRIPTYCH_SPECS;
  readonly formatBytes = formatBytes;

  form!: FormGroup;

  procedureId = signal<number | null>(null);
  isEditMode = computed(() => this.procedureId() !== null);

  isLoading = signal(false);
  isSubmitting = signal(false);
  loadError = signal<string | null>(null);
  submitError = signal<string | null>(null);

  slots = signal<Record<TriptychFormat, SlotState>>({
    pdf: emptySlot(),
    video: emptySlot(),
    infographic: emptySlot(),
  });

  /** Modules/categories offered in the selects — mirrors the seeded taxonomy. */
  readonly modules = ['Operations', 'Logistique', 'Service Client', 'Stock', 'Conformité', 'RH'];
  readonly categories = ['Procédure standard', 'Consigne de sécurité', 'Mode opératoire', 'Politique interne', 'Guide de formation'];
  readonly departments = ['Caisse', 'Réception', 'Rayon frais', 'Entrepôt', 'Service Client', 'Direction'];

  /** At least one format must be present for the triptych to be worth viewing. */
  hasAnyAsset = computed(() => {
    const s = this.slots();
    return TRIPTYCH_ORDER.some(f => s[f].file || s[f].uploadedPath || s[f].existingUrl);
  });

  /** Block submit while any slot is mid-flight. */
  isUploading = computed(() => TRIPTYCH_ORDER.some(f => this.slots()[f].progress !== null));

  ngOnInit(): void {
    this.form = this.fb.group({
      name: ['', [Validators.required, Validators.maxLength(255)]],
      reference_code: ['', [Validators.required, Validators.maxLength(50)]],
      module: ['Operations', [Validators.required]],
      category: [''],
      department: [''],
      description: ['', [Validators.maxLength(5000)]],
      status: ['En attente'],
      version: ['1.0', [Validators.maxLength(20)]],
    });

    const idParam = this.route.snapshot.paramMap.get('id');
    if (idParam) {
      this.procedureId.set(Number(idParam));
      this.loadProcedure(Number(idParam));
    } else {
      // Pre-fill a plausible unique reference so the "Zéro doublon" rule is not
      // the first thing a new author trips over.
      const year = new Date().getFullYear();
      const seq = String(Math.floor(1 + Math.random() * 999)).padStart(3, '0');
      this.form.patchValue({ reference_code: `SOP-PR-${year}-${seq}` });
    }
  }

  private loadProcedure(id: number): void {
    this.isLoading.set(true);
    this.loadError.set(null);

    this.api.get(id).subscribe({
      next: (p: Procedure) => {
        this.isLoading.set(false);
        this.form.patchValue({
          name: p.name,
          reference_code: p.reference_code,
          module: p.module,
          category: p.category ?? '',
          department: p.department ?? '',
          description: p.description ?? '',
          status: p.status,
          version: p.version,
        });

        this.slots.update(s => ({
          pdf: { ...s.pdf, existingUrl: p.triptych_urls?.pdf ?? null },
          video: { ...s.video, existingUrl: p.triptych_urls?.video ?? null },
          infographic: { ...s.infographic, existingUrl: p.triptych_urls?.infographic ?? null },
        }));
      },
      error: (err: Error) => {
        this.isLoading.set(false);
        this.loadError.set(err.message);
      },
    });
  }

  // ---------------------------------------------------------------- slots ---

  private patchSlot(format: TriptychFormat, patch: Partial<SlotState>): void {
    this.slots.update(s => ({ ...s, [format]: { ...s[format], ...patch } }));
  }

  slot(format: TriptychFormat): SlotState {
    return this.slots()[format];
  }

  spec(format: TriptychFormat): FormatSpec {
    return TRIPTYCH_SPECS[format];
  }

  onDragOver(event: DragEvent, format: TriptychFormat): void {
    event.preventDefault();
    event.stopPropagation();
    if (!this.slot(format).dragging) {
      this.patchSlot(format, { dragging: true });
    }
  }

  onDragLeave(event: DragEvent, format: TriptychFormat): void {
    event.preventDefault();
    event.stopPropagation();
    this.patchSlot(format, { dragging: false });
  }

  onDrop(event: DragEvent, format: TriptychFormat): void {
    event.preventDefault();
    event.stopPropagation();
    this.patchSlot(format, { dragging: false });

    const file = event.dataTransfer?.files?.[0];
    if (file) this.acceptFile(format, file);
  }

  onFileInput(event: Event, format: TriptychFormat): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) this.acceptFile(format, file);
    // Reset so re-selecting the same file still fires a change event.
    input.value = '';
  }

  /** Validate client-side, then start the upload immediately. */
  private acceptFile(format: TriptychFormat, file: File): void {
    const spec = TRIPTYCH_SPECS[format];
    const error = validateFile(file, spec);

    if (error) {
      this.patchSlot(format, { file: null, error, progress: null, uploadedPath: null });
      return;
    }

    this.patchSlot(format, { file, error: null, progress: 0, uploadedPath: null });
    this.upload(format, file);
  }

  private upload(format: TriptychFormat, file: File): void {
    const spec = TRIPTYCH_SPECS[format];
    // In edit mode the server can attach the asset directly; on create we hold
    // the path and send it with the POST.
    const bindTo = this.procedureId() ?? undefined;

    this.api.uploadAsset(spec, file, bindTo).subscribe({
      next: event => {
        if (event.kind === 'progress') {
          this.patchSlot(format, { progress: event.percent });
        } else {
          const path = event.result.paths?.[spec.pathKey] ?? null;
          this.patchSlot(format, {
            progress: null,
            uploadedPath: path,
            error: path ? null : 'Le serveur n’a retourné aucun chemin pour ce fichier.',
          });
        }
      },
      error: (err: Error) => {
        this.patchSlot(format, { progress: null, uploadedPath: null, error: err.message });
      },
    });
  }

  /** Clear a slot. Does not delete an already-stored asset server-side. */
  clearSlot(format: TriptychFormat): void {
    this.patchSlot(format, { file: null, progress: null, error: null, uploadedPath: null });
  }

  retry(format: TriptychFormat): void {
    const file = this.slot(format).file;
    if (file) {
      this.patchSlot(format, { error: null, progress: 0 });
      this.upload(format, file);
    }
  }

  // --------------------------------------------------------------- submit ---

  /** Field-level error text for the template. */
  fieldError(control: string): string | null {
    const c = this.form.get(control);
    if (!c || !c.touched || c.valid) return null;
    if (c.errors?.['required']) return 'Ce champ est obligatoire.';
    if (c.errors?.['maxlength']) {
      return `Maximum ${c.errors['maxlength'].requiredLength} caractères.`;
    }
    return 'Valeur invalide.';
  }

  submit(): void {
    this.submitError.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.submitError.set('Corrigez les champs en rouge avant d’enregistrer.');
      return;
    }

    if (this.isUploading()) {
      this.submitError.set('Patientez : un téléversement est encore en cours.');
      return;
    }

    if (!this.hasAnyAsset()) {
      this.submitError.set('Ajoutez au moins un format (PDF, vidéo ou infographie).');
      return;
    }

    const slots = this.slots();
    const payload: ProcedurePayload = {
      ...this.form.getRawValue(),
      category: this.form.value.category || null,
      department: this.form.value.department || null,
      description: this.form.value.description || null,
    };

    // Attach any path produced during this session. In edit mode the upload
    // endpoint already persisted them, so only newly uploaded ones matter.
    for (const format of TRIPTYCH_ORDER) {
      const path = slots[format].uploadedPath;
      if (path) payload[TRIPTYCH_SPECS[format].pathKey] = path;
    }

    this.isSubmitting.set(true);

    const id = this.procedureId();
    const request$ = id ? this.api.update(id, payload) : this.api.create(payload);

    request$.subscribe({
      next: (procedure: Procedure) => {
        this.isSubmitting.set(false);
        this.router.navigate(['/dashboard/procedures', procedure.id]);
      },
      error: (err: Error) => {
        this.isSubmitting.set(false);
        this.submitError.set(err.message);
      },
    });
  }
}
