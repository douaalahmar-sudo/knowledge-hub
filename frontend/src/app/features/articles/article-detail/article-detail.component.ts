import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterModule } from '@angular/router';
import { ArticleService, Article } from '../../../core/services/article.service';


@Component(
{
    selector: 'app-article-detail',
    standalone: true,
imports: [CommonModule, RouterModule], templateUrl: './article-detail.component.html',
    styleUrls: ['./article-detail.component.scss']
})


export class ArticleDetailComponent implements OnInit
{
    article: Article | null = null;
    isLoading = true;

    constructor(
    private route: ActivatedRoute,
    private articleService: ArticleService
    )
    {
    }
    ngOnInit() : void
    {
        const slug = this.route.snapshot.paramMap.get('slug');
        if (slug)
        {
            this.articleService.getArticleBySlug(slug).subscribe(
            {
                next: (art) =>
                {
                    this.article = art;
                    this.isLoading = false;
                },
                error: (err) =>
                {
                    console.error('Error loading article', err);
                    this.isLoading = false;
                }
            });
        }
    }
}