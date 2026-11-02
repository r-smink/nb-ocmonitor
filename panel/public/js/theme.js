'use strict';
// Zet het thema vóór de eerste render (geen flits). OC Monitor is standaard donker.
(function () {
  let t = 'dark';
  try { t = localStorage.getItem('ocm-theme') || 'dark'; } catch (e) { /* geen opslag beschikbaar */ }
  document.documentElement.dataset.theme = t;
})();
