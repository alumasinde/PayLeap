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

(function () {
  'use strict';

  var reader = document.querySelector('[data-document-reader]');
  if (!reader) return;

  var url = reader.getAttribute('data-progress-url');
  var bar = reader.querySelector('[data-reader-bar]');
  var label = reader.querySelector('[data-reader-label]');
  var status = reader.querySelector('[data-reader-status]');
  var hint = reader.querySelector('[data-reader-hint]');
  var tokenMeta = document.querySelector('meta[name="csrf-token"]');
  var token = tokenMeta ? tokenMeta.getAttribute('content') : '';
  var completed = parseInt(reader.getAttribute('data-start-progress') || '0', 10) >= 100;
  var tickSeconds = 5;

  if (!url || completed) return;

  function render(data) {
    if (typeof data.progress_percent !== 'undefined' && bar) {
      bar.value = data.progress_percent;
      bar.textContent = data.progress_percent + '%';
    }
    if (label) label.textContent = data.progress_percent + '%';

    if (data.status === 'completed') {
      completed = true;
      if (status) {
        status.textContent = 'Complete';
        status.className = 'status status-active';
      }
      if (hint) hint.textContent = 'You have completed this document.';
    } else if (status) {
      status.textContent = 'Reading';
      status.className = 'status status-pending';
    }
  }

  var timer = window.setInterval(function () {
    if (completed || document.visibilityState !== 'visible') return;

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': token
      },
      body: JSON.stringify({ seconds: tickSeconds })
    }).then(function (response) {
      return response.ok ? response.json() : null;
    }).then(function (data) {
      if (data && typeof data.progress_percent !== 'undefined') render(data);
    }).catch(function () {});
  }, tickSeconds * 1000);

  window.addEventListener('pagehide', function () {
    window.clearInterval(timer);
  });
})();
