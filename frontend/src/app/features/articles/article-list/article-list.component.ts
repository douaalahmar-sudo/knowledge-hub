import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { ArticleService, Article } from '../../../core/services/article.service';


@Component(
{
    selector: 'app-article-list',
    standalone: true,
imports: [CommonModule, RouterModule, FormsModule], templateUrl: './article-list.component.html',
    styleUrls: ['./article-list.component.scss']
})


export class ArticleListComponent implements OnInit
{
    articles: Article[] = [];
    isLoading = true;
    selectedCategory = '';
    searchQuery = '';
    categories = [
    {
        value: '', label: 'Tous les articles'
    },
    {
        value: 'news_announcements', label: '📢 Actualités & Annonces'
    },
    {
        value: 'onboarding_guides', label: '🚀 Guides d\'intégration' },
        {
            value: 'policies_guidelines', label: '📜 Politiques & Directives'
        },
        {
            value: 'hr_documentation', label: '📂 Documentation RH'
        }
        ];

        constructor(private articleService: ArticleService)
        {
        }
        ngOnInit() : void
        {
            this.loadArticles();
        }
        loadArticles() : void
        {
            this.isLoading = true;
            this.articleService.getArticles(
            {
                category: this.selectedCategory,
                search: this.searchQuery
            })
            .subscribe(
            {
                next: (res) =>
                {
                    this.articles = res.data || res;
                    this.isLoading = false;
                },
                error: (err) =>
                {
                    console.error('Error fetching articles', err);
                    this.isLoading = false;
                }
            });
        }
        onCategoryChange(catValue: string) : void
        {
            this.selectedCategory = catValue;
            this.loadArticles();
        }
        onSearch() : void
        {
            this.loadArticles();
        }
        getCategoryBadge(category: string):
        {
            label: string; class: string
        }
        {
            switch (category)
            {
                case 'news_announcements':
                return { label: 'Annonce', class: 'bg-blue-100 text-blue-800' };
                case 'onboarding_guides':
                return { label: 'Onboarding', class: 'bg-green-100 text-green-800' };
                case 'policies_guidelines':
                return { label: 'Politique', class: 'bg-purple-100 text-purple-800' };
                default:
                return { label: 'Doc RH', class: 'bg-gray-100 text-gray-800' };
            }
        }
    }