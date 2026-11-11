(function () {
  'use strict';

  var sidebar = document.getElementById('sidebar');
  var scrim = document.querySelector('.sidebar-scrim');

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('is-open');
    if (scrim) scrim.hidden = false;
  }

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('is-open');
    if (scrim) scrim.hidden = true;
  }

  document.querySelectorAll('[data-sidebar-toggle]').forEach(function (button) {
    button.addEventListener('click', openSidebar);
  });

  document.querySelectorAll('[data-sidebar-close]').forEach(function (element) {
    element.addEventListener('click', closeSidebar);
  });

  if (sidebar) {
    sidebar.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeSidebar);
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeSidebar();
  });

  var companySelect = document.querySelector('[data-company-select]');
  if (companySelect && companySelect.form) {
    companySelect.addEventListener('change', function () {
      companySelect.form.submit();
    });
  }

  document.addEventListener('click', function (event) {
    document.querySelectorAll('details.user-menu[open]').forEach(function (details) {
      if (!details.contains(event.target)) {
        details.removeAttribute('open');
      }
    });
  });

  document.querySelectorAll('[data-tabs]').forEach(function (nav) {
    var buttons = nav.querySelectorAll('[data-tab]');

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        var name = button.getAttribute('data-tab');

        buttons.forEach(function (candidate) {
          candidate.classList.toggle('is-active', candidate === button);
        });

        document.querySelectorAll('[data-tab-panel]').forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-tab-panel') !== name;
        });
      });
    });
  });
})();
