import { TestBed } from '@angular/core/testing';
import { AppComponent } from './app.component';

describe('AppComponent', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AppComponent],
    }).compileComponents();
  });

  // The CLI's two scaffold assertions (a `title` property and a
  // "Hello, frontend" <h1>) used to live here. Both were dropped long ago:
  // AppComponent is now a bare <router-outlet> host with no members, so those
  // tests referenced a property that doesn't compile and markup that no longer
  // exists — enough to fail type-checking for the whole spec project.
  it('should create the app', () => {
    const fixture = TestBed.createComponent(AppComponent);
    const app = fixture.componentInstance;
    expect(app).toBeTruthy();
  });
});
