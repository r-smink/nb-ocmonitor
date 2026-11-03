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

  // Bevestiging bij één knop in een formulier met meerdere knoppen: <button data-confirm-button="...">
  document.querySelectorAll('[data-confirm-button]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      if (!window.confirm(btn.dataset.confirmButton)) e.preventDefault();
    });
  });

  // Tabellen met klikbare rijen: <tr data-href="...">
  document.querySelectorAll('tr[data-href]').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
      if (e.target.closest('a, button, form')) return;
      window.location = tr.dataset.href;
    });
  });

  // Filters die direct versturen: <select data-autosubmit>
  document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
  });

  // Bulkselectie: <input type="checkbox" data-check-all="groep"> en <input type="checkbox" data-check="groep">,
  // met optioneel <span data-check-count="groep"> en <button data-check-submit="groep">.
  function syncChecks(group) {
    const boxes = document.querySelectorAll('input[data-check="' + group + '"]');
    const checked = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
    document.querySelectorAll('[data-check-count="' + group + '"]').forEach(function (el) { el.textContent = checked; });
    document.querySelectorAll('[data-check-submit="' + group + '"]').forEach(function (el) { el.disabled = checked === 0; });
    document.querySelectorAll('input[data-check-all="' + group + '"]').forEach(function (all) {
      all.checked = checked > 0 && checked === boxes.length;
      all.indeterminate = checked > 0 && checked < boxes.length;
    });
  }
  const groups = new Set();
  document.querySelectorAll('input[data-check]').forEach(function (box) {
    groups.add(box.dataset.check);
    box.addEventListener('change', function () { syncChecks(box.dataset.check); });
  });
  document.querySelectorAll('input[data-check-all]').forEach(function (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('input[data-check="' + all.dataset.checkAll + '"]').forEach(function (b) { b.checked = all.checked; });
      syncChecks(all.dataset.checkAll);
    });
  });
  groups.forEach(syncChecks);

  // Pagina met lopende opdrachten automatisch verversen: <main data-refresh="5">.
  // Niet zolang er een selectie of een ingevuld veld is (anders raakt de gebruiker die kwijt).
  const main = document.querySelector('main[data-refresh]');
  if (main) {
    const delay = parseInt(main.dataset.refresh, 10) * 1000;
    const tick = function () {
      const busy = document.querySelector('input[data-check]:checked') || (document.activeElement && document.activeElement.matches('input, select, textarea'));
      if (busy) setTimeout(tick, delay); else window.location.reload();
    };
    setTimeout(tick, delay);
  }
});
