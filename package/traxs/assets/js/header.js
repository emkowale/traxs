/*
 * File: assets/js/header.js
 * Description: Shared header (logo + title) for all Traxs SPA screens.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-11 EDT
 */

export function buildHeader(h, LOGO, titleText, subtitleText) {
  const wrap = h('div', { class: 'traxs-row' });

  const title = h('div', { class: 'traxs-row-text' });

  if (subtitleText) {
    // Two-line title: main label + second line
    const line1 = document.createElement('div');
    line1.textContent = titleText;

    const line2 = document.createElement('div');
    line2.textContent = subtitleText;

    title.appendChild(line1);
    title.appendChild(line2);
  } else {
    // Single-line title
    title.textContent = titleText;
  }

  const logo = h('img', {
    src: LOGO,
    alt: 'The Bear Traxs',
    width: '120',
    height: '120',
    style: 'cursor:pointer'
  });

  // Clicking the logo always goes to main menu
  logo.addEventListener('click', () => {
    location.hash = '#/';
  });

  wrap.appendChild(title);
  wrap.appendChild(logo);
  return wrap;
}
