import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { ArticleWorkflowEditorComponent } from './article-editor.component';

describe('ArticleWorkflowEditorComponent', () => {
  let component: ArticleWorkflowEditorComponent;
  let fixture: ComponentFixture<ArticleWorkflowEditorComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ArticleWorkflowEditorComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();

    fixture = TestBed.createComponent(ArticleWorkflowEditorComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
