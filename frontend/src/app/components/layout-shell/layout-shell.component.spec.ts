import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { LayoutShellComponent } from './layout-shell.component';

/**
 * This file previously held an early, superseded draft of
 * KaizenReportsComponent's source — no describe() at all — which failed to
 * resolve ./kaizen-reports.component.html from this directory and so stopped
 * Karma from starting for the entire project. The real, current component is
 * untouched at pages/kaizen-reports/kaizen-reports.component.ts (it has since
 * moved to KaizenReportService/ProcedureService and is what app.routes.ts
 * loads). This is the plain "should create" spec the file was meant to hold,
 * matching the other component specs in the project.
 */
describe('LayoutShellComponent', () => {
  let component: LayoutShellComponent;
  let fixture: ComponentFixture<LayoutShellComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [LayoutShellComponent],
      // AuthService and the embedded global-search / notification-center
      // children need HttpClient; the sidebar's routerLinks and the
      // constructor's router.events subscription need a Router.
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();

    fixture = TestBed.createComponent(LayoutShellComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
