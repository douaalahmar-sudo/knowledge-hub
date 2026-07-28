import {
  Component,
  ElementRef,
  HostListener,
  OnInit,
  ViewChild,
  computed,
  inject,
  signal,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ProcedureApiService } from '../../../core/services/procedure-api.service';
import { Procedure, TRIPTYCH_ORDER, TriptychFormat } from '../../../core/models/procedure.model';

/** Static presentation metadata for the three tabs. */
interface TabDef {
  format: TriptychFormat;
  label: string;
  icon: string;
}

const TABS: TabDef[] = [
  { format: 'pdf', label: 'Document (PDF)', icon: 'file' },
  { format: 'video', label: 'Vidéo explicative', icon: 'video' },
  { format: 'infographic', label: 'Infographie / Schéma', icon: 'image' },
];

const PLAYBACK_RATES = [0.5, 0.75, 1, 1.25, 1.5, 2];

@Component({
  selector: 'app-procedure-detail',
  standalone: true,
  imports: [CommonModule, RouterLink, IconComponent],
  templateUrl: './procedure-detail.component.html',
  styleUrl: './procedure-detail.component.scss',
})
export class ProcedureDetailComponent implements OnInit {
  private api = inject(ProcedureApiService);
  private route = inject(ActivatedRoute);
  private sanitizer = inject(DomSanitizer);

  @ViewChild('videoEl') videoRef?: ElementRef<HTMLVideoElement>;

  readonly tabs = TABS;
  readonly rates = PLAYBACK_RATES;

  procedure = signal<Procedure | null>(null);
  isLoading = signal(true);
  loadError = signal<string | null>(null);

  activeTab = signal<TriptychFormat>('pdf');

  // -- PDF panel -----------------------------------------------------------
  /**
   * Zoom is handed to the browser's built-in PDF viewer through the URL
   * fragment. Chromium and Firefox both honour #zoom=; if a viewer ignores it,
   * its own toolbar still works, so this degrades to native controls.
   */
  pdfZoom = signal(100);
  pdfPage = signal(1);

  // -- Video panel ---------------------------------------------------------
  isPlaying = signal(false);
  isMuted = signal(false);
  currentTime = signal(0);
  duration = signal(0);
  playbackRate = signal(1);

  // -- Infographic panel ---------------------------------------------------
  isLightboxOpen = signal(false);
  imageZoom = signal(1);

  /** Which formats this procedure actually has. */
  available = computed<Record<TriptychFormat, string | null>>(() => {
    const urls = this.procedure()?.triptych_urls;
    return {
      pdf: urls?.pdf ?? null,
      video: urls?.video ?? null,
      infographic: urls?.infographic ?? null,
    };
  });

  availableCount = computed(() => TRIPTYCH_ORDER.filter(f => this.available()[f]).length);

  /** Source for the <iframe>, rebuilt whenever page/zoom change. */
  pdfSrc = computed<SafeResourceUrl | null>(() => {
    const url = this.available().pdf;
    if (!url) return null;
    const fragment = `#page=${this.pdfPage()}&zoom=${this.pdfZoom()}&view=FitH`;
    // The URL comes from our own API's storage host, never from user input.
    return this.sanitizer.bypassSecurityTrustResourceUrl(url + fragment);
  });

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    if (!id) {
      this.isLoading.set(false);
      this.loadError.set('Identifiant de procédure invalide.');
      return;
    }

    this.api.get(id).subscribe({
      next: procedure => {
        this.isLoading.set(false);
        this.procedure.set(procedure);
        // Open on the first format that exists, so a procedure with no PDF does
        // not land the user on an empty tab.
        const first = TRIPTYCH_ORDER.find(f => procedure.triptych_urls?.[f]);
        if (first) this.activeTab.set(first);
      },
      error: (err: Error) => {
        this.isLoading.set(false);
        this.loadError.set(err.message);
      },
    });
  }

  // ------------------------------------------------------------------ tabs

  isAvailable(format: TriptychFormat): boolean {
    return !!this.available()[format];
  }

  selectTab(format: TriptychFormat): void {
    if (!this.isAvailable(format)) return;
    // Pause the video when navigating away, otherwise audio keeps playing over
    // a tab the user can no longer see.
    if (this.activeTab() === 'video' && format !== 'video') this.pauseVideo();
    this.activeTab.set(format);
  }

  /** Left/Right arrow keys move between enabled tabs (WAI-ARIA tab pattern). */
  onTabKeydown(event: KeyboardEvent, format: TriptychFormat): void {
    const enabled = TRIPTYCH_ORDER.filter(f => this.isAvailable(f));
    if (enabled.length < 2) return;

    const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
    if (!step) return;

    event.preventDefault();
    const idx = enabled.indexOf(format);
    const next = enabled[(idx + step + enabled.length) % enabled.length];
    this.selectTab(next);
  }

  // ------------------------------------------------------------------- pdf

  zoomPdf(delta: number): void {
    this.pdfZoom.update(z => Math.min(300, Math.max(50, z + delta)));
  }

  resetPdfZoom(): void {
    this.pdfZoom.set(100);
  }

  changePdfPage(delta: number): void {
    this.pdfPage.update(p => Math.max(1, p + delta));
  }

  // ----------------------------------------------------------------- video

  private get video(): HTMLVideoElement | null {
    return this.videoRef?.nativeElement ?? null;
  }

  togglePlay(): void {
    const v = this.video;
    if (!v) return;
    if (v.paused) {
      // play() rejects when the browser blocks autoplay; swallow so the UI does
      // not throw an unhandled rejection.
      v.play().catch(() => this.isPlaying.set(false));
    } else {
      v.pause();
    }
  }

  private pauseVideo(): void {
    this.video?.pause();
  }

  onPlay(): void { this.isPlaying.set(true); }
  onPause(): void { this.isPlaying.set(false); }

  onLoadedMetadata(): void {
    this.duration.set(this.video?.duration ?? 0);
  }

  onTimeUpdate(): void {
    this.currentTime.set(this.video?.currentTime ?? 0);
  }

  seek(event: Event): void {
    const value = Number((event.target as HTMLInputElement).value);
    if (this.video) this.video.currentTime = value;
    this.currentTime.set(value);
  }

  skip(seconds: number): void {
    const v = this.video;
    if (!v) return;
    v.currentTime = Math.min(v.duration || 0, Math.max(0, v.currentTime + seconds));
  }

  setRate(rate: number): void {
    if (this.video) this.video.playbackRate = rate;
    this.playbackRate.set(rate);
  }

  toggleMute(): void {
    const v = this.video;
    if (!v) return;
    v.muted = !v.muted;
    this.isMuted.set(v.muted);
  }

  /** mm:ss — procedures are short-form, so hours are not worth the width. */
  formatTime(seconds: number): string {
    if (!isFinite(seconds) || seconds < 0) return '0:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
  }

  progressPercent = computed(() => {
    const d = this.duration();
    return d > 0 ? (this.currentTime() / d) * 100 : 0;
  });

  /**
   * Pre-formatted CSS length for the scrub-bar fill.
   *
   * Bound as a bare custom property rather than `[style.--pd-progress.%]`:
   * Angular's unit-suffix shorthand is not applied to custom properties, so the
   * suffixed form type-checks but leaves the bar permanently empty at runtime.
   */
  progressCss = computed(() => `${this.progressPercent()}%`);

  // ----------------------------------------------------------- infographic

  openLightbox(): void {
    this.isLightboxOpen.set(true);
    this.imageZoom.set(1);
  }

  closeLightbox(): void {
    this.isLightboxOpen.set(false);
  }

  zoomImage(factor: number): void {
    this.imageZoom.update(z => Math.min(5, Math.max(1, +(z * factor).toFixed(2))));
  }

  toggleImageZoom(): void {
    this.imageZoom.update(z => (z > 1 ? 1 : 2));
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.isLightboxOpen()) this.closeLightbox();
  }

  // ----------------------------------------------------------------- misc

  /** Filename for the download attribute, derived from the reference code. */
  downloadName(format: TriptychFormat): string {
    const p = this.procedure();
    const base = p?.reference_code || 'procedure';
    const url = this.available()[format] || '';
    const ext = url.split('.').pop()?.split('?')[0] || 'bin';
    return `${base}-${format}.${ext}`;
  }
}
