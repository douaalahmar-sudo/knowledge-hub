import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { environment } from '../../environments/environment';

import { AuthService } from './auth.service';

const ME_URL = `${environment.apiUrl}/v1/auth/me`;

/**
 * AuthService reads localStorage in a field initializer, so the cached session
 * has to be in place *before* TestBed.inject() constructs it.
 */
function seedSession(user: Record<string, unknown> | null, token: string | null): void {
  localStorage.clear();
  if (token) localStorage.setItem('auth_token', token);
  if (user) localStorage.setItem('current_user', JSON.stringify(user));
}

/** A session shaped like one cached BEFORE access_role was persisted. */
const legacySession = {
  id: 7,
  name: 'Douaa L.',
  email: 'douaa@flesk.com',
  matricule: 'M-0042',
  role: 'validator',
  tenant: { name: 'FLESK Store #101 - Tunis' },
};

describe('AuthService', () => {
  let service: AuthService;
  let http: HttpTestingController;

  function build(): void {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  }

  afterEach(() => {
    localStorage.clear();
  });

  it('should be created', () => {
    seedSession(null, null);
    build();
    expect(service).toBeTruthy();
  });

  describe('backfillAccessRole', () => {
    it('fetches /me and backfills a session missing access_role', () => {
      seedSession(legacySession, 'token-abc');
      build();

      expect(service.accessRole()).toBeNull();

      service.backfillAccessRole();

      const req = http.expectOne(ME_URL);
      expect(req.request.method).toBe('GET');
      req.flush({ user: { ...legacySession, access_role: 'qualite' } });

      expect(service.accessRole()).toBe('qualite');
      expect(service.hasAccessRole(['qualite'])).toBeTrue();

      // Persisted, so the next app load short-circuits instead of re-fetching.
      const stored = JSON.parse(localStorage.getItem('current_user')!);
      expect(stored.access_role).toBe('qualite');

      http.verify();
    });

    it('leaves the rest of the cached session untouched', () => {
      seedSession(legacySession, 'token-abc');
      build();

      service.backfillAccessRole();
      http.expectOne(ME_URL).flush({ user: { ...legacySession, access_role: 'admin' } });

      const user = service.currentUser();
      expect(user.matricule).toBe('M-0042');
      expect(user.role).toBe('validator');
      expect(user.tenant.name).toBe('FLESK Store #101 - Tunis');
      http.verify();
    });

    // --- the redundancy guards ------------------------------------------
    // These assert on a *counted* request total rather than expectNone(),
    // which registers no Jasmine expectation and so would report a green,
    // vacuous pass even if the guard it is meant to protect were deleted.
    // `match(() => true)` collects every outstanding request, whatever its URL.
    function openRequestCount(): number {
      return http.match(() => true).length;
    }

    it('issues NO request when the session already has access_role', () => {
      seedSession({ ...legacySession, access_role: 'responsable_departement' }, 'token-abc');
      build();

      service.backfillAccessRole();

      expect(openRequestCount()).toBe(0);
      expect(service.accessRole()).toBe('responsable_departement');
      http.verify();
    });

    it('issues no request when there is no token', () => {
      seedSession(legacySession, null);
      build();

      service.backfillAccessRole();

      expect(openRequestCount()).toBe(0);
      http.verify();
    });

    it('issues no request when there is no cached user', () => {
      seedSession(null, 'token-abc');
      build();

      service.backfillAccessRole();

      expect(openRequestCount()).toBe(0);
      http.verify();
    });

    it('issues exactly one request when the field is missing', () => {
      seedSession(legacySession, 'token-abc');
      build();

      service.backfillAccessRole();

      expect(openRequestCount()).toBe(1);
      http.verify();
    });

    it('keeps the session intact when /me fails', () => {
      seedSession(legacySession, 'token-abc');
      build();

      service.backfillAccessRole();
      http.expectOne(ME_URL).flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

      // Swallowed: still signed in, just still missing the field.
      expect(service.currentUser()).toBeTruthy();
      expect(service.token()).toBe('token-abc');
      expect(service.accessRole()).toBeNull();
      http.verify();
    });
  });

  describe('hasAccessRole', () => {
    it('passes admin for any allowed set, and denies a null access_role', () => {
      seedSession({ ...legacySession, access_role: 'admin' }, 'token-abc');
      build();
      expect(service.hasAccessRole(['qualite'])).toBeTrue();
      expect(service.hasAccessRole(['responsable_departement'])).toBeTrue();

      TestBed.resetTestingModule();
      seedSession(legacySession, 'token-abc');
      build();
      expect(service.hasAccessRole(['qualite'])).toBeFalse();
    });
  });
});
