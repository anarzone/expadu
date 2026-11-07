import API
import Core
import CoreLocation
import Maps
import DesignSystem
import SafariServices
import SwiftUI

// MARK: - Sheets

/// The canvas's weather sheet: now, what it means for each plan today, then Hours / Rain / Next days.
struct WeatherSheet: View {
    let page: TodayPage
    @Environment(\.dismiss) private var dismiss
    @State private var tab = 0

    private var weather: TodayPage.Weather? { page.weather }

    var body: some View {
        ScrollView {
            if let weather {
                VStack(alignment: .leading, spacing: 0) {
                    top
                    now(weather)
                    let rows = forYourDay(weather)
                    if !rows.isEmpty {
                        Text("For your day").font(.posterGrotesque(20)).foregroundStyle(Palette.text)
                            .padding(.top, 26).padding(.bottom, 10).padding(.leading, 4)
                        VStack(spacing: 0) {
                            ForEach(Array(rows.enumerated()), id: \.offset) { index, row in
                                if index > 0 { Rectangle().fill(Palette.border).frame(height: 1).padding(.leading, 59) }
                                row.modifier(WxUp(delay: 0.08 + 0.05 * Double(index)))
                            }
                        }
                        .clipShape(.rect(cornerRadius: 22))
                        .card(radius: 22)
                    }
                    WxTabs(tab: $tab).padding(.top, 22).padding(.bottom, 12)
                    Group {
                        switch tab {
                        case 0: WxHours(hours: weather.hours)
                        case 1: WxRain(weather: weather, plans: coming)
                        default: WxDays(weather: weather)
                        }
                    }
                    .id(tab)
                    // The old pane goes at once; the new one rises in (wxup), as on the canvas.
                    .transition(.identity)
                    .modifier(WxUp(delay: 0, duration: 0.32))
                }
                .padding(.horizontal, 16).padding(.top, 20).padding(.bottom, 40)
            }
        }
        .scrollIndicators(.hidden)
        .background(Palette.canvas)
        .presentationDetents([.large])
        .presentationDragIndicator(.visible)
        .presentationBackground(Palette.canvas)
    }

    private var top: some View {
        HStack(alignment: .top) {
            VStack(alignment: .leading, spacing: 2) {
                Text("Weather").font(.posterGrotesque(28)).tracking(-0.4).foregroundStyle(Palette.text)
                Text("Cologne · updated \(TodayPage.clock(page.now))").font(.geist(13)).foregroundStyle(Palette.textSoft)
            }
            Spacer()
            Button { dismiss() } label: {
                Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                    .frame(width: 44, height: 44)
                    .glassEffect(.regular.interactive(), in: .circle)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Close")
        }
        .padding(.bottom, 6)
    }

    private func now(_ weather: TodayPage.Weather) -> some View {
        HStack(spacing: 12) {
            Image(systemName: WeatherSymbol.name(weather.icon)).symbolRenderingMode(.monochrome)
                .font(.system(size: 44)).foregroundStyle(WeatherSymbol.tint(weather.icon))
                .frame(width: 52, height: 52)
            Text("\(weather.temperature)°").font(.posterGrotesque(56)).tracking(-1).foregroundStyle(Palette.text)
            VStack(alignment: .leading, spacing: 2) {
                Text(weather.condition).font(.geist(17, .bold)).foregroundStyle(Palette.text)
                if let range = weather.range { Text(range).font(.geist(13.5)).foregroundStyle(Palette.textSoft) }
            }
        }
        .padding(.top, 8).padding(.bottom, 4).padding(.horizontal, 2)
        .modifier(WxUp(delay: 0, duration: 0.5))
        .accessibilityElement(children: .combine)
    }

    /// Today's plans still to come, each with the weather at its hour.
    private var coming: [TodayPage.Plan] {
        page.plans.filter { !$0.completed && $0.state == "upcoming" && $0.start != nil }
    }

    private func forYourDay(_ weather: TodayPage.Weather) -> [WxPlanRow] {
        coming.compactMap { plan in
            guard let start = plan.start, let hour = weather.at(start) else { return nil }
            let sky = [hour.condition?.lowercased(), hour.rain.map(WxRain.words)].compactMap { $0 }.joined(separator: ", ")
            return WxPlanRow(time: start, title: plan.title, note: [plan.shortPlace, sky.isEmpty ? nil : sky].compactMap { $0 }.joined(separator: " · "),
                             icon: hour.icon ?? weather.icon, temperature: hour.temperature)
        }
    }
}

struct WxPlanRow: View {
    let time: String
    let title: String
    let note: String
    let icon: String
    let temperature: Int?

    var body: some View {
        HStack(spacing: 13) {
            Text(time).font(.geist(15, .bold)).monospacedDigit().frame(width: 46, alignment: .leading)
            VStack(alignment: .leading, spacing: 1) {
                Text(title).font(.geist(16, .semibold)).lineLimit(1)
                if !note.isEmpty { Text(note).font(.geist(13.5)).foregroundStyle(Palette.textSoft).lineLimit(2) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Image(systemName: WeatherSymbol.name(icon)).symbolRenderingMode(.monochrome)
                .font(.system(size: 18)).foregroundStyle(WeatherSymbol.tint(icon)).frame(width: 20)
            Text(temperature.map { "\($0)°" } ?? "–").font(.geist(16, .bold)).frame(width: 30, alignment: .trailing)
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 14).padding(.vertical, 10).frame(minHeight: 58)
        .accessibilityElement(children: .combine)
    }
}

/// The canvas's segmented control: a card thumb that springs between Hours, Rain and Next days.
struct WxTabs: View {
    @Binding var tab: Int
    private let names = ["Hours", "Rain", "Next days"]

    var body: some View {
        GeometryReader { proxy in
            let width = (proxy.size.width - 4) / 3
            ZStack(alignment: .leading) {
                RoundedRectangle(cornerRadius: 16).fill(Palette.surface)
                    .shadow(color: Color(hex: 0x211D15).opacity(0.16), radius: 2, y: 1)
                    .frame(width: width).padding(.vertical, 2)
                    .offset(x: 2 + width * CGFloat(tab))
                HStack(spacing: 0) {
                    ForEach(names.indices, id: \.self) { index in
                        Button { withAnimation(.spring(duration: 0.42, bounce: 0.3)) { tab = index } } label: {
                            Text(names[index]).font(.geist(14, .semibold))
                                .foregroundStyle(tab == index ? Palette.text : Palette.textSoft)
                                .frame(maxWidth: .infinity, maxHeight: .infinity).contentShape(.rect)
                        }
                        .buttonStyle(.plain)
                        .accessibilityAddTraits(tab == index ? [.isSelected] : [])
                    }
                }
                .padding(.horizontal, 2)
            }
        }
        .frame(height: 36)
        .background(Palette.quiet, in: .rect(cornerRadius: 18))
        .sensoryFeedback(.selection, trigger: tab)
    }
}

/// The hour labels under the chart and the bars: "Now", then the hour.
struct WxHourLabels: View {
    let hours: [TodayPage.Weather.Hour]

    var body: some View {
        HStack(spacing: 0) {
            ForEach(Array(hours.enumerated()), id: \.offset) { index, hour in
                Text(index == 0 ? "Now" : String(Int(hour.hour.prefix(2)) ?? 0))
                    .font(.geist(11.5, .semibold)).monospacedDigit()
                    .foregroundStyle(index == 0 ? Palette.text : Palette.textSoft)
                    .frame(maxWidth: .infinity)
            }
        }
        .padding(.top, 4)
    }
}

enum WxPalette {
    static let sun = Color(hex: 0xF2A516)
    static let rain = Color(hex: 0x3F86D6)
    static let rainText = Color(light: 0x2F6FD0, dark: 0x7FB0F5)

    /// The canvas's wxTone: cold blue, mild green, warm yellow, hot orange.
    static func tone(_ temperature: Int) -> Color {
        temperature <= 4 ? Color(hex: 0x5AC8FA) : temperature <= 8 ? Color(hex: 0x6FD3B0) : temperature <= 11 ? Color(hex: 0xFFD60A) : Color(hex: 0xFF9F0A)
    }
}

/// Hours: each hour's sky, a smooth line through the temperatures, and the hours under it.
struct WxHours: View {
    let hours: [TodayPage.Weather.Hour]
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var drawn = false

    private var shown: [TodayPage.Weather.Hour] { hours.filter { $0.temperature != nil } }

    var body: some View {
        let hours = shown
        let temps = hours.compactMap(\.temperature)
        VStack(spacing: 0) {
            HStack(spacing: 0) {
                ForEach(Array(hours.enumerated()), id: \.offset) { index, hour in
                    Image(systemName: WeatherSymbol.name(hour.icon ?? "")).symbolRenderingMode(.monochrome)
                        .font(.system(size: 17)).foregroundStyle(WeatherSymbol.tint(hour.icon ?? "")).frame(height: 20)
                        .frame(maxWidth: .infinity)
                        .modifier(WxFade(delay: 0.035 * Double(index)))
                }
            }
            if temps.count > 1 {
                GeometryReader { proxy in
                    let points = WxCurve.points(temps, in: proxy.size)
                    ZStack(alignment: .topLeading) {
                        WxCurve(temps: temps, closed: true)
                            .fill(LinearGradient(colors: [WxPalette.sun.opacity(0.32), WxPalette.sun.opacity(0)], startPoint: .top, endPoint: .bottom))
                            .opacity(drawn ? 1 : 0)
                            .animation(reduceMotion ? nil : .easeOut(duration: 0.6).delay(0.45), value: drawn)
                        WxCurve(temps: temps, closed: false)
                            .trim(from: 0, to: drawn ? 1 : 0)
                            .stroke(WxPalette.sun, style: StrokeStyle(lineWidth: 3, lineCap: .round, lineJoin: .round))
                            .animation(reduceMotion ? nil : Motion.ios(0.9).delay(0.1), value: drawn)
                        ForEach(Array(points.enumerated()), id: \.offset) { index, point in
                            WxPoint(now: index == 0, temperature: temps[index], order: index).position(point)
                        }
                    }
                }
                .frame(height: 150).padding(.top, 2)
            }
            WxHourLabels(hours: hours)
        }
        .onAppear { drawn = true }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(hours.compactMap { hour in hour.temperature.map { "\(hour.hour) \($0)°" } }.joined(separator: ", "))
    }
}

struct WxPoint: View {
    let now: Bool
    let temperature: Int
    let order: Int

    var body: some View {
        ZStack {
            Circle().fill(now ? WxPalette.sun : Palette.surface).frame(width: 8, height: 8)
                .overlay { Circle().strokeBorder(WxPalette.sun, lineWidth: now ? 0 : 2.5).frame(width: 13, height: 13) }
                .background { if now { Circle().fill(WxPalette.sun.opacity(0.3)).frame(width: 16, height: 16) } }
                .modifier(WxFade(delay: 0.15 + 0.055 * Double(order)))
            Text("\(temperature)°").font(.geist(12.5, .bold)).foregroundStyle(Palette.text).fixedSize()
                .offset(y: -18)
                .modifier(WxUp(delay: 0.18 + 0.055 * Double(order), duration: 0.35))
        }
    }
}

/// A smooth line through the hourly temperatures, each point over its column (the canvas's wxCurve).
nonisolated private struct WxCurve: Shape {
    let temps: [Int]
    let closed: Bool

    static func points(_ temps: [Int], in size: CGSize) -> [CGPoint] {
        guard let low = temps.min(), let high = temps.max() else { return [] }
        let lo = Double(low - 1), hi = Double(high + 1), count = Double(temps.count)
        return temps.enumerated().map { index, temperature in
            CGPoint(x: (Double(index) + 0.5) * size.width / count,
                    y: 34 + (1 - (Double(temperature) - lo) / (hi - lo)) * (size.height - 58))
        }
    }

    func path(in rect: CGRect) -> Path {
        let points = Self.points(temps, in: rect.size)
        var path = Path()
        guard let first = points.first, let last = points.last else { return path }
        path.move(to: first)
        for index in 0..<(points.count - 1) {
            let p0 = points[max(index - 1, 0)], p1 = points[index], p2 = points[index + 1], p3 = points[min(index + 2, points.count - 1)]
            path.addCurve(to: p2,
                          control1: CGPoint(x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6),
                          control2: CGPoint(x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6))
        }
        if closed {
            path.addLine(to: CGPoint(x: last.x, y: rect.height))
            path.addLine(to: CGPoint(x: first.x, y: rect.height))
            path.closeSubpath()
        }
        return path
    }
}

/// Rain: what the next hours hold, then the chance of rain each hour as bars.
struct WxRain: View {
    let weather: TodayPage.Weather
    let plans: [TodayPage.Plan]

    static func words(_ chance: Int) -> String {
        chance >= 50 ? "rain likely" : chance >= 20 ? "\(chance)% chance of rain" : "dry"
    }

    private var hours: [TodayPage.Weather.Hour] { weather.hours }

    /// "Rain from about 21:00", or the server's own words for the day.
    private var title: String {
        if let wet = hours.first(where: { ($0.rain ?? 0) >= 50 }) { return "Rain from about \(wet.hour)" }
        if let maybe = hours.first(where: { ($0.rain ?? 0) >= 20 }) { return "Maybe rain from about \(maybe.hour)" }
        return weather.summary ?? "Dry for the next \(hours.count) hours"
    }

    /// The peak, and whether a plan today falls in the rain.
    private var note: String? {
        var parts: [String] = []
        if let peak = hours.max(by: { ($0.rain ?? 0) < ($1.rain ?? 0) }), let chance = peak.rain {
            if chance >= 20 {
                parts.append("Up to \(chance)% around \(peak.hour).")
            } else if let last = hours.last {
                parts.append(chance < 5 ? "No rain expected until \(last.hour)." : "No hour above \(chance)% until \(last.hour).")
            }
        }
        let wet = plans.first { plan in plan.start.flatMap { weather.at($0)?.rain }.map { $0 >= 50 } ?? false }
        if let wet, let start = wet.start {
            parts.append("Your \(wet.title) at \(start) falls in it.")
        } else if !plans.isEmpty, plans.allSatisfy({ plan in (plan.start.flatMap { weather.at($0)?.rain } ?? 0) < 20 }) {
            parts.append(plans.count == 1 ? "Your plan today looks dry." : "Your plans today look dry.")
        }
        return parts.isEmpty ? nil : parts.joined(separator: " ")
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            VStack(alignment: .leading, spacing: 3) {
                Text(title).font(.geist(17, .bold)).foregroundStyle(Palette.text)
                if let note { Text(note).font(.geist(14)).foregroundStyle(Palette.textSoft).lineSpacing(2) }
            }
            .padding(.horizontal, 2).padding(.top, 2).padding(.bottom, 14)
            HStack(alignment: .bottom, spacing: 5) {
                ForEach(Array(hours.enumerated()), id: \.offset) { index, hour in
                    WxBar(chance: hour.rain ?? 0, order: index)
                }
            }
            .frame(height: 130)
            WxHourLabels(hours: hours)
            Text("Chance of rain each hour").font(.geist(12.5)).foregroundStyle(Palette.textSoft).padding(.top, 10)
        }
        .accessibilityElement(children: .combine)
    }
}

struct WxBar: View {
    let chance: Int
    let order: Int
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var grown = false

    var body: some View {
        VStack(spacing: 4) {
            Spacer(minLength: 0)
            if chance >= 20 { Text("\(chance)%").font(.geist(10.5, .bold)).foregroundStyle(WxPalette.rainText).fixedSize() }
            UnevenRoundedRectangle(topLeadingRadius: 6, bottomLeadingRadius: 3, bottomTrailingRadius: 3, topTrailingRadius: 6)
                .fill(WxPalette.rain.opacity(chance >= 50 ? 1 : chance >= 20 ? 0.62 : 0.3))
                .frame(height: 130 * CGFloat(max(chance, 3)) / 100)
                .scaleEffect(x: 1, y: grown || reduceMotion ? 1 : 0, anchor: .bottom)
        }
        .frame(maxWidth: .infinity)
        .onAppear { withAnimation(.spring(duration: 0.55, bounce: 0.35).delay(0.035 * Double(order))) { grown = true } }
    }
}

/// Next days: each day's sky, its most likely rain, and its low to high on one shared scale.
struct WxDays: View {
    let weather: TodayPage.Weather

    private var days: [TodayPage.Weather.Day] { weather.days.filter { $0.low != nil && $0.high != nil } }

    var body: some View {
        let days = days
        let lo = days.compactMap(\.low).min() ?? 0, hi = max(days.compactMap(\.high).max() ?? 1, lo + 1)
        let place = { (t: Int) in CGFloat(t - lo) / CGFloat(hi - lo) }
        VStack(spacing: 0) {
            ForEach(Array(days.enumerated()), id: \.element.id) { index, day in
                if index > 0 { Rectangle().fill(Palette.border).frame(height: 1) }
                WxDayRow(name: index == 0 ? "Today" : Self.weekday(day.date), day: day,
                         from: place(day.low ?? lo), to: place(day.high ?? hi),
                         now: index == 0 ? place(min(max(weather.temperature, lo), hi)) : nil, order: index)
            }
        }
        .padding(.vertical, 4)
        .clipShape(.rect(cornerRadius: 22))
        .card(radius: 22)
    }

    static func weekday(_ date: String) -> String {
        let read = DateFormatter()
        read.locale = Locale(identifier: "en_GB")
        read.timeZone = TimeZone(identifier: "Europe/Berlin")
        read.dateFormat = "yyyy-MM-dd"
        guard let day = read.date(from: date) else { return date }
        read.dateFormat = "EEE"
        return read.string(from: day)
    }
}

struct WxDayRow: View {
    let name: String
    let day: TodayPage.Weather.Day
    let from: CGFloat
    let to: CGFloat
    let now: CGFloat?
    let order: Int
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var grown = false

    var body: some View {
        HStack(spacing: 8) {
            Text(name).font(.geist(16, .semibold)).frame(width: 58, alignment: .leading)
            VStack(spacing: 1) {
                Image(systemName: WeatherSymbol.name(day.icon ?? "")).symbolRenderingMode(.monochrome)
                    .font(.system(size: 17)).foregroundStyle(WeatherSymbol.tint(day.icon ?? "")).frame(height: 20)
                if let rain = day.rain, rain >= 30 { Text("\(rain)%").font(.geist(11, .bold)).foregroundStyle(WxPalette.rainText) }
            }
            .frame(width: 44)
            Text("\(day.low ?? 0)°").font(.geist(16, .semibold)).foregroundStyle(Palette.textSoft).frame(width: 30, alignment: .trailing)
            GeometryReader { proxy in
                let width = proxy.size.width
                ZStack(alignment: .leading) {
                    Capsule().fill(Palette.quiet)
                    Capsule()
                        .fill(LinearGradient(colors: [WxPalette.tone(day.low ?? 0), WxPalette.tone(day.high ?? 0)], startPoint: .leading, endPoint: .trailing))
                        .frame(width: max(width * (to - from), width * 0.04))
                        .scaleEffect(x: grown || reduceMotion ? 1 : 0, y: 1, anchor: .leading)
                        .offset(x: width * from)
                    if let now {
                        Circle().fill(Palette.text).frame(width: 10, height: 10)
                            .overlay { Circle().strokeBorder(Palette.surface, lineWidth: 2).frame(width: 14, height: 14) }
                            .offset(x: width * now - 5)
                    }
                }
                .frame(height: 6).frame(maxHeight: .infinity)
            }
            .frame(height: 14)
            Text("\(day.high ?? 0)°").font(.geist(16, .bold)).frame(width: 30, alignment: .leading)
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 16).frame(minHeight: 52)
        .modifier(WxUp(delay: 0.045 * Double(order), duration: 0.4))
        .onAppear { withAnimation(Motion.ios(0.6).delay(0.12 + 0.045 * Double(order))) { grown = true } }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("\(name), \(day.low ?? 0)° to \(day.high ?? 0)°" + (day.rain.map { $0 >= 30 ? ", \($0)% chance of rain" : "" } ?? ""))
    }
}

/// The canvas's wxup: in from 8 pt below, on its own delay.
struct WxUp: ViewModifier {
    let delay: Double
    var duration = 0.45
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown || reduceMotion ? 1 : 0)
            .offset(y: shown || reduceMotion ? 0 : 8)
            .onAppear { withAnimation(Motion.ios(duration).delay(delay)) { shown = true } }
    }
}

struct WxFade: ViewModifier {
    let delay: Double
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown || reduceMotion ? 1 : 0)
            .onAppear { withAnimation(.easeOut(duration: 0.3).delay(delay)) { shown = true } }
    }
}

/// A city site, inside the app.
struct SafariView: UIViewControllerRepresentable {
    let url: URL

    func makeUIViewController(context: Context) -> SFSafariViewController { SFSafariViewController(url: url) }
    func updateUIViewController(_ controller: SFSafariViewController, context: Context) {}
}
