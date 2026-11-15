document.addEventListener('click', function (event) {
  var toggle = event.target.closest('[data-sidebar-toggle]');
  if (toggle) {
    var open = document.body.classList.toggle('whatsapp-sidebar-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    return;
  }

  if (event.target.closest('[data-sidebar-close]') || event.target.closest('#whatsapp-sidebar a')) {
    document.body.classList.remove('whatsapp-sidebar-open');
    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (button) {
      button.setAttribute('aria-expanded', 'false');
    });
  }
});

document.addEventListener('keydown', function (event) {
  if (event.key !== 'Escape' || !document.body.classList.contains('whatsapp-sidebar-open')) return;
  document.body.classList.remove('whatsapp-sidebar-open');
  document.querySelectorAll('[data-sidebar-toggle]').forEach(function (button) {
    button.setAttribute('aria-expanded', 'false');
    button.focus();
  });
});
