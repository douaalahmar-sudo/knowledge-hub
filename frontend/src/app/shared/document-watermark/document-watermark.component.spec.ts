import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { environment } from '../../../environments/environment';

import { DocumentWatermarkComponent } from './document-watermark.component';

const ME_URL = `${environment.apiUrl}/v1/auth/me`;

/**
 * The §10.3 contract, asserted once against the shared component rather than
 * re-asserted in every viewer that mounts it.
 */
describe('DocumentWatermarkComponent', () => {
  let fixture: ComponentFixture<DocumentWatermarkComponent>;
  let httpMock: HttpTestingController;

  function setUp(user: Record<string, unknown> | null): void {
    localStorage.clear();
    if (user) {
      localStorage.setItem('auth_token', 'test-token');
      localStorage.setItem('current_user', JSON.stringify(user));
    }

    TestBed.configureTestingModule({
      imports: [DocumentWatermarkComponent],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    fixture = TestBed.createComponent(DocumentWatermarkComponent);
    httpMock = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  }

  function lines(): HTMLElement[] {
    return Array.from(fixture.nativeElement.querySelectorAll('.dw__line'));
  }

  function text(): string {
    return lines()[0]?.textContent?.trim() ?? '';
  }

  type Rgb = [number, number, number];

  /** The watermark's declared colour, as [r, g, b, alpha]. */
  function fill(): [number, number, number, number] {
    const colour = getComputedStyle(lines()[0]).color;
    const parts = colour.match(/[\d.]+/g)?.map(Number) ?? [];

    expect(parts.length).toBeGreaterThanOrEqual(3);
    // Chrome reports an opaque colour as `rgb(...)`, with no fourth part.
    return [parts[0], parts[1], parts[2], parts[3] ?? 1];
  }

  /** What the translucent fill actually paints over a given background. */
  function composite([r, g, b, a]: [number, number, number, number], bg: Rgb): Rgb {
    return [
      r * a + bg[0] * (1 - a),
      g * a + bg[1] * (1 - a),
      b * a + bg[2] * (1 - a),
    ];
  }

  /** WCAG relative luminance / contrast ratio, on 0-255 channels. */
  function luminance([r, g, b]: Rgb): number {
    const [lr, lg, lb] = [r, g, b].map(channel => {
      const c = channel / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
  }

  function contrast(a: Rgb, b: Rgb): number {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
  }

  afterEach(() => {
    localStorage.clear();
    // resetTestingModule clears AuthService's memoised clientIpOnce$ between
    // tests; without it the second test would replay the first test's IP
    // instead of issuing a request.
    TestBed.resetTestingModule();
  });

  it('renders the four §10.3 fields in order', () => {
    setUp({ name: 'Douaa Lahmar', email: 'douaa@flesk.com', matricule: 'FLK-2291', role: 'admin' });
    httpMock.expectOne(ME_URL).flush({ client_ip: '196.203.44.12' });
    fixture.detectChanges();

    const fields = text().split('|').map(f => f.trim());

    expect(fields.length).toBe(4);
    expect(fields[0]).toBe('Douaa Lahmar');
    expect(fields[1]).toBe('FLK-2291');
    expect(fields[2]).toBe('196.203.44.12');
    // fr-FR to the second — `timeStyle: 'short'` would stop at the minute,
    // which §10.3 does not allow.
    expect(fields[3]).toMatch(/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/);
    expect(text()).not.toContain('undefined');
  });

  it('tiles the identity string so it covers the frame', () => {
    setUp({ name: 'Douaa Lahmar', matricule: 'FLK-2291', role: 'admin' });
    httpMock.expectOne(ME_URL).flush({ client_ip: '10.0.0.1' });
    fixture.detectChanges();

    expect(lines().length).toBeGreaterThan(1);
  });

  it('falls back to the email when there is no matricule', () => {
    setUp({ name: 'Douaa Lahmar', email: 'douaa@flesk.com', role: 'admin' });
    httpMock.expectOne(ME_URL).flush({ client_ip: '10.0.0.1' });
    fixture.detectChanges();

    expect(text()).toContain('Douaa Lahmar | douaa@flesk.com | 10.0.0.1 |');
  });

  it('degrades to a placeholder when the IP lookup fails, never "undefined"', () => {
    setUp({ name: 'Douaa Lahmar', matricule: 'FLK-2291', role: 'admin' });
    httpMock.expectOne(ME_URL).flush('nope', { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(text()).toContain('FLK-2291 | — |');
    expect(text()).not.toContain('undefined');
    // Still four fields: a failed lookup must not collapse the format.
    expect(text().split('|').length).toBe(4);
  });

  it('substitutes the IP reactively once it arrives', () => {
    setUp({ name: 'Douaa Lahmar', matricule: 'FLK-2291', role: 'admin' });
    const request = httpMock.expectOne(ME_URL);

    expect(text()).toContain('FLK-2291 | — |');

    request.flush({ client_ip: '196.203.44.12' });
    fixture.detectChanges();

    expect(text()).toContain('FLK-2291 | 196.203.44.12 |');
  });

  /** The overlay must never swallow clicks, scrolls or video seeking. */
  it('is inert to pointer input and excluded from selection', () => {
    setUp({ name: 'Douaa Lahmar', matricule: 'FLK-2291', role: 'admin' });
    httpMock.expectOne(ME_URL).flush({ client_ip: '10.0.0.1' });
    fixture.detectChanges();

    const host = getComputedStyle(fixture.nativeElement);
    const layer = getComputedStyle(fixture.nativeElement.querySelector('.dw'));

    expect(host.pointerEvents).toBe('none');
    expect(layer.userSelect).toBe('none');
    // Diagonal: rotate(-30deg) resolves to a matrix, so assert it is not the
    // identity rather than pinning the exact values.
    expect(layer.transform).not.toBe('none');
  });

  /* §10.3 asks for a "surimpression diagonale grise translucide et
     semi-transparente". These pin the two halves of that: the declared colour
     is grey and translucent, and the one grey stays readable over both the
     black video stage and a white PDF frame — the reason a second, inverted
     tone used to exist. */
  describe('§10.3 grey translucent overlay', () => {
    beforeEach(() => {
      setUp({ name: 'Douaa Lahmar', matricule: 'FLK-2291', role: 'admin' });
      httpMock.expectOne(ME_URL).flush({ client_ip: '10.0.0.1' });
      fixture.detectChanges();
    });

    it('declares one grey, translucent colour — neither white nor dark slate', () => {
      const [r, g, b, alpha] = fill();

      // Mid-range on every channel: a dark slate (#0f172a) fails the lower
      // bound, white the upper.
      for (const channel of [r, g, b]) {
        expect(channel).toBeGreaterThan(110);
        expect(channel).toBeLessThan(200);
      }
      // Grey, not a tint: the channels stay close together.
      expect(Math.max(r, g, b) - Math.min(r, g, b)).toBeLessThan(40);
      // "translucide et semi-transparente".
      expect(alpha).toBeGreaterThan(0);
      expect(alpha).toBeLessThan(1);
    });

    it('stays legible over the black video stage and over a white document', () => {
      const onBlack = contrast(composite(fill(), [0, 0, 0]), [0, 0, 0]);
      const onWhite = contrast(composite(fill(), [255, 255, 255]), [255, 255, 255]);

      // Deliberately low bars. This is an overlay you read the document
      // through, and the tones it replaces measured 1.79 over black and 1.35
      // over white; the point is that neither surface now falls away to
      // nothing, not that the watermark competes with the content. The
      // dual dark/light text-shadow adds separation on top of this, and no
      // computed-style assertion can measure that.
      expect(onBlack).toBeGreaterThan(1.25);
      expect(onWhite).toBeGreaterThan(1.25);
    });

    it('carries a halo in both directions so no surface can swallow it', () => {
      const shadow = getComputedStyle(lines()[0]).textShadow;

      expect(shadow).not.toBe('none');
      expect(shadow).toContain('rgba(0, 0, 0');
      expect(shadow).toContain('rgba(255, 255, 255');
    });
  });

  it('is hidden from assistive technology', () => {
    setUp({ name: 'Douaa Lahmar', matricule: 'FLK-2291', role: 'admin' });
    httpMock.expectOne(ME_URL).flush({ client_ip: '10.0.0.1' });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.dw').getAttribute('aria-hidden')).toBe('true');
  });
});
