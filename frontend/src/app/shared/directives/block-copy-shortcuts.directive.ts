import { Directive, ElementRef, HostListener, inject } from '@angular/core';

/**
 * Suppresses the copy/save/print keyboard shortcuts required by cahier des
 * charges §10.2 ("blocage … des raccourcis de copie") over protected document
 * content: Ctrl/Cmd+C, Ctrl/Cmd+S and Ctrl/Cmd+P.
 *
 * Applied to the same viewer frames as {@link BlockContextMenuDirective}, and
 * for the same reason: the guard exists only while a document viewer is on
 * screen. Ordinary pages — the article list, the navigation, any form — never
 * mount it, so Ctrl+C keeps working where blocking it would simply be hostile.
 *
 * ## Why the keydown listener is on `document`, not on the host
 *
 * `contextmenu` is delivered to the element under the pointer, so
 * BlockContextMenuDirective can bind it on the host and be done. `keydown` is
 * delivered to the *focused* element, and a viewer frame is a plain `<div>`:
 * it is not focusable, so focus sits on `<body>` and a host-bound `keydown`
 * would never fire. Making the frame focusable to fix that would put a
 * decorative div in the tab order for no user benefit.
 *
 * The listener is therefore document-level but *directive-scoped*: Angular
 * attaches it when the frame is created and removes it when the frame is
 * destroyed, so its reach is exactly "a document viewer is currently
 * displayed". That is what §10.2 asks for, and it is why this lives in a
 * directive on the frame rather than in the viewer components.
 *
 * Ctrl+C is narrower still — it is only blocked when the selection actually
 * touches the frame. On a procedure page the metadata, the summary and the
 * step list around the viewer are ordinary text the user is entitled to copy;
 * only the document itself is protected.
 *
 * ## What this does NOT prevent — deliberately stated
 *
 * This is an identification and speed-bump measure, not a security boundary.
 * It cannot stop, and must not be described as stopping:
 *
 * - **OS screenshot tools** (Print Screen, Snipping Tool, macOS ⌘⇧4, phone
 *   cameras). The key never reaches the page; nothing in a browser can.
 * - **Browser devtools** — the network tab still holds the blob/response, and
 *   the DOM can be edited to strip this directive and the §10.3 watermark.
 * - **The browser's own menus** — File ▸ Print, File ▸ Save Page As and the ⋮
 *   menu are chrome, not page content; a `keydown` handler is not consulted.
 * - **Print-to-PDF and "virtual printer" drivers**, and browsers/extensions
 *   that render a page without honouring `@media print`. The global print rule
 *   in `styles.scss` blanks the page for a *cooperative* print pipeline only.
 * - **Anything inside an `<iframe>`** — the native PDF viewer is a separate
 *   document. Its own Ctrl+P/Ctrl+S, and its toolbar's save and print buttons,
 *   fire inside that document and never cross into this one. When focus is in
 *   the PDF plugin, these shortcuts are simply not ours to intercept.
 *
 * What it does buy: the accidental and the casual paths are closed, and every
 * frame it guards also carries the §10.3 identity watermark, so material that
 * does leave still points back to whoever displayed it.
 *
 * Usage: `<div appBlockCopyShortcuts appBlockContextMenu>…</div>`
 */
@Directive({
  selector: '[appBlockCopyShortcuts]',
  standalone: true,
})
export class BlockCopyShortcutsDirective {
  private host = inject(ElementRef<HTMLElement>);

  @HostListener('document:keydown', ['$event'])
  onKeydown(event: KeyboardEvent): void {
    // Ctrl+Alt on Windows is AltGr, which composes ordinary characters (Ctrl+Alt+C
    // is a real keystroke in several layouts). Excluding it keeps typing intact.
    if (!(event.ctrlKey || event.metaKey) || event.altKey) return;

    switch (event.key.toLowerCase()) {
      // Save and print act on the whole document regardless of where focus is,
      // so they are blocked for as long as a viewer is mounted.
      case 's':
      case 'p':
        event.preventDefault();
        break;
      case 'c':
        if (this.selectionTouchesHost()) event.preventDefault();
        break;
    }
  }

  /**
   * Backstop for the paths a keydown handler cannot see: the Edit menu, the
   * context menu of a nested editable, and any platform copy gesture that does
   * not go through Ctrl+C. `copy`/`cut` bubble from the element holding the
   * selection, so binding them on the host is correctly scoped on its own.
   */
  @HostListener('copy', ['$event'])
  @HostListener('cut', ['$event'])
  onClipboard(event: ClipboardEvent): void {
    event.preventDefault();
  }

  /**
   * True when the current selection starts or ends inside the guarded frame.
   * An empty selection returns false: Ctrl+C with nothing selected copies
   * nothing, and preventing it would only risk breaking a legitimate copy the
   * browser resolves differently.
   */
  private selectionTouchesHost(): boolean {
    const selection = document.getSelection();
    if (!selection || selection.isCollapsed || selection.rangeCount === 0) return false;

    const element = this.host.nativeElement;
    return [selection.anchorNode, selection.focusNode].some(
      node => node !== null && element.contains(node),
    );
  }
}
