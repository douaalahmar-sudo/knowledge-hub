import { Directive, HostListener } from '@angular/core';

/**
 * Suppresses the browser context menu on the element it is applied to (and its
 * descendants, since `contextmenu` bubbles).
 *
 * Meant to wrap protected document content — see
 * ArticleWorkflowDetailComponent's viewer frames — rather than a whole page, so
 * ordinary navigation around it keeps its normal right-click behaviour
 * (open-in-new-tab, copy link address…).
 *
 * Explicitly NOT a security boundary: it only closes the obvious "save
 * image/video as…" affordance. Devtools, the network tab and the blob URL all
 * remain reachable, in the same spirit as controlsList="nodownload".
 *
 * Note it cannot reach *inside* an <iframe> (the PDF panel): that content
 * belongs to a separate document, and its contextmenu events never cross into
 * this one — so the browser's own PDF viewer keeps its native menu.
 *
 * Usage: <div appBlockContextMenu>…</div>
 */
@Directive({
  selector: '[appBlockContextMenu]',
  standalone: true,
})
export class BlockContextMenuDirective {
  @HostListener('contextmenu', ['$event'])
  onContextMenu(event: MouseEvent): void {
    event.preventDefault();
  }
}
