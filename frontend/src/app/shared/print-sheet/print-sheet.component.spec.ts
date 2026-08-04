import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { environment } from '../../../environments/environment';

import { Article } from '../../core/models/article.model';
import { PrintAuthorizationService } from '../../core/services/print-authorization.service';
import { PrintSheetComponent } from './print-sheet.component';

const AUTHORIZE_URL = (id: string) => `${environment.apiUrl}/v1/articles/${id}/print-authorizations`;

/**
 * §11.1's authorized print: the banner, the trace number and the 24-hour
 * notice, plus the rule that none of it exists without a server-issued grant.
 *
 * The wording is asserted verbatim against the cahier des charges. It is a
 * legal notice on a document that leaves the building on paper — paraphrasing
 * it in a later refactor is exactly the regression worth failing a build over.
 */
describe('PrintSheetComponent (§11.1)', () => {
  let fixture: ComponentFixture<PrintSheetComponent>;
  let service: PrintAuthorizationService;
  let httpMock: HttpTestingController;

  const article = {
    id: 'art-1',
    title: 'Procédure d’ouverture de caisse',
    version: 3,
    criticite: 'golden_rule',
    content_summary: 'Résumé de la procédure.',
  } as unknown as Article;

  function grant(matricule = 'FLK-2291'): void {
    service.authorize(article.id).subscribe();

    httpMock.expectOne(AUTHORIZE_URL(article.id)).flush({
      id: 'grant-1',
      article_id: article.id,
      // Comfortably in the future so the expiry timer cannot fire mid-test.
      expires_at: new Date(Date.now() + 300_000).toISOString(),
      matricule,
      holder_name: 'Douaa Lahmar',
    });

    fixture.detectChanges();
  }

  /** The sheet relocates its host to <body>, so it is not under the fixture. */
  function sheet(): HTMLElement {
    return fixture.componentInstance['host'].nativeElement;
  }

  function text(): string {
    return sheet().textContent?.replace(/\s+/g, ' ').trim() ?? '';
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [PrintSheetComponent],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    fixture = TestBed.createComponent(PrintSheetComponent);
    service = TestBed.inject(PrintAuthorizationService);
    httpMock = TestBed.inject(HttpTestingController);

    fixture.componentInstance.article = article;
    fixture.detectChanges();
  });

  afterEach(() => {
    service.release();
    fixture.destroy();
    TestBed.resetTestingModule();
  });

  it('renders nothing at all without a grant', () => {
    // §11's default: no authorization, no content to print, no banner to
    // photograph off a screen.
    expect(text()).toBe('');
    expect(document.documentElement.hasAttribute('data-print-authorized')).toBe(false);
  });

  it('renders the §11.1 banner with the matricule as the copy-trace number', () => {
    grant('FLK-2291');

    expect(text()).toContain(
      "DOCUMENT PROPRIÉTÉ EXCLUSIVE D'AZIZA - DIFFUSION INTERDITE - COPIE TRACÉE N° FLK-2291"
    );
  });

  it('repeats the banner as both a header and a footer', () => {
    grant();

    expect(sheet().querySelector('.ps__banner--top')).not.toBeNull();
    expect(sheet().querySelector('.ps__banner--bottom')).not.toBeNull();
  });

  it('renders the 24-hour legal notice verbatim', () => {
    grant();

    expect(text()).toContain(
      'Version papier valide pour une durée de 24 heures uniquement. Pour accéder à la '
      + 'version officielle faisant foi, veuillez vous connecter au Knowledge Hub.'
    );
  });

  /**
   * The trace number comes from the server's grant, never from the local
   * session: the client must not be the source of the identity it stamps on
   * paper.
   */
  it('takes the trace number from the grant rather than any local state', () => {
    grant('AZ-0007');

    expect(text()).toContain('COPIE TRACÉE N° AZ-0007');
    expect(text()).not.toContain('FLK-2291');
  });

  it('identifies the document and the person who printed it', () => {
    grant();

    const printed = text();

    expect(printed).toContain('Procédure d’ouverture de caisse');
    expect(printed).toContain('Douaa Lahmar');
    expect(printed).toContain('Résumé de la procédure.');
  });

  it('prints the infographic when that is the format on screen', () => {
    fixture.componentInstance.infographicUrl = 'blob:fake-infographic';
    grant();

    const figure: HTMLImageElement | null = sheet().querySelector('.ps__figure');

    // The one document asset in our own DOM. The PDF is a cross-origin iframe
    // and deliberately does not print — see the component docblock.
    expect(figure).not.toBeNull();
    expect(figure!.getAttribute('src')).toBe('blob:fake-infographic');
  });

  it('is hidden on screen even while a grant is held', () => {
    grant();

    // It exists to be printed. Showing it in the viewer would put a second,
    // worse copy of the article under the real one.
    expect(getComputedStyle(sheet()).display).toBe('none');
  });

  it('moves its host to <body> so §11 needs exactly one exception', () => {
    // `body > *` is what the global rule hides; being a direct child is what
    // lets one selector reveal this without un-hiding an ancestor chain.
    expect(sheet().parentElement).toBe(document.body);
  });

  it('removes itself from <body> when destroyed', () => {
    const host = sheet();

    fixture.destroy();

    expect(host.parentElement).toBeNull();
  });
});
