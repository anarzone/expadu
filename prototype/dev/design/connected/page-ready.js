/* A page appears whole, as an app's screen does (owner, 16 Oct 2026: "I
   want it to load as a app page"). This is the last script in the page's
   head and is marked blocking="render": the browser paints nothing until it
   has run, so the first frame already holds the page, the menu and
   Composer's button in their places. Two frames later the shell's
   transitions come back (section-bar.css holds them while <html> has
   .is-booting). */
(() => {
    requestAnimationFrame(() =>
        requestAnimationFrame(() =>
            document.documentElement.classList.remove('is-booting'),
        ),
    );
})();
