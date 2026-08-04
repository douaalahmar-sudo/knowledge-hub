import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { environment } from '../../../environments/environment';

import { PrintAuthorizationService } from './print-authorization.service';

const ARTICLE_ID = 'art-1';
const AUTHORIZE_URL = `${environment.apiUrl}/v1/articles/${ARTICLE_ID}/print-authorizations`;
const CONSUME_URL = (id: string) => `${environment.apiUrl}/v1/print-authorizations/${id}/consume`;

/**
 * The client half of §11.1: holding a grant, revealing the print path only
 * while it lives, and giving it up afterwards.
 *
 * The server half — who may authorize, expiry, single use — is enforced in
 * PrintAuthorizationTest and is not re-asserted here; this covers only what the
 * browser is responsible for.
 */
describe('PrintAuthorizationService (§11.1)', () => {
  let service: PrintAuthorizationService;
  let httpMock: HttpTestingController;
  let printSpy: jasmine.Spy;

  function grantPayload(overrides: Record<string, unknown> = {}) {
    return {
      id: 'grant-1',
      article_id: ARTICLE_ID,
      expires_at: new Date(Date.now() + 300_000).toISOString(),
      matricule: 'FLK-2291',
      holder_name: 'Douaa Lahmar',
      ...overrides,
    };
  }

  function authorize(overrides: Record<string, unknown> = {}): void {
    service.authorize(ARTICLE_ID).subscribe({ error: () => undefined });
    httpMock.expectOne(AUTHORIZE_URL).flush(grantPayload(overrides));
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(PrintAuthorizationService);
    httpMock = TestBed.inject(HttpTestingController);
    // window.print() would block the Karma runner on a real dialogue.
    printSpy = spyOn(window, 'print').and.stub();
  });

  afterEach(() => {
    service.release();
    TestBed.resetTestingModule();
  });

  it('holds no grant and sets no attribute until one is issued', () => {
    expect(service.isAuthorized()).toBe(false);
    expect(document.documentElement.hasAttribute('data-print-authorized')).toBe(false);
  });

  it('marks the document authorized while a grant is held', () => {
    authorize();

    expect(service.isAuthorized()).toBe(true);
    expect(service.current()?.matricule).toBe('FLK-2291');
    // What the print CSS keys off — see styles.scss and print-sheet.
    expect(document.documentElement.getAttribute('data-print-authorized')).toBe('true');
  });

  it('consumes the grant and opens the print dialogue', () => {
    authorize();

    service.print();
    httpMock.expectOne(CONSUME_URL('grant-1')).flush({ used_at: new Date().toISOString() });

    expect(printSpy).toHaveBeenCalled();
  });

  /**
   * The grant is single-use server-side, so the client must not keep behaving
   * as though it still holds one — a second copy needs a second authorization,
   * which is what makes "COPIE TRACÉE" mean one traced copy.
   */
  it('releases the authorization once printing has happened', () => {
    authorize();

    service.print();
    httpMock.expectOne(CONSUME_URL('grant-1')).flush({ used_at: new Date().toISOString() });

    expect(service.isAuthorized()).toBe(false);
    expect(document.documentElement.hasAttribute('data-print-authorized')).toBe(false);
  });

  /**
   * The user is authorized; a failed *record* of the print is a monitoring
   * problem, not a reason to deny them. Same trade AuditLogger makes server-side.
   */
  it('still prints when the consume call fails', () => {
    authorize();

    service.print();
    httpMock.expectOne(CONSUME_URL('grant-1'))
      .flush('nope', { status: 500, statusText: 'Server Error' });

    expect(printSpy).toHaveBeenCalled();
    expect(service.isAuthorized()).toBe(false);
  });

  it('gives up the authorization when the grant expires', fakeAsync(() => {
    authorize({ expires_at: new Date(Date.now() + 1_000).toISOString() });

    expect(service.isAuthorized()).toBe(true);

    tick(1_001);

    expect(service.isAuthorized()).toBe(false);
    expect(document.documentElement.hasAttribute('data-print-authorized')).toBe(false);
  }));

  it('refuses a grant that is already expired when it arrives', () => {
    authorize({ expires_at: new Date(Date.now() - 1_000).toISOString() });

    expect(service.isAuthorized()).toBe(false);
  });

  it('does nothing when asked to print without a grant', () => {
    service.print();

    httpMock.expectNone(CONSUME_URL('grant-1'));
    expect(printSpy).not.toHaveBeenCalled();
  });

  /**
   * A grant that survived a reload would be a standing permission with extra
   * steps, which is precisely the model this was chosen over.
   */
  it('never persists the grant to storage', () => {
    authorize();

    const stored = JSON.stringify([
      ...Object.entries(localStorage),
      ...Object.entries(sessionStorage),
    ]);

    expect(stored).not.toContain('grant-1');
    expect(stored).not.toContain('FLK-2291');
  });

  it('surfaces the API refusal rather than swallowing it', () => {
    let message: string | undefined;

    service.authorize(ARTICLE_ID).subscribe({
      error: (err: { status: number }) => (message = String(err.status)),
    });

    httpMock.expectOne(AUTHORIZE_URL).flush(
      { message: 'Seul un administrateur ou un propriétaire des données peut autoriser une impression.' },
      { status: 403, statusText: 'Forbidden' }
    );

    // The caller shows the French message; a swallowed 403 would leave a print
    // button that silently does nothing.
    expect(message).toBe('403');
    expect(service.isAuthorized()).toBe(false);
  });
});
