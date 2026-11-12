(function () {
  'use strict';

  var sidebar = document.getElementById('sidebar');
  var scrim = document.querySelector('.sidebar-scrim');
  var toggleButtons = document.querySelectorAll('[data-sidebar-toggle]');
  var mobileQuery = window.matchMedia('(max-width: 1024px)');
  var collapseKey = 'mshahara.sidebar.collapsed';
  var collapsed = false;

  try {
    collapsed = window.localStorage.getItem(collapseKey) === '1';
  } catch (error) {
    collapsed = false;
  }

  function setCollapsed(value, persist) {
    collapsed = value;
    document.body.classList.toggle('sidebar-collapsed', value && !mobileQuery.matches);

    toggleButtons.forEach(function (button) {
      button.setAttribute('aria-expanded', String(!value || mobileQuery.matches));
    });

    if (persist) {
      try {
        window.localStorage.setItem(collapseKey, value ? '1' : '0');
      } catch (error) {
        // Local storage can be unavailable in private browsing contexts.
      }
    }
  }

  function openSidebar() {
    if (!sidebar) return;
    document.body.classList.remove('sidebar-collapsed');
    sidebar.classList.add('is-open');
    if (scrim) scrim.hidden = false;
  }

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('is-open');
    if (scrim) scrim.hidden = true;
  }

  toggleButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      if (mobileQuery.matches) {
        openSidebar();
      } else {
        setCollapsed(!collapsed, true);
      }
    });
  });

  document.querySelectorAll('[data-sidebar-close]').forEach(function (element) {
    element.addEventListener('click', closeSidebar);
  });

  if (sidebar) {
    sidebar.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeSidebar);
    });
  }

  document.querySelectorAll('.side-group-head').forEach(function (summary) {
    summary.addEventListener('click', function (event) {
      if (!mobileQuery.matches && collapsed) {
        event.preventDefault();
        setCollapsed(false, true);

        var group = summary.parentElement;
        if (group && group.tagName === 'DETAILS') group.open = true;
      }
    });
  });

  document.querySelectorAll('.side-link, .side-group-head').forEach(function (element) {
    var label = element.querySelector('span');
    if (!label) return;

    var text = label.textContent.trim();
    if (text) element.setAttribute('title', text);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeSidebar();
  });

  setCollapsed(collapsed, false);

  var onViewportChange = function () {
    setCollapsed(collapsed, false);
    if (!mobileQuery.matches) closeSidebar();
  };

  if (mobileQuery.addEventListener) {
    mobileQuery.addEventListener('change', onViewportChange);
  } else if (mobileQuery.addListener) {
    mobileQuery.addListener(onViewportChange);
  }

  window.addEventListener('resize', onViewportChange);

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

  document.querySelectorAll('[data-modal-open]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = document.getElementById(button.getAttribute('data-modal-open'));
      if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
    });
  });

  document.querySelectorAll('dialog.modal').forEach(function (dialog) {
    dialog.querySelectorAll('[data-modal-close]').forEach(function (button) {
      button.addEventListener('click', function () { dialog.close(); });
    });

    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) dialog.close();
    });

    if (dialog.hasAttribute('data-autoopen')) dialog.showModal();
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
