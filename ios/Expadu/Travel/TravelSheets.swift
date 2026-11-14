import API
import Core
import CoreLocation
import DesignSystem
import Maps
import SwiftUI

// MARK: - One departure

/// A band, opened: the line one way, its next three times, where to board, and every stop it goes on
/// to with the time it gets there (each stop's own board, read as its row shows; grey when it's the
/// timetable's). The other direction turns it round; a stop opens its own board.
struct DepartureSheet: View {
    let model: TravelModel
    @Environment(Session.self) private var session
    @Environment(\.dismiss) private var dismiss
    @State private var earlier = false

    var body: some View {
        if let open = model.open {
            let runs = model.runs(line: open.line, direction: open.direction)
            let index = min(open.run, max(0, runs.count - 1))
            if runs.indices.contains(index) {
                content(open: open, runs: runs, chosen: runs[index])
            } else {
                Text("No more runs this way today.").font(.geist(16)).padding(24)
            }
        }
    }

    private func content(open: TravelModel.Open, runs: [(dir: Station.Direction, run: Station.Run)], chosen: (dir: Station.Direction, run: Station.Run)) -> some View {
        let look = model.look(open.line)
        let line = model.station?.lines.first { $0.line == open.line }
        return VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                Text(open.line)
                    .font(.posterGrotesque(22)).monospacedDigit()
                    .foregroundStyle(look.ink)
                    .frame(minWidth: 44, minHeight: 44).padding(.horizontal, 3)
                    .background(look.fill, in: .rect(cornerRadius: 11))
                VStack(alignment: .leading, spacing: 1) {
                    Text("To \(chosen.dir.dest)").font(.geist(19, .bold)).lineLimit(1)
                    Text(meta(chosen.dir)).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                Spacer(minLength: 0)
                if model.directions(of: open.line).count > 1 {
                    CircleButton(symbol: "arrow.up.arrow.down", label: "Other direction") { model.turnDirection(session.api); earlier = false }
                }
                CircleButton(symbol: "xmark", label: "Close") { dismiss() }
            }
            .padding(.horizontal, 16).padding(.top, 18).padding(.bottom, 10)

            ScrollView {
                VStack(alignment: .leading, spacing: 10) {
                    if let note = note(open: open, chosen: chosen, runs: runs) {
                        Label { Text(note).font(.geist(12.5)) } icon: { Image(systemName: "exclamationmark.triangle").font(.system(size: 13)) }
                            .padding(.horizontal, 10).padding(.vertical, 8)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(Palette.quiet, in: .rect(cornerRadius: 12))
                    }
                    tiles(open: open, runs: runs, look: look)
                    HStack(spacing: 6) {
                        Image(systemName: "mappin").font(.system(size: 13))
                        Text(whereText(open.line)).font(.geist(13)).lineLimit(1)
                    }
                    .foregroundStyle(Palette.textSoft)
                    HStack(alignment: .firstTextBaseline) {
                        Text("Stops for the \(chosen.run.time)").font(.geist(13, .semibold))
                        Spacer()
                        if !chosen.run.timeBasis.isLive { Text("Timetable, not live").font(.geist(13, .medium)).foregroundStyle(Palette.textSoft) }
                    }
                    .padding(.top, 2)
                    stops(open: open, chosen: chosen, look: look)
                    if line?.isBus == false {
                        Button {
                            dismiss()
                            Task { await model.showOnMap(open.line, session.api) }
                        } label: {
                            Label("See line \(open.line) on the map", systemImage: "map")
                                .font(.geist(15, .semibold)).frame(maxWidth: .infinity).frame(height: 46)
                        }
                        .buttonStyle(PressStyle())
                        .background(Palette.quiet, in: .rect(cornerRadius: 14))
                        .padding(.top, 4)
                    }
                }
                .padding(.horizontal, 16).padding(.bottom, 30)
            }
            .scrollIndicators(.hidden)
        }
        .foregroundStyle(Palette.text)
        .animation(.smooth(duration: 0.35), value: open)
        .task(id: open.line) { await model.route(open.line, session.api) }
    }

    private func meta(_ dir: Station.Direction) -> String {
        var parts: [String] = []
        if let via = dir.via.first { parts.append("via \(via)") }
        if let headway = dir.headway { parts.append("every \(headway) min") }
        return parts.joined(separator: " · ")
    }

    private func note(open: TravelModel.Open, chosen: (dir: Station.Direction, run: Station.Run), runs: [(dir: Station.Direction, run: Station.Run)]) -> String? {
        if chosen.run.cancelled { return "The \(chosen.run.time) is cancelled." }
        if open.run == 0, let gone = runs.first(where: { $0.run.cancelled }), gone.run.id != chosen.run.id { return "The \(gone.run.time) is cancelled. This is the next one." }
        if chosen.dir.disrupted, let notice = model.station?.notice(for: open.line) { return notice.headline }
        if let text = chosen.run.notice { return text }
        if model.map, model.station?.lines.first(where: { $0.line == open.line })?.isBus == true { return "Buses are listed, not drawn." }
        return nil
    }

    private func tiles(open: TravelModel.Open, runs: [(dir: Station.Direction, run: Station.Run)], look: LineLook) -> some View {
        HStack(spacing: 7) {
            ForEach(Array(runs.prefix(3).enumerated()), id: \.element.run.id) { i, item in
                let minutes = item.run.minutes(from: model.now)
                Button {
                    withAnimation(.snappy) { model.open?.run = i }
                } label: {
                    VStack(alignment: .leading, spacing: 1) {
                        Text(item.run.cancelled ? "Cancelled" : minutes == 0 ? "Now" : "\(minutes) min")
                            .font(.posterGrotesque(19)).monospacedDigit()
                            .strikethrough(item.run.cancelled)
                        Text(item.run.time + (item.dir.dest != runs[0].dir.dest ? " \(item.dir.dest)" : ""))
                            .font(.geist(12.5)).monospacedDigit().foregroundStyle(Palette.textSoft).lineLimit(1)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.horizontal, 11).padding(.vertical, 8)
                    .background(i == open.run ? Palette.surface : Palette.quiet, in: .rect(cornerRadius: 14))
                    .overlay { if i == open.run { RoundedRectangle(cornerRadius: 14).strokeBorder(look.base, lineWidth: 2) } }
                    .contentShape(.rect)
                }
                .buttonStyle(PressStyle())
                .accessibilityAddTraits(i == open.run ? .isSelected : [])
            }
        }
        .sensoryFeedback(.selection, trigger: open.run)
    }

    private func whereText(_ line: String) -> String {
        let others = model.station?.area(of: line)?.lines.filter { $0 != line } ?? []
        let platform = model.station?.area(of: line)?.platform.map { "Platform \($0)" } ?? "Platform not known"
        let mode = model.station?.lines.first { $0.line == line }?.isBus == true ? "buses" : "trams"
        return others.isEmpty ? platform : "\(platform) · also \(mode) \(others.joined(separator: ", "))"
    }

    @ViewBuilder
    private func stops(open: TravelModel.Open, chosen: (dir: Station.Direction, run: Station.Run), look: LineLook) -> some View {
        let here = model.station?.name ?? ""
        let leaves = chosen.run.date ?? model.now
        if let route = model.stops(for: chosen.dir, line: open.line) {
            let after = route.after
            VStack(spacing: 0) {
                if !route.before.isEmpty {
                    if earlier {
                        ForEach(Array(route.before.enumerated()), id: \.offset) { _, stop in
                            StopRow(name: stop.name, time: nil, ride: nil, colour: look.base, kind: .past, changes: stop.changes, open: nil)
                        }
                    } else {
                        Button { withAnimation(.smooth) { earlier = true } } label: {
                            StopRow(name: "\(route.before.count) earlier stop\(route.before.count == 1 ? "" : "s")", time: nil, ride: nil, colour: look.base, kind: .collapsed, changes: [], open: nil)
                        }
                        .buttonStyle(PressStyle())
                    }
                }
                StopRow(name: here, time: (chosen.run.time, chosen.run.timeBasis.isLive), ride: nil, colour: look.base, kind: route.before.isEmpty ? .first : .here, changes: [], open: nil)
                let times = arrivals(after, line: open.line, dest: chosen.dir.dest, leaves: leaves)
                ForEach(Array(after.enumerated()), id: \.offset) { i, stop in
                    let at = times[i].map { (time: $0.time, live: $0.live) }
                    let ride: String? = at.flatMap { Self.minutes(from: chosen.run.time, to: $0.time) }.map { "\($0) min" }
                    StopRow(name: stop.name, time: at, ride: ride, colour: look.base, kind: i == after.count - 1 ? .last : .stop, changes: stop.changes,
                            loading: !model.board(stop.name, line: open.line)) {
                        Task { await model.go(to: stop.name, session.api) }
                    }
                    .task { await model.readStop(stop.name, line: open.line, session.api) }
                }
            }
        } else {
            VStack(spacing: 0) {
                StopRow(name: here, time: (chosen.run.time, chosen.run.timeBasis.isLive), ride: nil, colour: look.base, kind: .first, changes: [], open: nil)
                ForEach(Array(chosen.dir.via.enumerated()), id: \.offset) { _, stop in
                    StopRow(name: stop, time: nil, ride: nil, colour: look.base, kind: .stop, changes: [], open: nil)
                }
                StopRow(name: chosen.dir.dest, time: nil, ride: nil, colour: look.base, kind: .last, changes: [], open: nil)
            }
        }
    }

    /// Each stop's time for this tram, in order: never before the stop ahead of it, so an earlier
    /// tram's time at a stop further on is never taken for this one's. A stop not yet read leaves a gap.
    private func arrivals(_ stops: [LineRoute.Stop], line: String, dest: String, leaves: Date) -> [(time: String, live: Bool, date: Date)?] {
        var last = leaves
        return stops.enumerated().map { i, stop in
            let ahead = stops.dropFirst(i + 1).map(\.name) + [dest]
            guard let at = model.time(at: stop.name, line: line, ahead: ahead, after: last) else { return nil }
            last = at.date
            return at
        }
    }

    static func minutes(from a: String, to b: String) -> Int? {
        func value(_ t: String) -> Int? {
            let parts = t.split(separator: ":").compactMap { Int($0) }
            return parts.count == 2 ? parts[0] * 60 + parts[1] : nil
        }
        guard let x = value(a), let y = value(b) else { return nil }
        let d = (y - x + 1440) % 1440
        return d > 0 && d < 300 ? d : nil
    }
}

/// A stop on the line's column: a ring on the line in its colour, filled where you are.
private struct StopRow: View {
    enum Kind { case first, here, stop, last, past, collapsed }

    let name: String
    let time: (time: String, live: Bool)?
    let ride: String?
    let colour: Color
    let kind: Kind
    let changes: [String]
    var loading = false
    let open: (() -> Void)?

    var body: some View {
        let row = HStack(alignment: .top, spacing: 12) {
            ZStack(alignment: .top) {
                Rectangle()
                    .fill(kind == .collapsed ? AnyShapeStyle(colour.opacity(0.5)) : AnyShapeStyle(colour))
                    .frame(width: 4)
                    .padding(.top, kind == .first ? 18 : 0)
                    .padding(.bottom, kind == .last ? 28 : 0)
                if kind != .collapsed {
                    Circle()
                        .fill(kind == .here || kind == .first ? colour : Palette.surface)
                        .overlay { Circle().strokeBorder(colour, lineWidth: 3.5) }
                        .frame(width: 18, height: 18)
                        .shadow(color: kind == .here || kind == .first ? colour.opacity(0.3) : .clear, radius: 5)
                        .padding(.top, 9)
                }
            }
            .frame(width: 18)
            VStack(alignment: .leading, spacing: 3) {
                Text(name)
                    .font(.geist(kind == .collapsed ? 13 : 15, kind == .here || kind == .first ? .bold : kind == .collapsed ? .semibold : .medium))
                    .foregroundStyle(kind == .collapsed ? Palette.navTeal : Palette.text)
                if !changes.isEmpty, kind != .here, kind != .first {
                    Text("Change to " + changes.prefix(4).joined(separator: " · ")).font(.geist(12)).foregroundStyle(Palette.textSoft)
                }
            }
            .padding(.top, 8)
            Spacer(minLength: 0)
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                if let ride { Text(ride).font(.geist(12.5, .medium)).foregroundStyle(Palette.textSoft) }
                if let time {
                    Text(time.time).font(.geist(13.5, kind == .here || kind == .first ? .bold : .semibold)).monospacedDigit()
                        .foregroundStyle(time.live ? Palette.text : Palette.textSoft)
                } else if loading {
                    Text("…").font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                }
                if open != nil { Image(systemName: "chevron.right").font(.system(size: 11, weight: .semibold)).foregroundStyle(Palette.textSoft) }
            }
            .padding(.top, 8)
        }
        .frame(minHeight: kind == .collapsed ? 34 : 46, alignment: .top)
        .opacity(kind == .past ? 0.5 : 1)
        .contentShape(.rect)

        if let open {
            Button(action: open) { row }
                .buttonStyle(PressStyle())
                .accessibilityHint("Shows \(name)’s board")
        } else {
            row
        }
    }
}

/// A round glass button in a sheet's head (close, other direction).
struct CircleButton: View {
    let symbol: String
    let label: String
    let tap: () -> Void

    var body: some View {
        Button(action: tap) {
            Image(systemName: symbol).font(.system(size: 15, weight: .semibold)).frame(width: 40, height: 40).contentShape(.circle)
        }
        .buttonStyle(PressStyle())
        .glassEffect(.regular.interactive(), in: .circle)
        .accessibilityLabel(label)
    }
}

// MARK: - Your next trip

/// The next trip on your day (Today's leave card), as Travel shows it: ride, there, spare, and go.
struct LeaveSheet: View {
    let leave: TodayPage.Leave
    let otherWays: () -> Void
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(TripPlanner.self) private var planner
    @Environment(\.dismiss) private var dismiss
    @State private var starting = false
    /// Each press, for its haptic.
    @State private var pressed = 0

    /// Start trip, in one tap: a trip already running is replaced (said on the new one, with Undo); once
    /// started, the trip opens.
    private func start() {
        guard let way = leave.ways.first else { return }
        pressed += 1
        starting = true
        Task {
            _ = await today.startTrip(session.api, way: way)
            if today.page?.trip != nil {
                dismiss()
                planner.showTrip = true
            }
            starting = false
        }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 10) {
                Image(systemName: "clock").font(.system(size: 19, weight: .semibold)).foregroundStyle(.white)
                    .frame(width: 44, height: 44).background(LineLook(hex: leave.color ?? "#A01E5A").fill, in: .rect(cornerRadius: 11))
                VStack(alignment: .leading, spacing: 1) {
                    Text("Leave at \(leave.leaveTime ?? "")").font(.geist(19, .bold))
                    Text(["To \(leave.to)", leave.start, leave.line.map { "line \(Board.badge($0))" }].compactMap { $0 }.joined(separator: " · "))
                        .font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                Spacer(minLength: 0)
                CircleButton(symbol: "xmark", label: "Close") { dismiss() }
            }
            HStack(spacing: 7) {
                tile("Leave", leave.leaveTime ?? "–")
                tile("There", leave.arriveTime ?? "–")
                tile("Spare", leave.spareMinutes.map { "\($0) min" } ?? "–")
            }
            Button { start() } label: {
                Label(starting ? "Starting…" : "Start trip", systemImage: "figure.walk")
                    .font(.geist(17, .semibold)).frame(maxWidth: .infinity).frame(height: 52)
            }
            .buttonStyle(PressStyle())
            .buttonFace(Palette.action, radius: 14)
            .foregroundStyle(Palette.onAction)
            .disabled(starting || leave.ways.isEmpty)
            .sensoryFeedback(.impact(weight: .medium), trigger: pressed)
            Button(action: otherWays) {
                Label("Other routes", systemImage: "arrow.triangle.branch").font(.geist(15, .semibold)).frame(maxWidth: .infinity).frame(height: 44)
            }
            .buttonStyle(PressStyle())
            .background(Palette.quiet, in: .rect(cornerRadius: 14))
        }
        .foregroundStyle(Palette.text)
        .padding(16).padding(.top, 6)
    }

    private func tile(_ label: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(label).font(.geist(12.5)).foregroundStyle(Palette.textSoft)
            Text(value).font(.posterGrotesque(19)).monospacedDigit()
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 11).padding(.vertical, 8)
        .background(Palette.quiet, in: .rect(cornerRadius: 14))
    }
}

// MARK: - Find a stop

/// Find a stop: the closest one, the one shown now (★ it), their ★ stops, or one by name.
struct StopPicker: View {
    let model: TravelModel
    @Environment(Session.self) private var session
    @Environment(\.dismiss) private var dismiss
    @State private var query = ""
    @State private var hits: [StationHit] = []
    @State private var favourites: [StationHit] = []
    @State private var searched = false
    @FocusState private var focused: Bool

    var body: some View {
        VStack(spacing: 0) {
            HStack {
                Text("Find a stop").font(.posterGrotesque(26))
                Spacer()
                CircleButton(symbol: "xmark", label: "Close") { dismiss() }
            }
            .padding(.horizontal, 16).padding(.top, 18)
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass").foregroundStyle(Palette.textSoft)
                TextField("Find a stop", text: $query)
                    .font(.geist(17)).focused($focused)
                    .textInputAutocapitalization(.words).autocorrectionDisabled()
                    .submitLabel(.search)
                if !query.isEmpty {
                    Button { query = "" } label: { Image(systemName: "xmark.circle.fill").foregroundStyle(Palette.textSoft) }
                        .accessibilityLabel("Clear")
                }
            }
            .padding(.horizontal, 12).frame(height: 46)
            .background(Palette.quiet, in: .rect(cornerRadius: 14))
            .padding(.horizontal, 16).padding(.vertical, 12)

            ScrollView {
                VStack(alignment: .leading, spacing: 8) {
                    if query.trimmingCharacters(in: .whitespaces).count < 2 {
                        group {
                            row(icon: "location", title: "The stop closest to you", sub: "Where you are now") {
                                Task { await model.go(to: nil, session.api) }
                            }
                        }
                        if let station = model.station {
                            heading("Shown now")
                            group {
                                HStack(spacing: 0) {
                                    row(icon: "tram.fill", title: station.name, sub: "\(station.areas.count) boarding area\(station.areas.count == 1 ? "" : "s")", check: true) { dismiss() }
                                    Button {
                                        Task {
                                            await model.toggleFavourite(session.api)
                                            await loadFavourites()
                                        }
                                    } label: {
                                        Image(systemName: model.favourite ? "star.fill" : "star")
                                            .font(.system(size: 18)).foregroundStyle(model.favourite ? Palette.brandOrange : Palette.textSoft)
                                            .frame(width: 52, height: 52).contentShape(.rect)
                                            .contentTransition(.symbolEffect(.replace))
                                    }
                                    .buttonStyle(PressStyle())
                                    .accessibilityLabel(model.favourite ? "Remove from favourites" : "Add to favourites")
                                    .sensoryFeedback(.success, trigger: model.favourite)
                                }
                            }
                        }
                        if !favourites.isEmpty {
                            heading("Favourites")
                            group { ForEach(favourites) { stop in stationRow(stop, star: true) } }
                        }
                    } else if !hits.isEmpty {
                        heading("Stops")
                        group { ForEach(hits) { stop in stationRow(stop, star: favourites.contains { $0.name == stop.name }) } }
                    } else if searched {
                        VStack(alignment: .leading, spacing: 4) {
                            Text("No KVB stop by that name.").font(.geist(16, .semibold))
                            Text("Check the spelling, or plan the trip instead.").font(.geist(15)).foregroundStyle(Palette.textSoft)
                        }
                        .padding(.top, 20).padding(.horizontal, 4)
                    }
                }
                .padding(.horizontal, 16).padding(.bottom, 30)
                .animation(.snappy(duration: 0.25), value: hits)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        .foregroundStyle(Palette.text)
        .task { await loadFavourites() }
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 2 else { hits = []; searched = false; return }
            try? await Task.sleep(for: .milliseconds(220))
            guard !Task.isCancelled else { return }
            do throws(APIFailure) { hits = try await session.api.stations(q) } catch { hits = [] }
            searched = true
        }
    }

    private func loadFavourites() async {
        do throws(APIFailure) { favourites = try await session.api.travelPlaces().favouriteStops } catch {}
    }

    private func heading(_ text: String) -> some View {
        Text(text).font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft).padding(.top, 8).padding(.horizontal, 4)
    }

    private func group<Content: View>(@ViewBuilder _ content: () -> Content) -> some View {
        VStack(spacing: 0) { content() }
            .background(Palette.canvas, in: .rect(cornerRadius: 16))
            .overlay { RoundedRectangle(cornerRadius: 16).strokeBorder(Palette.border) }
    }

    private func row(icon: String, title: String, sub: String?, check: Bool = false, tap: @escaping () -> Void) -> some View {
        Button(action: tap) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 17)).frame(width: 36, height: 36).background(Palette.quiet, in: .rect(cornerRadius: 10))
                VStack(alignment: .leading, spacing: 1) {
                    Text(title).font(.geist(16, .semibold)).lineLimit(1)
                    if let sub { Text(sub).font(.geist(13)).foregroundStyle(Palette.textSoft) }
                }
                Spacer(minLength: 0)
                if check { Image(systemName: "checkmark").font(.system(size: 15, weight: .semibold)).foregroundStyle(Palette.navTeal) }
            }
            .padding(.horizontal, 12).frame(minHeight: 56).contentShape(.rect)
        }
        .buttonStyle(PressStyle())
    }

    private func stationRow(_ stop: StationHit, star: Bool) -> some View {
        Button { Task { await model.go(to: stop.name, session.api) } } label: {
            HStack(spacing: 12) {
                Image(systemName: star ? "star.fill" : "tram.fill").font(.system(size: 16))
                    .foregroundStyle(star ? Palette.brandOrange : Palette.text)
                    .frame(width: 36, height: 36).background(Palette.quiet, in: .rect(cornerRadius: 10))
                VStack(alignment: .leading, spacing: 4) {
                    Text(stop.name).font(.geist(16, .semibold)).lineLimit(1)
                    LineBadges(lines: Array(stop.lines.prefix(7)), colours: stop.colors ?? [:])
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.right").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft)
            }
            .padding(.horizontal, 12).frame(minHeight: 60).contentShape(.rect)
        }
        .buttonStyle(PressStyle())
    }
}

/// Small line badges in KVB's colours (a stop's lines).
struct LineBadges: View {
    let lines: [String]
    var colours: [String: String] = [:]

    var body: some View {
        HStack(spacing: 3) {
            ForEach(lines, id: \.self) { line in
                LineBadge(line: line, color: colours[line] ?? "#4A4A4A", height: 18)
            }
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Lines " + lines.joined(separator: ", "))
    }
}

// MARK: - KVB's notice

/// KVB's notice as a pop-up: the English brief, KVB's own words in German, and their page.
struct NoticePopup: View {
    let notice: Station.Notice
    let close: () -> Void
    @State private var german = false
    @Environment(\.openURL) private var openURL

    var body: some View {
        ZStack(alignment: .top) {
            Color(hex: 0x19140C).opacity(0.3).ignoresSafeArea().onTapGesture(perform: close)
            VStack(alignment: .leading, spacing: 12) {
                HStack(alignment: .top, spacing: 10) {
                    Image(systemName: "exclamationmark").font(.system(size: 13, weight: .heavy))
                        .foregroundStyle(.white).frame(width: 26, height: 26).background(Color(hex: 0xC2321A), in: .circle)
                    VStack(alignment: .leading, spacing: 3) {
                        Text(notice.headline).font(.geist(18, .bold)).fixedSize(horizontal: false, vertical: true)
                        Text(german ? "In German · from KVB" : "Translated from German").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                    }
                    Spacer(minLength: 0)
                    CircleButton(symbol: "xmark", label: "Close", tap: close)
                }
                // KVB's German can run long (every date of a works plan): it scrolls; the links stay in reach.
                ScrollView {
                    Text(german ? (notice.details ?? notice.title) : (notice.brief?.text ?? notice.title))
                        .font(.geist(15)).fixedSize(horizontal: false, vertical: true)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .contentTransition(.opacity)
                }
                .scrollBounceBehavior(.basedOnSize)
                .frame(maxHeight: 300)
                HStack(spacing: 18) {
                    Button(german ? "Show it in English" : "KVB’s words, in German") { withAnimation(.smooth(duration: 0.25)) { german.toggle() } }
                    if let url = notice.url.flatMap(URL.init(string:)) {
                        Button { openURL(url) } label: { HStack(spacing: 4) { Text("KVB’s notice"); Image(systemName: "arrow.up.right") } }
                    }
                }
                .font(.geist(14.5, .semibold)).foregroundStyle(Palette.navTeal)
                .frame(minHeight: 44)
            }
            .foregroundStyle(Palette.text)
            .padding(18)
            .glassEffect(.regular, in: .rect(cornerRadius: 26))
            .padding(.horizontal, 14).padding(.top, 120)
        }
    }
}

// MARK: - Map

/// The lines through the station on the city map: each way's next stops and where it ends, in the
/// line's colour; the rail's line drawn bold and the rest faint. Buses are listed, not drawn.
struct TravelMap: View {
    let model: TravelModel

    /// A way's drawing: along its tracks, else through its stops from the station.
    private static func points(_ way: LinesMap.Way, from station: LinesMap.Point) -> [CLLocationCoordinate2D] {
        if let path = way.path {
            let track = Polyline.decode(path).map { CLLocationCoordinate2D(latitude: $0.latitude, longitude: $0.longitude) }
            if track.count > 1 { return track }
        }
        return ([station] + way.stops + [way.end].compactMap { $0 }).map { CLLocationCoordinate2D(latitude: $0.lat, longitude: $0.lng) }
    }

    var body: some View {
        let map = model.linesMap
        let focus = model.line
        let station = map.map { CLLocationCoordinate2D(latitude: $0.station.lat, longitude: $0.station.lng) }
        let lines: [CityMap.Line] = map.map { m in
            m.lines.flatMap { line in
                line.ways.enumerated().map { i, way in
                    let on = focus == nil || focus == line.line
                    return CityMap.Line(id: "m\(line.line)-\(i)", points: Self.points(way, from: m.station), color: UIColor(model.look(line.line).base),
                                        width: on ? (focus == nil ? 5 : 8) : 4, faint: !on)
                }
            }
        } ?? []
        let shown = (map?.lines ?? []).filter { focus == nil || $0.line == focus }
        // Every stop the shown lines make, once (lines share stops), as a ring in its line's colour.
        var seen = Set<String>()
        let stops: [CityMap.Pin] = shown.flatMap { line in
            line.ways.flatMap { way in way.stops.compactMap { stop -> CityMap.Pin? in
                let key = "\(stop.name)|\(Int(stop.lat * 2000))|\(Int(stop.lng * 2000))"
                guard seen.insert(key).inserted else { return nil }
                return CityMap.Pin(id: "stop-\(key)", coordinate: CLLocationCoordinate2D(latitude: stop.lat, longitude: stop.lng), title: stop.name,
                                   kind: .stop(ring: UIColor(model.look(line.line).deep), symbol: LineBadge.symbol(line.mode)))
            } }
        }
        let ends: [CityMap.Pin] = shown.flatMap { line in
            line.ways.compactMap { way in way.end.map { end in
                CityMap.Pin(id: "end-\(line.line)-\(end.name)", coordinate: CLLocationCoordinate2D(latitude: end.lat, longitude: end.lng), title: end.name,
                            kind: .badge(text: line.line, fill: UIColor(model.look(line.line).base), ink: UIColor(model.look(line.line).ink)))
            } }
        }
        let pins = (map.map { [CityMap.Pin(id: "station", coordinate: CLLocationCoordinate2D(latitude: $0.station.lat, longitude: $0.station.lng), title: $0.station.name, kind: .place(symbol: "tram.fill"))] } ?? [])
            + stops + ends
        // All lines: the station and the next few stops each way. One line: all of it. Before the
        // lines come, where they are (never the whole world).
        let fit: [CLLocationCoordinate2D] = map == nil ? [model.place].compactMap { $0 } : focus == nil
            ? [station].compactMap { $0 } + (map?.lines ?? []).flatMap { $0.ways.flatMap { $0.stops.prefix(3) } }.map { CLLocationCoordinate2D(latitude: $0.lat, longitude: $0.lng) }
            : lines.filter { !$0.faint }.flatMap(\.points)
        ZStack(alignment: .bottom) {
            CityMap(lines: lines, pins: pins, fit: fit, padding: UIEdgeInsets(top: 260, left: 40, bottom: 160, right: 40), showsUser: true, fitID: (focus ?? "").hashValue)
            if let note = note(map) {
                Text(note).font(.geist(12.5)).foregroundStyle(Palette.text)
                    .padding(.horizontal, 12).padding(.vertical, 8)
                    .glassEffect(.regular, in: .rect(cornerRadius: 14))
                    .padding(.horizontal, 16).padding(.bottom, 150)
            }
        }
    }

    private func note(_ map: LinesMap?) -> String? {
        guard let map else { return "Drawing the lines…" }
        return map.buses.isEmpty ? nil : "Buses \(map.buses.joined(separator: " · ")) stop here too."
    }
}
