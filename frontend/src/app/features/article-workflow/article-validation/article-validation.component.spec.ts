import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { ArticleWorkflowValidationComponent } from './article-validation.component';

describe('ArticleWorkflowValidationComponent', () => {
  let component: ArticleWorkflowValidationComponent;
  let fixture: ComponentFixture<ArticleWorkflowValidationComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ArticleWorkflowValidationComponent],
      // Same providers as ArticleWorkflowListComponent's spec: ArticleApiService
      // needs HttpClient, the template's routerLinks need a Router.
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();

    fixture = TestBed.createComponent(ArticleWorkflowValidationComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
