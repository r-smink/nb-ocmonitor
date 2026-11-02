'use strict';
// Geen inline scripts (CSP): alle interactie via data-attributen.
document.addEventListener('DOMContentLoaded', function () {
  const toggle = document.getElementById('themeToggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      const t = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = t;
      try { localStorage.setItem('ocm-theme', t); } catch (e) { /* negeren */ }
    });
  }

  // Kopieerknoppen: <button data-copy="#id">
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const el = document.querySelector(btn.dataset.copy);
      if (!el) return;
      const text = el.value !== undefined ? el.value : el.textContent;
      navigator.clipboard.writeText(text.trim()).then(function () {
        const old = btn.textContent;
        btn.textContent = 'Gekopieerd';
        setTimeout(function () { btn.textContent = old; }, 1600);
      });
    });
  });

  // Bevestiging bij gevaarlijke formulieren: <form data-confirm="Weet je het zeker?">
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });

  // Tabellen met klikbare rijen: <tr data-href="...">
  document.querySelectorAll('tr[data-href]').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
      if (e.target.closest('a, button, form')) return;
      window.location = tr.dataset.href;
    });
  });

  // Pagina met lopende opdrachten automatisch verversen: <main data-refresh="5">
  const main = document.querySelector('main[data-refresh]');
  if (main) setTimeout(function () { window.location.reload(); }, parseInt(main.dataset.refresh, 10) * 1000);
});
