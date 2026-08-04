import {
  Component,
  ElementRef,
  Input,
  OnDestroy,
  OnInit,
  inject,
} from '@angular/core';
import { Article } from '../../core/models/article.model';
import { PrintAuthorizationService } from '../../core/services/print-authorization.service';

/**
 * What an authorized print (§11.1) actually puts on paper.
 *
 * Required by the clause, verbatim:
 *   - a header/footer banner reading "DOCUMENT PROPRIÉTÉ EXCLUSIVE D'AZIZA -
 *     DIFFUSION INTERDITE - COPIE TRACÉE N° [ID MATRICULE COMPTABLE]"
 *   - "Version papier valide pour une durée de 24 heures uniquement. Pour
 *     accéder à la version officielle faisant foi, veuillez vous connecter au
 *     Knowledge Hub."
 *
 * The trace number is the matricule from the server-issued grant, never from
 * the local session: the client must not be the source of the identity it
 * stamps on paper.
 *
 * ## Why the host relocates to <body>
 *
 * §11's blanket rule is `body > * { display: none !important }`, which hides
 * app-root and therefore every component inside it. Revealing one descendant
 * would mean un-hiding every ancestor between it and <body> — a chain that
 * changes whenever the layout does. Moving this host to be a direct child of
 * <body> makes the exception a single rule that cannot drift. It is the same
 * approach CDK overlays take, for the same reason.
 *
 * ## What does NOT print
 *
 * The PDF itself. It is served from Drive in a cross-origin <iframe>, which
 * browsers do not reliably include in a print and into which we cannot inject a
 * banner. An authorized print therefore carries the article's identity, its
 * summary and — when that is the active format — the infographic, which is a
 * real <img> in our own DOM. Stamping the banner onto the PDF needs server-side
 * PDF generation; it is a separate piece of work, not something this component
 * silently half-does. The 24-hour notice points the reader at the Hub for the
 * version faisant foi, which is exactly the right answer here.
 */
@Component({
  selector: 'app-print-sheet',
  standalone: true,
  template: `
    @if (auth.current(); as grant) {
      <!-- Repeated top and bottom. In a multi-page print both are fixed
           position, which Chrome and Firefox repeat on every sheet; Safari is
           less consistent, so the notice is also inline in the flow below and
           will print at least once wherever it lands. -->
      <div class="ps__banner ps__banner--top">
        DOCUMENT PROPRIÉTÉ EXCLUSIVE D'AZIZA - DIFFUSION INTERDITE - COPIE TRACÉE N°
        {{ grant.matricule }}
      </div>

      <article class="ps__body">
        <h1 class="ps__title">{{ article?.title }}</h1>

        <dl class="ps__meta">
          <div><dt>Version</dt><dd>{{ article?.version ?? '—' }}</dd></div>
          <div><dt>Criticité</dt><dd>{{ article?.criticite ?? '—' }}</dd></div>
          <div><dt>Imprimé par</dt><dd>{{ grant.holder_name }} ({{ grant.matricule }})</dd></div>
          <div><dt>Imprimé le</dt><dd>{{ printedAt }}</dd></div>
        </dl>

        @if (article?.content_summary) {
          <p class="ps__summary">{{ article?.content_summary }}</p>
        }

        <!-- The infographic is in our own DOM, so it prints; the PDF is not. -->
        @if (infographicUrl) {
          <img class="ps__figure" [src]="infographicUrl" alt="" />
        }

        <p class="ps__legal">
          Version papier valide pour une durée de 24 heures uniquement. Pour accéder à la
          version officielle faisant foi, veuillez vous connecter au Knowledge Hub.
        </p>
      </article>

      <div class="ps__banner ps__banner--bottom">
        DOCUMENT PROPRIÉTÉ EXCLUSIVE D'AZIZA - DIFFUSION INTERDITE - COPIE TRACÉE N°
        {{ grant.matricule }}
      </div>
    }
  `,
  styleUrl: './print-sheet.component.scss',
})
export class PrintSheetComponent implements OnInit, OnDestroy {
  readonly auth = inject(PrintAuthorizationService);

  private host = inject(ElementRef<HTMLElement>);

  @Input() article: Article | null = null;

  /** Object URL of the infographic, when that is the format on screen. */
  @Input() infographicUrl: string | null = null;

  /**
   * Captured at construction rather than bound to a clock: this is when the
   * sheet was produced, and the 24-hour validity in the notice runs from it.
   */
  readonly printedAt = new Intl.DateTimeFormat('fr-FR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date());

  ngOnInit(): void {
    // See the class docblock: a direct child of <body> is what makes §11's
    // exception one rule instead of an ancestor chain.
    document.body.appendChild(this.host.nativeElement);
  }

  ngOnDestroy(): void {
    // Angular no longer owns this node's position, so removing it is ours to
    // do — otherwise every visit to a viewer would leave a sheet behind.
    this.host.nativeElement.remove();
  }
}
