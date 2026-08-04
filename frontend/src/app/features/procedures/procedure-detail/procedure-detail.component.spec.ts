import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { environment } from '../../../../environments/environment';
import { Procedure } from '../../../core/models/procedure.model';

import { ProcedureDetailComponent } from './procedure-detail.component';

const ME_URL = `${environment.apiUrl}/v1/auth/me`;
const PROCEDURE_URL = `${environment.apiUrl}/v1/procedures/7`;

/**
 * §10.2/§10.3 applied to the procedure triptych.
 *
 * This viewer previously carried no watermark, no right-click suppression and
 * four "Télécharger" buttons — §2 names downloadable procedures as a core
 * failure of the previous version, so the absence of those buttons is asserted
 * here as a behaviour, not left to review.
 */
describe('ProcedureDetailComponent', () => {
  let fixture: ComponentFixture<ProcedureDetailComponent>;
  let component: ProcedureDetailComponent;
  let httpMock: HttpTestingController;

  function procedureFixture(): Procedure {
    return {
      id: 7,
      reference_code: 'PR-2026-010',
      name: 'Procédure Ouverture de Caisse',
      module: 'Operations',
      category: null,
      department: null,
      description: null,
      status: 'Validé',
      version: '1.0',
      is_active: true,
      pdf_path: 'p.pdf',
      video_path: 'v.mp4',
      infographic_path: 'i.png',
      triptych_urls: {
        pdf: 'https://files.test/p.pdf',
        video: 'https://files.test/v.mp4',
        infographic: 'https://files.test/i.png',
      },
    } as Procedure;
  }

  function setUp(): void {
    localStorage.clear();
    localStorage.setItem('auth_token', 'test-token');
    localStorage.setItem(
      'current_user',
      JSON.stringify({ name: 'Douaa Lahmar', matricule: 'FLK-2291', role: 'admin' })
    );

    TestBed.configureTestingModule({
      imports: [ProcedureDetailComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: '7' }) } },
        },
      ],
    });

    fixture = TestBed.createComponent(ProcedureDetailComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
    fixture.detectChanges();

    httpMock.expectOne(ME_URL).flush({ client_ip: '196.203.44.12' });
    httpMock.expectOne(PROCEDURE_URL).flush(procedureFixture());
    fixture.detectChanges();
  }

  /** Switches tab and re-renders; the triptych panels are @if-gated. */
  function showTab(tab: 'pdf' | 'video' | 'infographic'): void {
    component.activeTab.set(tab);
    fixture.detectChanges();
  }

  function watermarkText(): string {
    const line: HTMLElement | null = fixture.nativeElement.querySelector('.dw__line');
    return line?.textContent?.trim() ?? '';
  }

  function assertFourFieldWatermark(): void {
    const fields = watermarkText().split('|').map(f => f.trim());
    expect(fields.length).toBe(4);
    expect(fields[0]).toBe('Douaa Lahmar');
    expect(fields[1]).toBe('FLK-2291');
    expect(fields[2]).toBe('196.203.44.12');
    expect(fields[3]).toMatch(/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/);
  }

  afterEach(() => {
    localStorage.clear();
    TestBed.resetTestingModule();
  });

  it('should create', () => {
    setUp();
    expect(component).toBeTruthy();
  });

  // ------------------------------------------------------- §10.3 watermark

  it('watermarks the PDF viewer with all four fields', () => {
    setUp();
    showTab('pdf');

    expect(fixture.nativeElement.querySelector('.pd-frame app-document-watermark')).not.toBeNull();
    assertFourFieldWatermark();
  });

  it('watermarks the video viewer with all four fields', () => {
    setUp();
    showTab('video');

    expect(
      fixture.nativeElement.querySelector('.pd-video-stage app-document-watermark')
    ).not.toBeNull();
    assertFourFieldWatermark();
  });

  it('watermarks the infographic viewer with all four fields', () => {
    setUp();
    showTab('infographic');

    expect(fixture.nativeElement.querySelector('.pd-figure app-document-watermark')).not.toBeNull();
    assertFourFieldWatermark();
  });

  /** The fullscreen view is the most exposed presentation of the document. */
  it('watermarks the fullscreen lightbox', () => {
    setUp();
    showTab('infographic');
    component.openLightbox();
    fixture.detectChanges();

    expect(
      fixture.nativeElement.querySelector('.pd-lightbox__stage app-document-watermark')
    ).not.toBeNull();
    assertFourFieldWatermark();
  });

  /** One memoised lookup, not one per overlay. */
  it('issues a single client-IP request no matter how many overlays mount', () => {
    setUp();
    showTab('pdf');
    showTab('video');
    showTab('infographic');

    // setUp already flushed the only expected /me call; a second would fail here.
    httpMock.verify();
  });

  // ---------------------------------------------------- §10.2 right-click

  it('blocks right-click on each document frame', () => {
    setUp();

    for (const [tab, selector] of [
      ['pdf', '.pd-frame'],
      ['video', '.pd-video-stage'],
      ['infographic', '.pd-figure'],
    ] as const) {
      showTab(tab);

      const frame: HTMLElement = fixture.nativeElement.querySelector(selector);
      const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
      frame.dispatchEvent(event);

      expect(event.defaultPrevented)
        .withContext(`right-click was not blocked on ${selector}`)
        .toBeTrue();
    }
  });

  /**
   * The suppression must stay scoped to the document. Blocking it page-wide
   * would take "open link in new tab" away from ordinary navigation, which is
   * a usability cost §10.2 does not ask for.
   */
  it('leaves right-click working on surrounding page navigation', () => {
    setUp();
    showTab('pdf');

    const backLink: HTMLElement | null = fixture.nativeElement.querySelector('a[routerLink]');
    expect(backLink).withContext('no navigation link found to test against').not.toBeNull();

    const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
    backLink!.dispatchEvent(event);

    expect(event.defaultPrevented).toBeFalse();
  });

  // ----------------------------------------------------- §10.2 shortcuts

  /**
   * The directive's own spec covers the key matrix; this covers the wiring —
   * that each frame actually carries the guard, in every tab and in the
   * lightbox. Events go to `document` because that is where a real Ctrl+P
   * lands: the frames are not focusable, so focus stays on <body>.
   */
  it('blocks the copy, save and print shortcuts while a document is displayed', () => {
    setUp();

    for (const tab of ['pdf', 'video', 'infographic'] as const) {
      showTab(tab);

      for (const key of ['s', 'p']) {
        const event = new KeyboardEvent('keydown', {
          key,
          ctrlKey: true,
          bubbles: true,
          cancelable: true,
        });
        document.dispatchEvent(event);

        expect(event.defaultPrevented)
          .withContext(`Ctrl+${key} was not blocked on the ${tab} tab`)
          .toBeTrue();
      }
    }
  });

  it('blocks Ctrl+C for a selection inside a document frame', () => {
    setUp();
    showTab('infographic');

    const range = document.createRange();
    range.selectNodeContents(fixture.nativeElement.querySelector('.pd-figure'));
    const selection = document.getSelection()!;
    selection.removeAllRanges();
    selection.addRange(range);

    const event = new KeyboardEvent('keydown', {
      key: 'c',
      ctrlKey: true,
      bubbles: true,
      cancelable: true,
    });
    document.dispatchEvent(event);
    selection.removeAllRanges();

    expect(event.defaultPrevented).toBeTrue();
  });

  it('disables text selection on every document frame', () => {
    setUp();

    for (const [tab, selector] of [
      ['pdf', '.pd-frame'],
      ['video', '.pd-video-stage'],
      ['infographic', '.pd-figure'],
    ] as const) {
      showTab(tab);

      const frame: HTMLElement = fixture.nativeElement.querySelector(selector);

      expect(getComputedStyle(frame).userSelect)
        .withContext(`${selector} is still selectable`)
        .toBe('none');
    }
  });

  // ------------------------------------------------------- §10.2 downloads

  it('offers no download affordance in any tab or the lightbox', () => {
    setUp();

    for (const tab of ['pdf', 'video', 'infographic'] as const) {
      showTab(tab);

      const downloads = fixture.nativeElement.querySelectorAll('a[download]');
      expect(downloads.length).withContext(`download link present in the ${tab} tab`).toBe(0);
      expect(fixture.nativeElement.textContent)
        .withContext(`"Télécharger" still offered in the ${tab} tab`)
        .not.toContain('Télécharger');
    }

    component.openLightbox();
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelectorAll('a[download]').length).toBe(0);
    expect(fixture.nativeElement.textContent).not.toContain('Télécharger');
  });

  /**
   * The regression this guards: the PDF tab used to carry
   * `[href]="available().pdf" target="_blank"` labelled "Plein écran". Opening
   * the raw file URL in a bare tab escapes the §10.3 overlay, the right-click
   * block and the removal of the download buttons all at once — every
   * protection on this tab, undone by one click.
   *
   * Asserted against the fixture's real URLs rather than a hardcoded string, so
   * any element that starts pointing at a triptych file fails this, whatever
   * attribute it uses to do it.
   */
  it('links to no raw file URL anywhere on the PDF tab', () => {
    setUp();
    showTab('pdf');

    const fileUrls = Object.values(procedureFixture().triptych_urls).filter(
      (url): url is string => !!url
    );

    const panel: HTMLElement = fixture.nativeElement.querySelector('#panel-pdf');
    const offenders: string[] = [];

    for (const el of Array.from(panel.querySelectorAll<HTMLElement>('*'))) {
      // The viewer iframe is the sanctioned exception: embedding the document
      // is how it gets rendered at all, and that render is the one wrapped in
      // the watermark and the right-click block. Everything else pointing at
      // the file is a way *out* of those protections.
      if (el === panel.querySelector('iframe.pd-pdf')) continue;

      for (const attr of Array.from(el.attributes)) {
        if (fileUrls.some(url => attr.value.includes(url))) {
          offenders.push(`<${el.tagName.toLowerCase()} ${attr.name}="${attr.value}">`);
        }
      }
    }

    expect(offenders)
      .withContext(`navigable elements exposing a raw file URL: ${offenders.join(', ')}`)
      .toEqual([]);

    // Nothing on this panel navigates anywhere, in a new tab or otherwise.
    expect(panel.querySelectorAll('a[href]').length).toBe(0);
    expect(panel.querySelectorAll('[target="_blank"]').length).toBe(0);
  });

  /**
   * The affordance is kept, not deleted — it just stays inside the app. The
   * frame that expands is the same element the overlay and the right-click
   * block are attached to, so they cannot be left behind.
   */
  it('offers an in-app fullscreen toggle that keeps the protections attached', () => {
    setUp();
    showTab('pdf');

    expect(component.isPdfFullscreen()).toBeFalse();
    expect(fixture.nativeElement.querySelector('.pd-frame--fullscreen')).toBeNull();

    component.togglePdfFullscreen();
    fixture.detectChanges();

    const frame: HTMLElement = fixture.nativeElement.querySelector('.pd-frame--fullscreen');
    expect(frame).not.toBeNull();

    // The document, the overlay and the block are all still one subtree.
    expect(frame.querySelector('iframe.pd-pdf')).not.toBeNull();
    expect(frame.querySelector('app-document-watermark')).not.toBeNull();
    assertFourFieldWatermark();

    const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
    frame.dispatchEvent(event);
    expect(event.defaultPrevented)
      .withContext('right-click was not blocked in fullscreen')
      .toBeTrue();
  });

  it('closes fullscreen on Escape and via the exit button', () => {
    setUp();
    showTab('pdf');

    component.togglePdfFullscreen();
    fixture.detectChanges();
    component.onEscape();
    fixture.detectChanges();
    expect(component.isPdfFullscreen()).withContext('Escape did not exit').toBeFalse();

    component.togglePdfFullscreen();
    fixture.detectChanges();
    fixture.nativeElement.querySelector('.pd-frame__exit').click();
    fixture.detectChanges();
    expect(component.isPdfFullscreen()).withContext('exit button did not exit').toBeFalse();
  });

  it('marks the video element as non-downloadable', () => {
    setUp();
    showTab('video');

    const video: HTMLVideoElement = fixture.nativeElement.querySelector('video');
    expect(video.getAttribute('controlsList')).toBe('nodownload noplaybackrate');
    expect(video.hasAttribute('disablePictureInPicture')).toBeTrue();
  });
});
