/*
 * File: assets/js/ui-home.js
 * Description: Home screen renderer for Traxs SPA.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-13 EDT
 */

export function createHome(root, h, headerBlock, logoUrl = '') {
  function renderHome(onNav) {
    root.innerHTML = '';

    const screen = h('div', { class: 'traxs-home' });

    const hero = h('div', { class: 'traxs-home-hero' });
    const title = h('h1', { class: 'traxs-home-title', text: 'Traxs' });

    const logo = h('img', {
      class: 'traxs-home-logo',
      src: logoUrl,
      alt: 'Traxs logo',
    });
    logo.addEventListener('click', () => (location.hash = '#/'));

    hero.append(title, logo);

    const buttonsWrap = h('div', { class: 'traxs-home-buttons' });
    [
      { text: 'Receive POs', token: 'pos' },
      { text: 'Print Work Orders', token: 'print' },
      //{ text: 'Scan Work Order', token: 'scan' },
    ].forEach(({ text, token }) => {
      const btn = h('button', {
        class: 'traxs-btn primary button button-primary',
        text,
      });
      btn.addEventListener('click', () => onNav(token));
      buttonsWrap.appendChild(btn);
    });

    screen.append(hero, buttonsWrap);
    screen.dataset.traxsHome = '1';
    root.appendChild(screen);
  }

  return { renderHome };
}
