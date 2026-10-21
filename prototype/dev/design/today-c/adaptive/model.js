(function (root) {
    function select({ now, day, routeId, feature, delay } = {}) {
        const clock = Date.parse(now);
        if (
            delay?.active &&
            delay.id &&
            routeId &&
            delay.routeId === routeId &&
            Date.parse(delay.expiresAt) > clock
        )
            return { layout: 'delay', featureId: null, delayId: delay.id };
        if (
            feature?.important &&
            feature.id &&
            feature.date === day &&
            Date.parse(feature.endAt || feature.startAt) > clock
        )
            return { layout: 'event', featureId: feature.id, delayId: null };
        return { layout: 'everyday', featureId: null, delayId: null };
    }
    root.TodayAdaptive = { select };
    if (typeof module !== 'undefined') module.exports = root.TodayAdaptive;
})(globalThis);
