// SPDX-License-Identifier: MIT

/**
 * Regression guard for the Ionic 8 anti-patterns reported in GitLab issue
 * `dowardev/sso#8`.
 *
 * These checks read the source files instead of mounting the components: the
 * anti-patterns are structural (invalid slots, replaced ARIA roles, dead CSS
 * selectors), and mounting every affected page would couple the test to the
 * full dependency graph of each view.
 *
 * The hex-colour guard reads the CSS of a component instead of its raw
 * source: the `<style>` blocks and the static `style="..."` attributes. It
 * then keeps only the declaration bodies, so a hex token used as an id
 * selector or as a URL fragment is never reported as a colour.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const SRC = join(__dirname, '..', '..');

const vueFiles = (): string[] => {
  const files: string[] = [];

  const walk = (dir: string): void => {
    for (const entry of readdirSync(dir)) {
      if (entry === 'node_modules' || entry === '__tests__') continue;
      const full = join(dir, entry);
      if (statSync(full).isDirectory()) {
        walk(full);
      } else if (entry.endsWith('.vue')) {
        files.push(full);
      }
    }
  };

  walk(SRC);

  return files;
};

const read = (relative: string): string => readFileSync(join(SRC, relative), 'utf8');

describe('Ionic 8 anti-pattern regression guard', () => {
  it('never uses slot="icon-only" inside an ion-segment-button', () => {
    const offenders = vueFiles().filter((file) => {
      const source = readFileSync(file, 'utf8');
      const buttons = source.match(/<ion-segment-button\b[\s\S]*?>/g) ?? [];

      return buttons.some((button) => button.includes('slot="icon-only"'));
    });

    expect(offenders).toEqual([]);
  });

  it('uses layout="icon-top" on the segment buttons that show an icon', () => {
    const source = read('views/user/ProfilePage.vue');
    const buttons = source.match(/<ion-segment-button\b[\s\S]*?>/g) ?? [];

    expect(buttons.length).toBeGreaterThan(0);

    for (const button of buttons) {
      expect(button).toContain('layout="icon-top"');
    }
  });

  it('does not put slot="end" on a child of ion-list-header', () => {
    const offenders = vueFiles().filter((file) => {
      const source = readFileSync(file, 'utf8');
      const headers = source.match(/<ion-list-header\b[\s\S]*?<\/ion-list-header>/g) ?? [];

      return headers.some((header) => header.includes('slot="end"'));
    });

    expect(offenders).toEqual([]);
  });

  it('does not replace the implicit list role with role="navigation"', () => {
    const offenders = vueFiles().filter((file) =>
      readFileSync(file, 'utf8').includes('role="navigation"')
    );

    expect(offenders).toEqual([]);
  });

  it('keeps the sidebar navigation inside a semantic <nav> element', () => {
    const source = read('App.vue');

    expect(source).toContain('<nav');
    expect(source).toContain('aria-label=');
  });

  it('never renders an ion-buttons outside an ion-toolbar', () => {
    const offenders = vueFiles().filter((file) => {
      const source = readFileSync(file, 'utf8');
      const toolbars = source.match(/<ion-toolbar\b[\s\S]*?<\/ion-toolbar>/g) ?? [];
      const inlineButtons = source.match(/<ion-buttons\b[\s\S]*?<\/ion-buttons>/g) ?? [];

      return inlineButtons.some((group) => !toolbars.some((toolbar) => toolbar.includes(group)));
    });

    expect(offenders).toEqual([]);
  });

  it('does not keep the dead ion-list > div.list-content selector', () => {
    const source = read('theme/variables.css');

    expect(source).not.toContain('.list-content');
  });

  it('does not use !important in the theme or in the admin health view', () => {
    expect(read('theme/variables.css')).not.toContain('!important');
    expect(read('views/admin/HealthPage.vue')).not.toContain('!important');
  });

  it('keeps hardcoded hex colours out of the components', () => {
    const offenders = vueFiles().filter((file) => {
      const source = readFileSync(file, 'utf8');

      return hardcodedHexColours(source).length > 0;
    });

    expect(offenders).toEqual([]);
  });
});

/**
 * A hex colour is a `#` followed by 3, 4, 6 or 8 hex digits. The digit count
 * has to be exact: `#abcde` is not a colour, and a shorter run inside a longer
 * one is not either.
 */
const HEX_COLOUR = /(?<![0-9a-fA-F#])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{4}|[0-9a-fA-F]{3})(?![0-9a-fA-F])/g;

/**
 * Removes the selector text of a CSS source, keeping only the declaration
 * bodies. The selector of a rule is everything between the previous `;`, `}`
 * or `{` and its own `{`, so blanking those spans leaves the declarations.
 */
const cssDeclarations = (css: string): string => {
  const blank = new Uint8Array(css.length);

  for (let i = 0; i < css.length; i += 1) {
    if (css[i] !== '{') continue;

    let start = i;
    while (start > 0 && !';}{'.includes(css[start - 1])) start -= 1;

    for (let j = start; j < i; j += 1) blank[j] = 1;
  }

  let result = '';
  for (let i = 0; i < css.length; i += 1) result += blank[i] ? ' ' : css[i];

  return result;
};

/**
 * Returns the hex colours hardcoded in a CSS source.
 *
 * Only the declaration bodies are inspected, so a hex token used as an id
 * selector or as a URL fragment is never reported as a colour.
 */
const cssHexColours = (css: string): string[] => {
  // Comments and `url()` references carry no colour: `#fff` in a URL is a
  // fragment, not a paint.
  const declarations = cssDeclarations(
    css.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/url\([^)]*\)/gi, ' ')
  );

  return [...declarations.matchAll(HEX_COLOUR)].map((match) => match[0]);
};

/**
 * Returns the hex colours hardcoded in the CSS of a Vue single-file
 * component.
 *
 * Only the CSS is inspected: the `<style>` blocks and the static `style="..."`
 * attributes. A `:style` binding holds a JavaScript object rather than CSS and
 * is therefore not part of this scan.
 */
const hardcodedHexColours = (source: string): string[] => {
  const styles = [...source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((match) => match[1]);
  const inline = [...source.matchAll(/\sstyle="([^"]*)"/g)].map((match) => match[1]);

  return [...styles, ...inline].flatMap(cssHexColours);
};

describe('hex colour detection', () => {
  it('reports a hex colour used as a declaration value', () => {
    expect(cssHexColours('.a { color: #fff; }')).toEqual(['#fff']);
    expect(cssHexColours('.a { color: #A78BFA; }')).toEqual(['#A78BFA']);
    expect(cssHexColours('.a { --custom: #ffffffff; }')).toEqual(['#ffffffff']);
  });

  it('reports a hex colour nested in a fallback or a gradient', () => {
    expect(cssHexColours('.a { background: var(--x, #0a0f1d); }')).toEqual(['#0a0f1d']);
    expect(
      cssHexColours('.a { background: linear-gradient(135deg, #0054e9 50%, #4d91ff 50%); }')
    ).toEqual(['#0054e9', '#4d91ff']);
  });

  it('reports a hex colour in the css of a component', () => {
    expect(hardcodedHexColours('<style>.a { color: #fff; }</style>')).toEqual(['#fff']);
    expect(hardcodedHexColours('<div style="color: #fff"></div>')).toEqual(['#fff']);
  });

  it('ignores a hex token used as an id selector', () => {
    expect(cssHexColours('#fade { color: red; }')).toEqual([]);
    expect(cssHexColours('#fade:hover { color: red; }')).toEqual([]);
    expect(cssHexColours('#fade, #other { color: red; }')).toEqual([]);
    expect(cssHexColours('#abc .child { color: red; }')).toEqual([]);
    expect(cssHexColours('a:hover #fade { color: red; }')).toEqual([]);
  });

  it('ignores a hex token that is not a valid colour length', () => {
    expect(cssHexColours('.a { color: #abcde; }')).toEqual([]);
    expect(cssHexColours('.a { color: #abcdefa; }')).toEqual([]);
  });

  it('ignores a hex token inside a comment or a url reference', () => {
    expect(cssHexColours('.a { color: #fff; } /* #000 */')).toEqual(['#fff']);
    expect(cssHexColours('.a { background: url(#gradient); }')).toEqual([]);
    expect(cssHexColours('.a { background: url("img.svg#fff"); }')).toEqual([]);
  });

  it('ignores a hex token outside the css of the component', () => {
    expect(hardcodedHexColours('<a href="#fff">link</a>')).toEqual([]);
    expect(hardcodedHexColours('<script>const anchor = "#fff";</script>')).toEqual([]);
  });
});

/**
 * `ion-item` layout guard.
 *
 * An interactive control placed in the default slot of an `ion-item` renders
 * in the middle of the row instead of at its trailing edge. Likewise, an
 * `slot="end"` wrapper with `flex-direction: column` breaks the horizontal
 * alignment of the items it groups, so its children stack on separate rows.
 */
describe('ion-item layout regression guard', () => {
  const CONTROL = /<ion-(toggle|checkbox|radio)\b/;

  it('gives trailing interactive controls an explicit slot="end"', () => {
    const offenders = vueFiles().filter((file) => {
      const source = readFileSync(file, 'utf8');
      const controls = source.match(/<ion-(toggle|checkbox|radio)\b[\s\S]*?>/g) ?? [];

      return controls.some(
        (control) => CONTROL.test(control) && !/\bslot="/.test(control)
      );
    });

    expect(offenders).toEqual([]);
  });

  it('does not stack the children of an slot="end" wrapper vertically', () => {
    const offenders = vueFiles().filter((file) => {
      const source = readFileSync(file, 'utf8');

      // Class names used by an element that carries slot="end".
      const endClasses = new Set<string>();
      for (const tag of source.match(/<[a-z-]+\b[^>]*\bslot="end"[^>]*>/g) ?? []) {
        const className = tag.match(/\bclass="([^"]+)"/)?.[1];
        if (className) {
          for (const name of className.split(/\s+/)) endClasses.add(name);
        }
      }

      if (endClasses.size === 0) return false;

      const rules = source.match(/\.[a-z0-9-]+\s*\{[^}]*\}/g) ?? [];

      return rules.some((rule) => {
        const selector = rule.slice(0, rule.indexOf('{')).trim();
        const matched = [...endClasses].some((name) => selector.includes(`.${name}`));

        return matched && /flex-direction:\s*column/.test(rule);
      });
    });

    expect(offenders).toEqual([]);
  });

  it('keeps the session row actions on a single horizontal line', () => {
    const source = read('views/admin/SessionsPage.vue');
    const rule = source.match(/\.session-end\s*\{[^}]*\}/)?.[0] ?? '';

    expect(rule).toContain('flex-direction: row');
    expect(rule).not.toContain('flex-direction: column');
  });
});
