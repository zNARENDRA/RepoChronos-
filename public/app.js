document.addEventListener('submit', (event) => {
  if (event.target.method.toLowerCase() !== 'post') return;
  const button = event.submitter;
  if (button) {
    button.disabled = true;
    button.textContent = 'Working…';
  }
});
window.addEventListener('pageshow', (e) => {
  if (e.persisted) window.location.reload();
});
