import { Component, OnInit, AfterViewInit, ElementRef, ViewChild, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { ArticleService, ArticlePayload } from '../../../core/services/article.service';
import { IconComponent } from '../../../shared/icon/icon.component';


interface SelectedAttachment {
  file: File;
  name: string;
  size: number;
}


@Component(
{
    selector: 'app-article-editor',
    standalone: true,
    imports: [CommonModule, ReactiveFormsModule, RouterLink, IconComponent],
    templateUrl: './article-editor.component.html',
    styleUrl: './article-editor.component.scss'
})


export class ArticleEditorComponent implements OnInit, AfterViewInit
{
    private fb = inject(FormBuilder);
    private articleService = inject(ArticleService);
    private router = inject(Router);
    private route = inject(ActivatedRoute);

    @ViewChild('editor') editorRef!: ElementRef<HTMLDivElement>;

    articleForm!: FormGroup;
    isEditMode = false;
    isSubmitting = false;
    articleSlug: string | null = null;
    submittingStatus: 'draft' | 'published' | 'archived' | null = null;

    // File upload state (handled outside the reactive form).
    coverImageFile: File | null = null;
    coverPreviewUrl: string | null = null;
    attachments: SelectedAttachment[] = [];

    categories = [
    {
        label: 'Actualités & Annonces', value: 'news_announcements'
    },
    {
        label: 'Guides d\'intégration', value: 'onboarding_guides'
    },
    {
        label: 'Politiques & Directives', value: 'policies_guidelines'
    },
    {
        label: 'Documentation RH', value: 'hr_documentation'
    }
    ];

    ngOnInit() : void
    {
        this.initForm();
        this.articleSlug = this.route.snapshot.paramMap.get('slug');
        if (this.articleSlug)
        {
            this.isEditMode = true;
            this.loadArticle(this.articleSlug);
        }
    }

    ngAfterViewInit() : void
    {
        // Seed the contenteditable surface from the current form value (create mode).
        this.syncEditorFromForm();
    }

    private initForm() : void
    {
        this.articleForm = this.fb.group(
        {
            title: ['', [Validators.required, Validators.minLength(5)]],
            category: ['news_announcements', [Validators.required]],
            cover_image_url: [''],
            summary: [''],
            tags: [''],
            content: ['', [Validators.required]],
            status: ['draft']
        });
    }

    private loadArticle(slug: string) : void
    {
        this.articleService.getArticleBySlug(slug).subscribe(
        {
            next: (article) =>
            {
                this.articleForm.patchValue(
                {
                    title: article.title,
                    category: article.category,
                    cover_image_url: article.cover_image_url,
                    summary: article.summary,
                    tags: (article.tags || []).join(', '),
                    content: article.content,
                    status: article.status
                });
                if (article.cover_image_url)
                {
                    this.coverPreviewUrl = article.cover_image_url;
                }
                // Push the loaded HTML into the editor once the view exists.
                this.syncEditorFromForm();
            },
            error: (err) => console.error('Error loading article:', err)
        });
    }

    // ---------------- Rich text editor (contenteditable) ----------------

    private syncEditorFromForm() : void
    {
        if (this.editorRef?.nativeElement)
        {
            this.editorRef.nativeElement.innerHTML = this.articleForm.get('content')?.value || '';
        }
    }

    onEditorInput() : void
    {
        const html = this.editorRef.nativeElement.innerHTML;
        const control = this.articleForm.get('content');
        // Treat an "empty" editor (<br>, &nbsp; only) as empty for validation.
        const stripped = html.replace(/<br\s*\/?>/gi, '').replace(/&nbsp;/gi, '').replace(/<[^>]*>/g, '').trim();
        control?.setValue(stripped.length ? html : '');
        control?.markAsDirty();
    }

    format(command: string, value?: string) : void
    {
        this.editorRef.nativeElement.focus();
        document.execCommand(command, false, value);
        this.onEditorInput();
    }

    insertLink() : void
    {
        const url = window.prompt('URL du lien :', 'https://');
        if (url) this.format('createLink', url);
    }

    insertImage() : void
    {
        const url = window.prompt('URL de l\'image à insérer :', 'https://');
        if (url) this.format('insertImage', url);
    }

    // ---------------- File uploads ----------------

    onCoverSelected(event: Event) : void
    {
        const input = event.target as HTMLInputElement;
        const file = input.files && input.files[0];
        if (!file) return;
        this.coverImageFile = file;
        this.coverPreviewUrl = URL.createObjectURL(file);
        // A freshly uploaded file supersedes any pasted URL.
        this.articleForm.get('cover_image_url')?.setValue('');
    }

    removeCover() : void
    {
        this.coverImageFile = null;
        this.coverPreviewUrl = null;
        this.articleForm.get('cover_image_url')?.setValue('');
    }

    onAttachmentsSelected(event: Event) : void
    {
        const input = event.target as HTMLInputElement;
        if (!input.files) return;
        Array.from(input.files).forEach(file =>
        {
            this.attachments.push({ file, name: file.name, size: file.size });
        });
        input.value = '';
    }

    removeAttachment(index: number) : void
    {
        this.attachments.splice(index, 1);
    }

    formatBytes(bytes: number) : string
    {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    // ---------------- Save workflow ----------------

    onSubmit(status: 'draft' | 'published' | 'archived') : void
    {
        // Drafts/archives are allowed to be incomplete; publishing must be valid.
        if (status === 'published' && this.articleForm.invalid)
        {
            this.articleForm.markAllAsTouched();
            return;
        }
        if (!this.articleForm.get('title')?.value)
        {
            this.articleForm.markAllAsTouched();
            return;
        }

        this.isSubmitting = true;
        this.submittingStatus = status;

        const raw = this.articleForm.value;
        const payload: ArticlePayload =
        {
            title: raw.title,
            category: raw.category,
            summary: raw.summary,
            content: raw.content,
            status,
            tags: (raw.tags || '')
                .split(',')
                .map((t: string) => t.trim())
                .filter((t: string) => t.length),
            cover_image_url: raw.cover_image_url || undefined,
            coverImageFile: this.coverImageFile,
            attachmentFiles: this.attachments.map(a => a.file)
        };

        const request$ = this.isEditMode && this.articleSlug
            ? this.articleService.updateArticle(this.articleSlug, payload)
            : this.articleService.createArticle(payload);

        request$.subscribe(
        {
            next: () =>
            {
                this.isSubmitting = false;
                this.submittingStatus = null;
                this.router.navigate(['/dashboard/knowledge-base']);
            },
            error: (err) =>
            {
                console.error('Failed to save article:', err);
                this.isSubmitting = false;
                this.submittingStatus = null;
                alert(err?.error?.message || 'Une erreur est survenue lors de l\'enregistrement.');
            }
        });
    }
}
