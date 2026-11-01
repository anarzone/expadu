/* Keep the navigation round trip in isolated fixtures. */
(function () {
    const base = new URL('../../', document.currentScript.src);
    ExpaduStore.routes.composer = new URL(
        '../composer-studio/tests/fixtures/journey.html',
        base,
    ).href;
    document.addEventListener('click', (event) => {
        if (
            !window.JourneyNavigation &&
            event.target.closest('.journey-resume')
        ) {
            event.preventDefault();
            location.href = new URL(
                'tests/fixtures/navigation.html?journey=resume',
                base,
            ).href;
        }
    });
})();
