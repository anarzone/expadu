/* Sample weather for the preview's sample day in Cologne, hour by hour.
   Fictional review data like the sample events, never a forecast: it agrees
   with the 21° and sun the app header shows at 13:10, then clouds over with
   showers through the evening. In the app this is the hourly forecast.
   Each hour is [sky, °C, chance of rain in %]; sky is clear, partly,
   cloudy, showers, rain or mist. The captured 8 September programme has
   no weather: it wasn't recorded. */
(function (root) {
    root.ExpaduSampleWeather = {
        source: 'Sample weather',
        days: {
            '2026-09-30': [
                ['clear', 11, 0], // 00:00
                ['clear', 11, 0],
                ['clear', 10, 0],
                ['clear', 10, 0],
                ['mist', 9, 0],
                ['mist', 9, 0],
                ['mist', 10, 0], // 06:00
                ['partly', 11, 0],
                ['partly', 13, 0],
                ['partly', 15, 0],
                ['clear', 17, 0],
                ['clear', 19, 0],
                ['clear', 20, 0], // 12:00
                ['clear', 21, 0],
                ['clear', 21, 0],
                ['partly', 21, 0],
                ['partly', 20, 10],
                ['cloudy', 19, 30],
                ['showers', 17, 60], // 18:00
                ['showers', 16, 70],
                ['rain', 15, 70],
                ['cloudy', 14, 40],
                ['partly', 13, 10],
                ['clear', 12, 0],
            ],
        },
    };
})(globalThis);
