/**
 * §11: "L'impression doit être désactivée et masquée par défaut sur
 * l'intégralité du Hub."
 *
 * ## What these tests can and cannot check
 *
 * Karma runs a real Chrome and loads the application's global `styles.scss`,
 * so the `@media print` block below is the shipped one, parsed by the shipped
 * CSS engine — a typo, a dropped rule or a regression that deletes it fails
 * here. That is a genuine check of the rule, not a restatement of it.
 *
 * What no test in this environment can do is *enter* print media.
 * `getComputedStyle` always resolves against the screen medium; emulating
 * print needs the DevTools protocol (`Emulation.setEmulatedMedia`), which
 * karma-jasmine does not expose, and jsdom does not evaluate media queries at
 * all. So these assert the rules exist and say the right thing; that Chrome
 * then applies them is the browser's own contract.
 *
 * MANUAL VERIFICATION, once per release, in Chrome, Firefox and Edge:
 *   1. Open an article viewer, a procedure viewer and the article list.
 *   2. Ctrl+P on each (from the browser menu on the viewers, where the
 *      shortcut is blocked by BlockCopyShortcutsDirective).
 *   3. The preview must show the "Impression désactivée" notice on one page
 *      and no document content on any of them.
 *   4. Repeat through "Save as PDF" — some print-to-PDF drivers re-render the
 *      DOM and ignore `@media print`. If one does, that is a known limit of a
 *      cooperative measure, documented in styles.scss, not a bug to fix here.
 */
describe('§11 print protection', () => {
  /** Every rule the app declares inside an `@media print` block. */
  function printRules(): CSSStyleRule[] {
    const collected: CSSStyleRule[] = [];

    for (const sheet of Array.from(document.styleSheets)) {
      let rules: CSSRule[];
      try {
        rules = Array.from(sheet.cssRules);
      } catch {
        // A cross-origin sheet would throw; none of ours is, so skip and move on.
        continue;
      }

      for (const rule of rules) {
        if (rule instanceof CSSMediaRule && rule.conditionText.includes('print')) {
          collected.push(
            ...Array.from(rule.cssRules).filter(
              (r): r is CSSStyleRule => r instanceof CSSStyleRule,
            ),
          );
        }
      }
    }

    return collected;
  }

  function ruleFor(selector: string): CSSStyleRule | undefined {
    return printRules().find(rule => rule.selectorText.replace(/\s+/g, ' ') === selector);
  }

  it('declares an @media print block at all', () => {
    // The regression this guards against is the whole block being dropped by a
    // stylesheet refactor: the app would then print documents silently.
    expect(printRules().length).toBeGreaterThan(0);
  });

  it('hides every top-level element from the printer', () => {
    const rule = ruleFor('body > *');

    expect(rule).withContext('no `body > *` rule inside @media print').toBeDefined();
    // app-root and anything the app appends to body — overlays, the procedure
    // lightbox, dialogs — are all covered by this one selector.
    expect(rule!.style.display).toBe('none');
    // Without !important the app's own layout rules outrank this.
    expect(rule!.style.getPropertyPriority('display')).toBe('important');
  });

  it('is Hub-wide rather than scoped to the document viewers', () => {
    // §11 says "l'intégralité du Hub", and a printed list page still discloses
    // titles and references. A selector naming .ad-frame/.pd-frame would mean
    // the scope had been narrowed — fail loudly if that happens.
    const selectors = printRules().map(rule => rule.selectorText);

    expect(selectors).toContain('body > *');
    for (const selector of selectors) {
      expect(selector).not.toMatch(/\.(ad|pd)-/);
    }
  });

  it('prints an explanatory notice instead of the page', () => {
    const rule = ruleFor('body::before');

    expect(rule).withContext('no `body::before` notice rule').toBeDefined();
    expect(rule!.style.content).toContain('Impression désactivée');
    expect(rule!.style.display).toBe('block');
  });

  it('restores document flow so the notice is not lost on a blank sheet', () => {
    // The app pins `body { overflow: hidden }` for its fixed layout.
    const rule = ruleFor('html, body');

    expect(rule).withContext('no `html, body` print reset').toBeDefined();
    expect(rule!.style.overflow).toBe('visible');
    expect(rule!.style.height).toBe('auto');
  });

  /**
   * §11.1's single exception. The "Impression désactivée" notice must not print
   * across the top of the very sheet the Hub just authorized — and the
   * exception must be keyed on the attribute, so that with no grant the blanket
   * hide above stands untouched.
   */
  it('suppresses the disabled-printing notice only for an authorized print', () => {
    const rule = ruleFor('html[data-print-authorized] body::before');

    expect(rule).withContext('no §11.1 exception to the print notice').toBeDefined();
    expect(rule!.style.content).toBe('none');

    // The blanket rule is still there and still unconditional.
    expect(ruleFor('body::before')!.style.content).toContain('Impression désactivée');
  });

  it('does not weaken the screen rendering of the app', () => {
    // Sanity check that the print block really is behind a media condition:
    // body must still be laid out normally on screen.
    expect(getComputedStyle(document.body).display).not.toBe('none');
  });
});
