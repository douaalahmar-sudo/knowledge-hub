import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';

import { ArticleWorkflowDetailComponent } from './article-detail.component';

describe('ArticleWorkflowDetailComponent', () => {
  let component: ArticleWorkflowDetailComponent;
  let fixture: ComponentFixture<ArticleWorkflowDetailComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ArticleWorkflowDetailComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: 'test-id' }) } },
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ArticleWorkflowDetailComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
