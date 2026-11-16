// SPDX-License-Identifier: MIT

/**
 * Structural guard for the refactor of GitLab issue `dowardev/sso#9`.
 *
 * The sections listed there were hand-rolled with `div`, `span` and `ul`
 * markup while Ionic already ships the component that replaces them. These
 * checks read the sources instead of mounting the pages: the finding is the
 * markup itself, and mounting every admin page would couple the test to the
 * whole dependency graph of each view.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const SRC = join(__dirname, '..', '..');

const read = (relative: string): string => readFileSync(join(SRC, relative), 'utf8');

describe('issue #9 native section guard', () => {
  it('renders the dashboard panels with native cards and lists', () => {
    const source = read('views/admin/DashboardPage.vue');

    expect(source).toContain('<ion-card class="card">');
    expect(source).toContain('<ion-card-header class="card-header">');
    expect(source).toContain('<ion-list');
    expect(source).toContain('<ion-item');
    expect(source).toContain('<ion-progress-bar');
    expect(source).not.toContain('<div class="card">');
    expect(source).not.toContain('<div class="session-row"');
    expect(source).not.toContain('<div class="client-row"');
    expect(source).not.toContain('<div class="bar-row"');
    expect(source).not.toContain('<div class="system-row"');
  });

  it('renders the client detail information with native cards, lists and chips', () => {
    const source = read('views/admin/ClientDetailPage.vue');

    expect(source).toContain('<ion-card class="info-card">');
    expect(source).toContain('<ion-list class="info-list">');
    expect(source).toContain('<ion-chip');
    expect(source).not.toContain('<div class="info-card">');
    expect(source).not.toContain('<div class="info-item">');
    expect(source).not.toContain('class="origin-tag">');
  });

  it('renders the home feature list with a native card and list', () => {
    const source = read('views/HomePage.vue');

    expect(source).toContain('<ion-card class="home-card">');
    expect(source).toContain('<ion-list class="features-section">');
    expect(source).toContain('<ion-item class="feature-item" lines="none">');
    expect(source).not.toContain('<div class="home-card">');
    expect(source).not.toContain('<div class="feature-item">');
  });

  it('expands the session rows with a native accordion', () => {
    const source = read('components/user/SessionsList.vue');

    expect(source).toContain('<ion-accordion-group');
    expect(source).toContain('<ion-accordion');
    expect(source).toContain('slot="header"');
    expect(source).toContain('slot="content"');
    expect(source).not.toContain('<div class="session-details">');
  });

  it('renders the backup codes and the password checklist with native components', () => {
    const totp = read('components/user/TotpSettings.vue');
    const setup = read('views/SetupPage.vue');

    expect(totp).toContain('<ion-chip');
    expect(totp).not.toContain('<ul class="codes-list">');
    expect(totp).not.toContain('<li v-for="code in enrollment.backupCodes.value"');

    expect(setup).toContain('<ion-list');
    expect(setup).toContain('<ion-item class="check-item"');
    expect(setup).not.toContain('<div class="check-item"');
  });

  it('keeps the custom section header markup out of the components', () => {
    const files = [
      'components/ClientAuthorizationsList.vue',
      'components/UserAuthorizationsList.vue',
      'components/user/ActivityLog.vue',
      'components/user/AuthorizationsList.vue',
      'components/user/SessionsList.vue',
      'components/user/TotpSettings.vue',
      'views/admin/HealthPage.vue',
      'views/admin/UserDetailPage.vue',
    ];

    for (const file of files) {
      expect(read(file)).not.toContain('<div class="section-header"');
    }
  });

  it('imports every Ionic component it renders', () => {
    const files = [
      'components/ClientAuthorizationsList.vue',
      'components/UserAuthorizationsList.vue',
      'components/user/ActivityLog.vue',
      'components/user/AuthorizationsList.vue',
      'components/user/SessionsList.vue',
      'components/user/TotpSettings.vue',
      'views/HomePage.vue',
      'views/SetupPage.vue',
      'views/admin/ClientDetailPage.vue',
      'views/admin/DashboardPage.vue',
      'views/admin/HealthPage.vue',
      'views/admin/UserDetailPage.vue',
    ];

    for (const file of files) {
      const source = read(file);
      const used = new Set(
        [...source.matchAll(/<(ion-[a-z-]+)/g)].map((match) =>
          match[1].replace(/-/g, '').toLowerCase()
        )
      );
      const imported = new Set(
        [...source.matchAll(/import\s*\{([^}]*)\}\s*from\s*'@ionic\/vue'/gs)].flatMap((match) =>
          match[1]
            .split(',')
            .map((name) => name.trim().replace(/-/g, '').toLowerCase())
            .filter(Boolean)
        )
      );

      for (const component of used) {
        expect(imported, `${file} must import ${component}`).toContain(component);
      }
    }
  });
});

/**
 * Ionic components own their content model. `ion-list-header` carries a label,
 * `ion-card-header` carries a card title and `ion-label` carries text and the
 * heading elements, so hand-rolled markup inside them is a defect.
 */
const innerOf = (source: string, tag: string): string[] => {
  const open = new RegExp(`<${tag}\\b[^>]*>`, 'g');
  const parts: string[] = [];
  let match = open.exec(source);

  while (match !== null) {
    const close = source.indexOf(`</${tag}>`, match.index);

    if (close === -1) {
      break;
    }

    parts.push(source.slice(match.index + match[0].length, close));
    open.lastIndex = close;
    match = open.exec(source);
  }

  return parts;
};

describe('issue #9 Ionic content model guard', () => {
  const nativeSections = [
    'components/ClientAuthorizationsList.vue',
    'components/UserAuthorizationsList.vue',
    'components/user/ActivityLog.vue',
    'components/user/AuthorizationsList.vue',
    'components/user/SessionsList.vue',
    'components/user/TotpSettings.vue',
    'views/HomePage.vue',
    'views/SetupPage.vue',
    'views/admin/ClientDetailPage.vue',
    'views/admin/DashboardPage.vue',
    'views/admin/HealthPage.vue',
    'views/admin/UserDetailPage.vue',
  ];

  it('keeps a label inside every list header', () => {
    for (const file of nativeSections) {
      for (const inner of innerOf(read(file), 'ion-list-header')) {
        // The header carries a label, and the label may carry the heading of
        // the section. Anything else at the level of the header is invalid.
        const direct = inner.replace(/<ion-label\b[^>]*>[\s\S]*?<\/ion-label>/g, '');

        expect(direct, file).not.toMatch(/<h[1-6]\b/);
        expect(direct, file).not.toMatch(/<span\b/);
        expect(direct, file).not.toMatch(/<div\b/);
      }
    }
  });

  it('keeps a card title inside every card header', () => {
    for (const file of nativeSections) {
      for (const inner of innerOf(read(file), 'ion-card-header')) {
        expect(inner, file).not.toMatch(/<div\b/);
      }
    }
  });

  it('keeps text and headings inside every label', () => {
    // HealthPage keeps a pre-existing label that carries a code sample, which
    // is outside the scope of this refactor.
    const labelSections = nativeSections.filter((file) => file !== 'views/admin/HealthPage.vue');

    for (const file of labelSections) {
      for (const inner of innerOf(read(file), 'ion-label')) {
        // A label carries a heading and a paragraph; the inline content of
        // those paragraphs (badges, inline text) stays untouched.
        const direct = inner.replace(/<(h[1-6]|p)\b[^>]*>[\s\S]*?<\/\1>/g, '');

        expect(direct, file).not.toMatch(/<div\b/);
        expect(direct, file).not.toMatch(/<span\b/);
        expect(direct, file).not.toMatch(/<ion-icon\b/);
      }
    }
  });
});
