"use strict";

const scenarios = [
  { id: "everyday", title: "Everyday", file: "01-everyday.png" },
  { id: "important-event", title: "Important event", file: "02-important-event.png" },
  { id: "route-delay", title: "Route delay", file: "03-route-delay.png" },
  { id: "interactions", title: "Interactions", file: "04-interactions.png" },
];
const packs = [
  { version: 10, title: "Fresh redraw", path: "fresh-v10", note: "One appointment, connected travel, outlined recovery actions.", badge: "Latest" },
  { version: 9, title: "Final icon balance", path: "final-v9", note: "Useful symbols, a simple plus, direct opening." },
  { version: 8, title: "Direct opening", path: "direct-actions-v8", note: "Fewer opening controls; appointment dots." },
  { version: 7, title: "Distinct action icons", path: "action-icons-v7", note: "Different symbols for different destinations." },
  { version: 6, title: "Text actions", path: "simplified-v6", note: "The text-button exploration, kept for comparison.", badge: "Rejected" },
  { version: 5, title: "Layout review", path: "review-v5", note: "Saved ideas separated; clearer mobile event rail." },
  { version: 4, title: "Compact menu", path: "menu-v4", note: "Smaller mobile navigation and the Travel rename." },
];

const gallery = document.querySelector("#gallery");
const dialog = document.querySelector("#viewer");
const stage = document.querySelector("#viewer-stage");
const canvas = document.querySelector("#viewer-canvas");
const viewerImage = document.querySelector("#viewer-image");
const zoomLabel = document.querySelector("#zoom-level");
const previousButton = document.querySelector("#previous");
const nextButton = document.querySelector("#next");
const closeButton = document.querySelector("#close-viewer");
const state = {
  scenario: scenarios.find(item => item.id === location.hash.slice(1)) || scenarios[0],
  order: "newest",
  current: null,
  opener: null,
  scale: 1,
  fit: true,
  previousOverflow: "",
};
let drag = null;
let backdropPointer = false;

function orderedPacks() {
  return state.order === "oldest" ? [...packs].reverse() : packs;
}

function hasImage(pack) {
  return state.scenario.id !== "interactions" || !pack.noInteractions;
}

function imagePath(pack) {
  return pack.path + "/" + state.scenario.file;
}

function node(tag, className, text) {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (text) element.textContent = text;
  return element;
}

function renderGallery() {
  gallery.replaceChildren();
  gallery.dataset.scenario = state.scenario.id;
  document.querySelector("#scenario-title").textContent = state.scenario.title;
  const count = orderedPacks().filter(hasImage).length;
  document.querySelector("#board-count").textContent = count + " boards" +
    (state.scenario.id === "interactions" ? " · Interaction storyboards" : " · Desktop + mobile");
  document.querySelectorAll("[data-scenario]").forEach(button => {
    if (button.tagName === "BUTTON") button.setAttribute("aria-pressed", String(button.dataset.scenario === state.scenario.id));
  });

  orderedPacks().forEach(pack => {
    const card = node("article", "design-card");
    const heading = node("div", "card-heading");
    heading.append(node("span", "version", "v" + pack.version), node("h3", "", pack.title));
    if (pack.badge) heading.append(node("span", "badge" + (pack.badge === "Rejected" ? " archived" : ""), pack.badge));
    card.append(heading);
    if (hasImage(pack)) {
      const button = node("button", "board-button");
      button.type = "button";
      button.dataset.version = pack.version;
      button.setAttribute("aria-label", "Enlarge v" + pack.version + ": " + pack.title + " — " + state.scenario.title);
      const img = node("img");
      img.src = imagePath(pack);
      img.alt = "v" + pack.version + " " + state.scenario.title + " design";
      img.loading = pack.version > 6 ? "eager" : "lazy";
      img.decoding = "async";
      img.width = state.scenario.id === "interactions" ? 1024 : 1536;
      img.height = state.scenario.id === "interactions" ? 1536 : 1024;
      button.append(img);
      button.addEventListener("click", () => openViewer(pack, button));
      card.append(button);
    } else {
      card.append(node("div", "missing-board", "No interaction board was created for this version."));
    }
    card.append(node("p", "card-note", pack.note));
    gallery.append(card);
  });
}

function fitScale() {
  const padding = window.innerWidth <= 640 ? 20 : 32;
  return Math.min(
    (stage.clientWidth - padding) / viewerImage.naturalWidth,
    (stage.clientHeight - padding) / viewerImage.naturalHeight,
    1,
  );
}

function applyScale(scale, preservePosition = true) {
  if (!viewerImage.naturalWidth) return;
  const centerX = (stage.scrollLeft + stage.clientWidth / 2) / Math.max(canvas.scrollWidth, 1);
  const centerY = (stage.scrollTop + stage.clientHeight / 2) / Math.max(canvas.scrollHeight, 1);
  state.scale = Math.max(0.1, Math.min(3, scale));
  viewerImage.style.width = Math.round(viewerImage.naturalWidth * state.scale) + "px";
  viewerImage.style.height = Math.round(viewerImage.naturalHeight * state.scale) + "px";
  zoomLabel.value = Math.round(state.scale * 100) + "%";
  stage.classList.toggle("is-zoomed", state.scale > fitScale() + 0.01);
  if (preservePosition) {
    stage.scrollLeft = centerX * canvas.scrollWidth - stage.clientWidth / 2;
    stage.scrollTop = centerY * canvas.scrollHeight - stage.clientHeight / 2;
  } else {
    stage.scrollTo(0, 0);
  }
  document.querySelector("#zoom-out").disabled = state.scale <= 0.1;
  document.querySelector("#zoom-in").disabled = state.scale >= 3;
}

function fitImage() {
  state.fit = true;
  applyScale(fitScale(), false);
}

function changeZoom(delta) {
  state.fit = false;
  applyScale(state.scale + delta);
}

function showPack(pack) {
  state.current = pack;
  const sequence = orderedPacks().filter(hasImage);
  const index = sequence.indexOf(pack);
  const title = "v" + pack.version + " · " + pack.title;
  document.querySelector("#viewer-title").textContent = title;
  document.querySelector("#viewer-context").textContent = state.scenario.title +
    (state.scenario.id === "interactions" ? " · Interaction storyboard" : " · Desktop + mobile") +
    (pack.badge ? " · " + pack.badge : "");
  document.querySelector("#viewer-position").textContent = (index + 1) + " / " + sequence.length;
  previousButton.disabled = index === 0;
  nextButton.disabled = index === sequence.length - 1;
  document.querySelector("#original-link").href = imagePath(pack);
  document.querySelector("#image-error").hidden = true;
  stage.setAttribute("aria-busy", "true");
  state.fit = true;
  viewerImage.onload = () => {
    stage.setAttribute("aria-busy", "false");
    fitImage();
  };
  viewerImage.onerror = () => {
    stage.setAttribute("aria-busy", "false");
    document.querySelector("#image-error").hidden = false;
  };
  viewerImage.alt = title + " — " + state.scenario.title;
  viewerImage.src = imagePath(pack);
  if (viewerImage.complete && viewerImage.naturalWidth) {
    stage.setAttribute("aria-busy", "false");
    fitImage();
  }
}

function openViewer(pack, opener) {
  state.opener = opener;
  state.previousOverflow = document.documentElement.style.overflow;
  document.documentElement.style.overflow = "hidden";
  dialog.showModal();
  showPack(pack);
  closeButton.focus({ preventScroll: true });
}

function navigateVersion(direction) {
  const sequence = orderedPacks().filter(hasImage);
  const next = sequence[sequence.indexOf(state.current) + direction];
  if (next) showPack(next);
}

document.querySelectorAll("button[data-scenario]").forEach(button => {
  button.addEventListener("click", () => {
    state.scenario = scenarios.find(item => item.id === button.dataset.scenario);
    history.replaceState(null, "", "#" + state.scenario.id);
    renderGallery();
  });
});

document.querySelectorAll("[data-columns]").forEach(button => {
  if (button.tagName !== "BUTTON") return;
  button.addEventListener("click", () => {
    gallery.dataset.columns = button.dataset.columns;
    document.querySelectorAll("button[data-columns]").forEach(other => {
      other.setAttribute("aria-pressed", String(other === button));
    });
  });
});

document.querySelector("#order").addEventListener("change", event => {
  state.order = event.target.value;
  renderGallery();
});
closeButton.addEventListener("click", () => dialog.close());
dialog.addEventListener("close", () => {
  document.documentElement.style.overflow = state.previousOverflow;
  stage.classList.remove("is-panning");
  drag = null;
  state.opener?.focus({ preventScroll: true });
});
previousButton.addEventListener("click", () => navigateVersion(-1));
nextButton.addEventListener("click", () => navigateVersion(1));
document.querySelector("#zoom-out").addEventListener("click", () => changeZoom(-0.25));
document.querySelector("#zoom-in").addEventListener("click", () => changeZoom(0.25));
document.querySelector("#fit-image").addEventListener("click", fitImage);
document.querySelector("#actual-size").addEventListener("click", () => {
  state.fit = false;
  applyScale(1);
});
viewerImage.addEventListener("dblclick", () => {
  if (state.fit) {
    state.fit = false;
    applyScale(Math.max(1, fitScale() * 1.75));
  } else {
    fitImage();
  }
});

function outsideDialog(event) {
  const bounds = dialog.getBoundingClientRect();
  return event.clientX < bounds.left || event.clientX > bounds.right ||
    event.clientY < bounds.top || event.clientY > bounds.bottom;
}
dialog.addEventListener("pointerdown", event => { backdropPointer = outsideDialog(event); });
dialog.addEventListener("click", event => {
  if (backdropPointer && outsideDialog(event)) dialog.close();
  backdropPointer = false;
});
dialog.addEventListener("keydown", event => {
  if (event.key === "ArrowLeft") { event.preventDefault(); navigateVersion(-1); }
  if (event.key === "ArrowRight") { event.preventDefault(); navigateVersion(1); }
  if (event.key === "+" || event.key === "=") { event.preventDefault(); changeZoom(0.25); }
  if (event.key === "-") { event.preventDefault(); changeZoom(-0.25); }
  if (event.key === "0") { event.preventDefault(); fitImage(); }
});

viewerImage.addEventListener("pointerdown", event => {
  if (event.pointerType !== "mouse" || event.button !== 0 || !stage.classList.contains("is-zoomed")) return;
  event.preventDefault();
  drag = { x: event.clientX, y: event.clientY, left: stage.scrollLeft, top: stage.scrollTop };
  viewerImage.setPointerCapture(event.pointerId);
  stage.classList.add("is-panning");
});
viewerImage.addEventListener("pointermove", event => {
  if (!drag) return;
  stage.scrollLeft = drag.left - (event.clientX - drag.x);
  stage.scrollTop = drag.top - (event.clientY - drag.y);
});
function endPan(event) {
  if (viewerImage.hasPointerCapture(event.pointerId)) viewerImage.releasePointerCapture(event.pointerId);
  drag = null;
  stage.classList.remove("is-panning");
}
viewerImage.addEventListener("pointerup", endPan);
viewerImage.addEventListener("pointercancel", endPan);
window.addEventListener("resize", () => {
  if (dialog.open && state.fit && viewerImage.complete) fitImage();
});
window.addEventListener("hashchange", () => {
  const scenario = scenarios.find(item => item.id === location.hash.slice(1));
  if (!scenario || scenario.id === state.scenario.id) return;
  if (dialog.open) dialog.close();
  state.scenario = scenario;
  renderGallery();
});
renderGallery();
