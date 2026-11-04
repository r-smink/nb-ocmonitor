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

  // Zijbalk op kleine schermen: <button data-nav-toggle aria-controls="sidebar">
  document.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const open = document.body.classList.toggle('nav-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.body.classList.contains('nav-open')) {
      document.body.classList.remove('nav-open');
      document.querySelectorAll('[data-nav-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    }
  });

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

  // Sitedetail: icoonbalk met scrollspy. <nav data-scrollspy> met <a data-spy-link="key">, secties <section data-spy="key">.
  // De sectie met het grootste zichtbare deel (onder de sticky kop) markeert zijn icoon; klikken zet hem meteen.
  const rail = document.querySelector('[data-scrollspy]');
  if (rail) {
    const links = {};
    rail.querySelectorAll('[data-spy-link]').forEach(function (a) { links[a.dataset.spyLink] = a; });
    const order = Object.keys(links);
    const visible = {};
    let lockedUntil = 0;
    const setActive = function (key) {
      order.forEach(function (k) {
        links[k].classList.toggle('on', k === key);
        if (k === key) links[k].setAttribute('aria-current', 'true'); else links[k].removeAttribute('aria-current');
      });
    };
    const fromHash = function () {
      const key = (window.location.hash || '').replace('#sd-', '');
      return links[key] ? key : null;
    };
    setActive(fromHash() || order[0]);
    order.forEach(function (k) {
      links[k].addEventListener('click', function () {
        setActive(k);
        // Tijdens het (smooth) scrollen niet laten terugspringen door de observer.
        lockedUntil = Date.now() + 900;
      });
    });
    if ('IntersectionObserver' in window) {
      const thresholds = [];
      for (let i = 0; i <= 20; i++) thresholds.push(i / 20);
      const observer = new IntersectionObserver(function (entries) {
        // "Het meest in beeld": zichtbaar deel ten opzichte van wat er maximaal in de band past. Zo wint een
        // korte sectie die helemaal in beeld is van een lange volgende sectie die er half in staat.
        entries.forEach(function (e) {
          const room = Math.min(e.boundingClientRect.height, e.rootBounds ? e.rootBounds.height : window.innerHeight) || 1;
          visible[e.target.dataset.spy] = e.isIntersecting ? e.intersectionRect.height / room : 0;
        });
        if (Date.now() < lockedUntil) return;
        let best = null;
        let bestHeight = 0;
        order.forEach(function (k) {
          if ((visible[k] || 0) > bestHeight) { best = k; bestHeight = visible[k]; }
        });
        if (best) setActive(best);
      }, { rootMargin: '-88px 0px -55% 0px', threshold: thresholds });
      document.querySelectorAll('section[data-spy]').forEach(function (s) { observer.observe(s); });
      // Helemaal onderaan krijgt een korte laatste sectie nooit het grootste stuk van de band: dan die markeren.
      let ticking = false;
      window.addEventListener('scroll', function () {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(function () {
          ticking = false;
          if (Date.now() >= lockedUntil && window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
            setActive(order[order.length - 1]);
          }
        });
      }, { passive: true });
    }
  }
});
