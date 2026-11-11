// Print buttons on documents. Kept in a file so the CSP stays script-src 'self'.
document.addEventListener('click', function (event) {
  var trigger = event.target.closest('[data-print]');

  if (trigger) {
    event.preventDefault();
    window.print();
  }
});
