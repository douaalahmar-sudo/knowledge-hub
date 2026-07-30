import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { from, of } from 'rxjs';
import { catchError, concatMap, tap } from 'rxjs/operators';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ArticleApiService } from '../../../core/services/article-api.service';
import {
  ARTICLE_FILE_FORMAT_ORDER,
  ARTICLE_FILE_FORMAT_SPECS,
  ArticleFileFormat,
  ArticleFileFormatSpec,
  CreateArticlePayload,
  formatBytes,
  validateArticleFile,
} from '../../../core/models/article.model';

type UploadStatus = 'idle' | 'uploading' | 'done' | 'failed';

/** Per-slot UI state for one of the three upload zones. */
interface FileSlotState {
  file: File | null;
  /** Client-side validation error (wrong type/too large/empty). */
  error: string | null;
  dragging: boolean;
  /** Only meaningful after the article exists and uploads have started. */
  uploadStatus: UploadStatus;
  uploadError: string | null;
}

const emptyFileSlot = (): FileSlotState => ({
  file: null,
  error: null,
  dragging: false,
  uploadStatus: 'idle',
  uploadError: null,
});

/**
 * Create-only article editor for the workflow module (ArticleApiService).
 * Draft creation, then a sequential file-upload pass against the article id
 * the API returns. Editing an existing draft is a later task — this never
 * loads one.
 *
 * Same features/article-workflow/ location and naming pattern as
 * ArticleWorkflowListComponent, for the same reason: features/articles/
 * already holds the legacy mock-backed editor for the old category/tags
 * schema, a genuinely different feature.
 */
@Component({
  selector: 'app-article-workflow-editor',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule, RouterLink, IconComponent],
  templateUrl: './article-editor.component.html',
  styleUrl: './article-editor.component.scss',
})
export class ArticleWorkflowEditorComponent {
  private fb = inject(FormBuilder);
  private api = inject(ArticleApiService);
  private router = inject(Router);

  readonly formats = ARTICLE_FILE_FORMAT_ORDER;
  readonly formatBytes = formatBytes;

  form = this.fb.group({
    title: ['', [Validators.required, Validators.maxLength(255)]],
    content_summary: [''],
    tags_metier: [''],
    criticite: ['note' as 'golden_rule' | 'note', [Validators.required]],
  });

  isSubmitting = signal(false);
  submitError = signal<string | null>(null);

  /** Set once create() succeeds — drives the upload pass and any retry. */
  createdArticleId = signal<string | null>(null);

  fileSlots = signal<Record<ArticleFileFormat, FileSlotState>>({
    pdf: emptyFileSlot(),
    infographie: emptyFileSlot(),
    video: emptyFileSlot(),
  });

  /** PDF is the source-of-truth format — required before submit is enabled. */
  hasPdf = computed(() => this.fileSlots().pdf.file !== null);

  hasFailedUpload = computed(() =>
    this.formats.some(f => this.fileSlots()[f].uploadStatus === 'failed')
  );

  // ------------------------------------------------------------- slots ---

  slot(format: ArticleFileFormat): FileSlotState {
    return this.fileSlots()[format];
  }

  spec(format: ArticleFileFormat): ArticleFileFormatSpec {
    return ARTICLE_FILE_FORMAT_SPECS[format];
  }

  private patchSlot(format: ArticleFileFormat, patch: Partial<FileSlotState>): void {
    this.fileSlots.update(s => ({ ...s, [format]: { ...s[format], ...patch } }));
  }

  onDragOver(event: DragEvent, format: ArticleFileFormat): void {
    event.preventDefault();
    event.stopPropagation();
    if (!this.slot(format).dragging) {
      this.patchSlot(format, { dragging: true });
    }
  }

  onDragLeave(event: DragEvent, format: ArticleFileFormat): void {
    event.preventDefault();
    event.stopPropagation();
    this.patchSlot(format, { dragging: false });
  }

  onDrop(event: DragEvent, format: ArticleFileFormat): void {
    event.preventDefault();
    event.stopPropagation();
    this.patchSlot(format, { dragging: false });

    const file = event.dataTransfer?.files?.[0];
    if (file) this.acceptFile(format, file);
  }

  onFileInput(event: Event, format: ArticleFileFormat): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) this.acceptFile(format, file);
    // Reset so re-selecting the same file still fires a change event.
    input.value = '';
  }

  /**
   * Validate only — unlike the procedures triptych form, nothing uploads yet.
   * The article doesn't exist until submit(), and every upload endpoint needs
   * its id in the URL, so there's nowhere to send this file until then.
   */
  private acceptFile(format: ArticleFileFormat, file: File): void {
    const error = validateArticleFile(file, ARTICLE_FILE_FORMAT_SPECS[format]);

    if (error) {
      this.patchSlot(format, { file: null, error });
      return;
    }

    this.patchSlot(format, { file, error: null, uploadStatus: 'idle', uploadError: null });
  }

  clearSlot(format: ArticleFileFormat): void {
    this.patchSlot(format, emptyFileSlot());
  }

  /** Re-upload just this slot without recreating the article. */
  retryUpload(format: ArticleFileFormat): void {
    const articleId = this.createdArticleId();
    const file = this.slot(format).file;
    if (!articleId || !file) return;

    this.patchSlot(format, { uploadStatus: 'uploading', uploadError: null });

    this.api.uploadFile(articleId, format, file).subscribe({
      next: () => this.patchSlot(format, { uploadStatus: 'done' }),
      error: (err: Error) => this.patchSlot(format, { uploadStatus: 'failed', uploadError: err.message }),
    });
  }

  // ------------------------------------------------------------ submit ---

  fieldError(control: string): string | null {
    const c = this.form.get(control);
    if (!c || !c.touched || c.valid) return null;
    if (c.errors?.['required']) return 'Ce champ est obligatoire.';
    if (c.errors?.['maxlength']) {
      return `Maximum ${c.errors['maxlength'].requiredLength} caractères.`;
    }
    return 'Valeur invalide.';
  }

  private parseTags(raw: string): string[] {
    return raw
      .split(',')
      .map(tag => tag.trim())
      .filter(tag => tag.length > 0);
  }

  submit(): void {
    this.submitError.set(null);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.submitError.set('Corrigez les champs en rouge avant d’enregistrer.');
      return;
    }

    if (!this.hasPdf()) {
      this.submitError.set('Le format PDF est obligatoire avant de créer l’article.');
      return;
    }

    this.isSubmitting.set(true);

    const raw = this.form.getRawValue();
    const payload: CreateArticlePayload = {
      title: raw.title!,
      content_summary: raw.content_summary || null,
      tags_metier: this.parseTags(raw.tags_metier ?? ''),
      criticite: raw.criticite!,
    };

    this.api.create(payload).subscribe({
      next: article => {
        this.createdArticleId.set(article.id);
        this.uploadSelectedFiles(article.id);
      },
      error: (err: Error) => {
        this.isSubmitting.set(false);
        this.submitError.set(err.message);
      },
    });
  }

  /**
   * Uploads every selected file one at a time (concatMap, not parallel) —
   * per the task, in sequence. A failed slot doesn't abort the rest: it's
   * caught, recorded on that slot, and the sequence continues so one bad
   * video doesn't also block an already-fine infographie from landing.
   */
  private uploadSelectedFiles(articleId: string): void {
    const jobs = this.formats
      .map(format => ({ format, file: this.fileSlots()[format].file }))
      .filter((job): job is { format: ArticleFileFormat; file: File } => job.file !== null);

    if (jobs.length === 0) {
      this.finishSubmitting();
      return;
    }

    from(jobs)
      .pipe(
        concatMap(({ format, file }) => {
          this.patchSlot(format, { uploadStatus: 'uploading', uploadError: null });

          return this.api.uploadFile(articleId, format, file).pipe(
            tap(() => this.patchSlot(format, { uploadStatus: 'done' })),
            catchError((err: Error) => {
              this.patchSlot(format, { uploadStatus: 'failed', uploadError: err.message });
              return of(null);
            })
          );
        })
      )
      .subscribe({ complete: () => this.finishSubmitting() });
  }

  private finishSubmitting(): void {
    this.isSubmitting.set(false);

    // All uploads (if any) succeeded: move on. If something failed, stay here
    // — the failed-slot banner and its retry button take over instead of
    // stranding the user on a detail page with no way to retry yet (there's
    // no edit mode to come back through).
    if (!this.hasFailedUpload()) {
      this.goToArticle();
    }
  }

  goToArticle(): void {
    const id = this.createdArticleId();
    if (id) this.router.navigate(['/dashboard/articles', id]);
  }
}
