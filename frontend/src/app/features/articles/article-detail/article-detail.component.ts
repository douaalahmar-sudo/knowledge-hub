import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { ArticleService, Article } from '../../../core/services/article.service';
import { ProcedureService } from '../../../services/procedure.service';
import { IconComponent } from '../../../shared/icon/icon.component';


@Component(
{
    selector: 'app-article-detail',
    standalone: true,
    imports: [CommonModule, RouterModule, IconComponent],
    templateUrl: './article-detail.component.html',
    styleUrls: ['./article-detail.component.scss']
})


export class ArticleDetailComponent implements OnInit
{
    private route = inject(ActivatedRoute);
    private articleService = inject(ArticleService);
    private procedureService = inject(ProcedureService);

    article: Article | null = null;
    isLoading = true;
    relatedProcedures = signal<any[]>([]);

    ngOnInit() : void
    {
        const slug = this.route.snapshot.paramMap.get('slug');
        if (!slug)
        {
            this.isLoading = false;
            return;
        }

        this.articleService.getArticleBySlug(slug).subscribe(
        {
            next: (art: any) =>
            {
                this.article = art?.data ?? art;
                this.isLoading = false;
                this.loadRelatedProcedures();
            },
            error: (err) =>
            {
                console.error('Error loading article', err);
                this.isLoading = false;
            }
        });
    }

    /** Reading time: prefer server value, otherwise compute words / 200 WPM. */
    get readingTime() : number
    {
        if (this.article?.reading_time_minutes)
        {
            return this.article.reading_time_minutes;
        }
        return this.articleService.estimateReadingTime(this.article?.content);
    }

    /**
     * Related procedures: match the article's tags against procedure name/module.
     * Falls back to a few recent procedures when nothing matches.
     */
    private loadRelatedProcedures() : void
    {
        this.procedureService.getProcedures().subscribe(
        {
            next: (procedures) =>
            {
                const list = Array.isArray(procedures) ? procedures : [];
                const tags = (this.article?.tags || []).map(t => t.toLowerCase());

                let related = list;
                if (tags.length)
                {
                    related = list.filter(p =>
                        tags.some(tag =>
                            (p.name || '').toLowerCase().includes(tag) ||
                            (p.module || '').toLowerCase().includes(tag)
                        )
                    );
                }
                if (!related.length)
                {
                    related = list.slice(0, 3);
                }
                this.relatedProcedures.set(related.slice(0, 4));
            },
            error: () => this.relatedProcedures.set([])
        });
    }
}
