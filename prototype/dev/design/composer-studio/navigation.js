(function (root) {
    'use strict';
    // File previews have opaque origins: history may change the fragment,
    // but must not replace index.html with its containing directory.
    function panelUrl(currentUrl, panel = null) {
        const documentUrl = currentUrl.split('#')[0];
        return panel
            ? `${documentUrl}#${encodeURIComponent(panel)}`
            : documentUrl;
    }
    const api = { panelUrl };
    if (typeof module !== 'undefined') module.exports = api;
    root.StudioNavigation = api;
})(globalThis);
