import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { ArticleService, Article, ArticleCategory } from '../../../core/services/article.service';
import { IconComponent } from '../../../shared/icon/icon.component';


interface CategoryCard {
  value: '' | ArticleCategory;
  label: string;
  icon: string; // icon name resolved by <app-icon>
  description: string;
}


@Component(
{
    selector: 'app-article-list',
    standalone: true,
    imports: [CommonModule, RouterModule, FormsModule, IconComponent],
    templateUrl: './article-list.component.html',
    styleUrls: ['./article-list.component.scss']
})


export class ArticleListComponent implements OnInit
{
    private articleService = inject(ArticleService);

    articles: Article[] = [];
    isLoading = true;
    selectedCategory: '' | ArticleCategory = '';
    searchQuery = '';

    // 4 official categories + an "All" entry, rendered as filter cards.
    categoryCards: CategoryCard[] =
    [
        { value: '', label: 'Tous les articles', icon: 'book', description: 'Toute la base de connaissances' },
        { value: 'news_announcements', label: 'Actualités & Annonces', icon: 'megaphone', description: 'Communications officielles' },
        { value: 'onboarding_guides', label: 'Guides d\'intégration', icon: 'graduation', description: 'Onboarding des collaborateurs' },
        { value: 'policies_guidelines', label: 'Politiques & Directives', icon: 'policy', description: 'Règles & conformité' },
        { value: 'hr_documentation', label: 'Documentation RH', icon: 'folder', description: 'Ressources humaines' }
    ];

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
            search: this.searchQuery,
            status: 'published' // Employee portal shows published items only.
        })
        .subscribe(
        {
            next: (res) =>
            {
                this.articles = res?.data ?? res ?? [];
                this.isLoading = false;
            },
            error: (err) =>
            {
                console.error('Error fetching articles', err);
                this.articles = [];
                this.isLoading = false;
            }
        });
    }

    onCategoryChange(catValue: '' | ArticleCategory) : void
    {
        this.selectedCategory = catValue;
        this.loadArticles();
    }

    onSearch() : void
    {
        this.loadArticles();
    }

    clearSearch() : void
    {
        this.searchQuery = '';
        this.loadArticles();
    }

    // Featured / pinned hero: only on the unfiltered landing view.
    get showFeatured() : boolean
    {
        return !this.selectedCategory && !this.searchQuery.trim() && this.featuredArticles.length > 0;
    }

    get featuredArticles() : Article[]
    {
        return this.articles
            .filter(a => a.is_featured || a.category === 'news_announcements')
            .slice(0, 3);
    }

    // Main feed excludes whatever is currently promoted to the hero.
    get feedArticles() : Article[]
    {
        if (!this.showFeatured) return this.articles;
        const featuredIds = new Set(this.featuredArticles.map(a => a.id));
        return this.articles.filter(a => !featuredIds.has(a.id));
    }

    getCategoryBadge(category: string) : { label: string; class: string }
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
