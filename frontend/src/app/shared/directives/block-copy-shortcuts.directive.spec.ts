import { Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';

import { BlockCopyShortcutsDirective } from './block-copy-shortcuts.directive';

/** A guarded document frame with selectable text inside and outside it. */
@Component({
  standalone: true,
  imports: [BlockCopyShortcutsDirective],
  template: `
    <p id="outside">Résumé de la procédure, librement copiable.</p>
    <div id="frame" appBlockCopyShortcuts>
      <p id="inside">Contenu du document protégé.</p>
    </div>
  `,
})
class HostComponent {}

/** The same page with no guard on it — an article list, a form, the nav. */
@Component({
  standalone: true,
  template: `<p id="outside">Une page ordinaire.</p>`,
})
class UnguardedComponent {}

describe('BlockCopyShortcutsDirective', () => {
  let fixture: ComponentFixture<HostComponent>;

  /**
   * Dispatched on `document`, not on the frame: that is where a real Ctrl+P
   * lands, since the frame is a non-focusable div and focus stays on <body>.
   * Testing it any other way would test a path the browser never takes.
   */
  function press(key: string, modifiers: Partial<KeyboardEventInit> = {}): KeyboardEvent {
    const event = new KeyboardEvent('keydown', {
      key,
      bubbles: true,
      cancelable: true,
      ...modifiers,
    });
    document.dispatchEvent(event);
    return event;
  }

  /** Puts a real selection across the given element's text. */
  function selectInside(id: string): void {
    const range = document.createRange();
    range.selectNodeContents(fixture.nativeElement.querySelector(`#${id}`));
    const selection = document.getSelection()!;
    selection.removeAllRanges();
    selection.addRange(range);
  }

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HostComponent] });
    fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
  });

  afterEach(() => {
    document.getSelection()?.removeAllRanges();
  });

  describe('while a document viewer is displayed', () => {
    it('blocks Ctrl+S and Ctrl+P', () => {
      expect(press('s', { ctrlKey: true }).defaultPrevented).toBe(true);
      expect(press('p', { ctrlKey: true }).defaultPrevented).toBe(true);
    });

    it('blocks the macOS Cmd equivalents', () => {
      expect(press('s', { metaKey: true }).defaultPrevented).toBe(true);
      expect(press('p', { metaKey: true }).defaultPrevented).toBe(true);
    });

    it('blocks Ctrl+C and Cmd+C when the selection is inside the frame', () => {
      selectInside('inside');

      expect(press('c', { ctrlKey: true }).defaultPrevented).toBe(true);
      expect(press('c', { metaKey: true }).defaultPrevented).toBe(true);
    });

    it('leaves Ctrl+C alone for text outside the frame', () => {
      // The summary, metadata and step list around a viewer are ordinary
      // content; §10.2 protects the document, not the page around it.
      selectInside('outside');

      expect(press('c', { ctrlKey: true }).defaultPrevented).toBe(false);
    });

    it('leaves Ctrl+C alone when nothing is selected', () => {
      expect(press('c', { ctrlKey: true }).defaultPrevented).toBe(false);
    });

    it('blocks copy and cut events on the frame itself', () => {
      const frame = fixture.nativeElement.querySelector('#frame');

      for (const type of ['copy', 'cut']) {
        const event = new Event(type, { bubbles: true, cancelable: true });
        frame.dispatchEvent(event);
        expect(event.defaultPrevented).withContext(type).toBe(true);
      }
    });

    it('does not touch unmodified keys or other shortcuts', () => {
      // Typing, select-all, find, reload and the arrow keys all stay intact —
      // the block is three shortcuts, not a keyboard trap.
      expect(press('c').defaultPrevented).toBe(false);
      expect(press('p').defaultPrevented).toBe(false);
      expect(press('a', { ctrlKey: true }).defaultPrevented).toBe(false);
      expect(press('f', { ctrlKey: true }).defaultPrevented).toBe(false);
      expect(press('r', { ctrlKey: true }).defaultPrevented).toBe(false);
      expect(press('ArrowRight', { ctrlKey: true }).defaultPrevented).toBe(false);
    });

    it('ignores AltGr combinations, which are ordinary typing on some layouts', () => {
      selectInside('inside');

      expect(press('c', { ctrlKey: true, altKey: true }).defaultPrevented).toBe(false);
      expect(press('s', { ctrlKey: true, altKey: true }).defaultPrevented).toBe(false);
    });
  });

  describe('on pages that mount no viewer', () => {
    it('does not block the shortcuts anywhere else in the app', () => {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({ imports: [UnguardedComponent] });
      TestBed.createComponent(UnguardedComponent).detectChanges();

      // Same events, same target, no guard mounted: the article list, the
      // search page and every form keep Ctrl+C/S/P.
      expect(press('c', { ctrlKey: true }).defaultPrevented).toBe(false);
      expect(press('s', { ctrlKey: true }).defaultPrevented).toBe(false);
      expect(press('p', { ctrlKey: true }).defaultPrevented).toBe(false);
    });

    it('stops blocking as soon as the viewer is destroyed', () => {
      expect(press('p', { ctrlKey: true }).defaultPrevented).toBe(true);

      fixture.destroy();

      // Angular tears the document listener down with the frame; navigating
      // away from a document must not leave the shortcut blocked app-wide.
      expect(press('p', { ctrlKey: true }).defaultPrevented).toBe(false);
    });
  });

  it('disables text selection on the guarded frame', () => {
    const frame = fixture.nativeElement.querySelector('#frame');
    const outside = fixture.nativeElement.querySelector('#outside');

    // The rule lives in the global styles.scss, keyed on the attribute, and is
    // loaded into the Karma page — so this asserts the shipped selector.
    expect(getComputedStyle(frame).userSelect).toBe('none');
    expect(getComputedStyle(outside).userSelect).not.toBe('none');
  });
});
