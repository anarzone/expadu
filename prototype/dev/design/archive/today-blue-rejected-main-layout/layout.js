'use strict';
// Reserve the real dock height, including selected ideas and safe-area padding.
const dock = document.querySelector('#dock-zone');
function updateDockClearance() {
  document.documentElement.style.setProperty('--dock-clearance', `${Math.ceil(dock.getBoundingClientRect().height) + 16}px`);
}
new ResizeObserver(updateDockClearance).observe(dock);
updateDockClearance();

// Keep keyboard focus above the fixed Composer, including when it grows.
document.addEventListener('focusin', event => {
  const target = event.target;
  if (!(target instanceof HTMLElement) || dock.contains(target) || sheet.contains(target)) return;
  const rect = target.getBoundingClientRect();
  const edge = dock.getBoundingClientRect().top - 12;
  if (rect.bottom <= edge) return;
  const scrollRoot = workspace.contains(target) ? document.querySelector('#workspace-scroll') : window;
  scrollRoot.scrollBy({top: rect.bottom - edge, behavior: 'instant'});
});
