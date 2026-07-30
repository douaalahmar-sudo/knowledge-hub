import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { ArticleWorkflowListComponent } from './article-list.component';

describe('ArticleWorkflowListComponent', () => {
  let component: ArticleWorkflowListComponent;
  let fixture: ComponentFixture<ArticleWorkflowListComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ArticleWorkflowListComponent],
      // ArticleApiService needs HttpClient; the template's routerLink needs a
      // Router. No spec elsewhere in this project exercises an HttpClient-backed
      // service yet, so there's no existing convention to match here.
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();

    fixture = TestBed.createComponent(ArticleWorkflowListComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
