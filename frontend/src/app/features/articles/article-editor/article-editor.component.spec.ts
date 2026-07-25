import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, ActivatedRoute } from '@angular/router';
import { ArticleService, Article } from '../../../core/services/article.service';


@Component(
{
    selector: 'app-article-editor',
    standalone: true,
imports: [CommonModule, ReactiveFormsModule], templateUrl: './article-editor.component.html',
    styleUrls: ['./article-editor.component.scss']
})


export class ArticleEditorComponent implements OnInit
{
    articleForm!: FormGroup;
    isSubmitting = false;
    isEditMode = false;
    articleId: string | null = null;
    categories = [
    {
        value: 'news_announcements', label: 'Actualités & Annonces'
    },
    {
        value: 'onboarding_guides', label: 'Guides d\'intégration (Onboarding)' },
        {
            value: 'policies_guidelines', label: 'Politiques & Directives'
        },
        {
            value: 'hr_documentation', label: 'Documentation RH'
        }
        ];

        constructor(
        private fb: FormBuilder,
        private articleService: ArticleService,
        private router: Router,
        private route: ActivatedRoute
        )
        {
        }
        ngOnInit() : void
        {
            this.initForm();
            const slug = this.route.snapshot.paramMap.get('slug');
            if (slug)
            {
                this.isEditMode = true;
                this.loadArticle(slug);
            }
        }
        private initForm() : void
        {
            this.articleForm = this.fb.group(
            {
                title: ['', [Validators.required, Validators.maxLength(255)]],
                category: ['news_announcements', Validators.required],
                summary: [''],
                content: ['', Validators.required],
                cover_image_url: [''],
                status: ['draft', Validators.required]
            });
        }
        private loadArticle(slug: string) : void
        {
            this.articleService.getArticleBySlug(slug).subscribe(
            {
                next: (article) =>
                {
                    this.articleId = article.id || null;
                    this.articleForm.patchValue(
                    {
                        title: article.title,
                        category: article.category,
                        summary: article.summary,
                        content: article.content,
                        cover_image_url: article.cover_image_url,
                        status: article.status
                    });
                },
                error: (err) => console.error('Error loading article', err)
            });
        }
        onSubmit(status: 'draft' | 'published') : void
        {
            if (this.articleForm.invalid) return;
            this.isSubmitting = true;
            const formData =
            {
                ...this.articleForm.value,
                status
            };
            if (this.isEditMode && this.articleId)
            {
                this.articleService.updateArticle(this.articleId, formData).subscribe(
                {
                    next: () => this.handleSuccess(),
                    error: (err) => this.handleError(err)
                });
            }
            else
            {
                this.articleService.createArticle(formData).subscribe(
                {
                    next: () => this.handleSuccess(),
                    error: (err) => this.handleError(err)
                });
            }
        }
        private handleSuccess() : void
        {
            this.isSubmitting = false;
            this.router.navigate(['/knowledge-base']);
        }
        private handleError(err: any) : void
        {
            this.isSubmitting = false;
            console.error('Failed to save article', err);
        }
    }