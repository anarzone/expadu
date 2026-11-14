import API
import Core
import CoreLocation
import DesignSystem
import Maps
import SwiftUI

/// The routes (the canvas's results): the way drawn on the map, and the ways as a list in
/// its lines' colours: when to leave and when you're there, why it's good, its steps, and Start trip
/// or Add to my day. Sorted as you pick; today's conditions say what was checked.
struct TripRoutesView: View {
    @Bindable var planner: TripPlanner
    /// Back to the search, to change where to.
    let back: () -> Void
    /// Straight back to the Travel board.
    let close: () -> Void
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var picked: Int?
    @State private var details: Int?
    @State private var conditions = false
    @State private var whenOpen = false
    /// The Bike tab: the bike route on its own, as Google and Apple keep it.
    @State private var byBike = false
    /// The list's panel pulled up to fill most of the screen.
    @State private var panelLarge = false
    @State private var panelDrag: CGFloat = 0
    @State private var screenHeight: CGFloat = 874
    @State private var undo: PlannedTrip?

    var body: some View {
        let options = shown(planner.options)
        let current = options.first { $0.index == picked } ?? options.first
        ZStack(alignment: .top) {
            map(current, bottom: mapBottom(options))
                .ignoresSafeArea()
            VStack(spacing: 10) {
                head
                tools
                if conditions, let answer = planner.answer {
                    ConditionsCard(planner: planner, answer: answer, current: current, close: { withAnimation(.smooth) { conditions = false } })
                        .padding(.horizontal, 12)
                        .transition(.opacity.combined(with: .move(edge: .top)))
                }
                Spacer(minLength: 0)
                if planner.loading {
                    LoadingCard(step: planner.loadStep).padding(.horizontal, 16).padding(.bottom, 30)
                } else if let problem = planner.problem {
                    StateCard(title: problem, text: problem == "Where are you starting?" ? "We need a starting point to find routes." : "Try a stop nearby, or take off a preference.",
                              button: problem == "Where are you starting?" ? "Add a starting point" : "Change the plan", go: back)
                        .padding(.horizontal, 16).padding(.bottom, 30)
                }
            }
            if !planner.loading, planner.problem == nil, !options.isEmpty {
                panel(options)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .onGeometryChange(for: CGFloat.self) { $0.size.height } action: { screenHeight = $0 }
        .animation(.smooth(duration: 0.35), value: conditions)
        .sheet(isPresented: $whenOpen, onDismiss: { Task { await planner.plan(session.api) } }) {
            WhenSheet(planner: planner)
                .presentationDetents([.height(planner.when == .now ? 210 : 420)])
                .presentationBackground(Palette.surface)
                .presentationCornerRadius(32)
        }
        .sheet(item: $planner.savedSheet) { planned in
            AddedSheet(planner: planner, planned: planned, close: { kept in
                planner.savedSheet = nil
                undo = kept
            })
            .presentationDetents([.height(330)])
            .presentationBackground(Palette.surface)
            .presentationCornerRadius(32)
        }
        .alert("That didn’t work", isPresented: Binding(get: { planner.startingProblem != nil }, set: { if !$0 { planner.startingProblem = nil } })) {
            Button("OK", role: .cancel) {
                planner.startingProblem = nil
                if planner.refreshAfterProblem {
                    planner.refreshAfterProblem = false
                    withAnimation(.smooth(duration: 0.3)) { details = nil }
                    Task { await planner.plan(session.api) }
                }
            }
        } message: { Text(planner.startingProblem ?? "") }
        .overlay(alignment: .bottom) {
            if let kept = undo {
                UndoToast(text: "Added to your day", action: "Undo") {
                    Task { await planner.undoAdd(kept, session.api) }
                    undo = nil
                }
                .padding(.bottom, 30)
                .transition(.move(edge: .bottom).combined(with: .opacity))
                .task { try? await Task.sleep(for: .seconds(4)); withAnimation { undo = nil } }
            }
        }
        .animation(.snappy, value: undo != nil)
        // A new answer or order starts from the top of the list, nothing picked.
        .onChange(of: planner.answer?.journeys.map(\.departAt)) { reset() }
        .onChange(of: planner.sort) { reset() }
        .onChange(of: planner.prefs) { reset() }
        .onChange(of: byBike) { reset() }
    }

    /// Whether the answer has a bike route: then the panel has its Transit and Bike tabs.
    private var hasBike: Bool { planner.options.contains { $0.journey.mode == "bike" } }

    /// The ways the tab shows: by bike on the Bike tab, every other way (trams, trains, on foot) on Transit.
    private func shown(_ options: [TripPlanner.Option]) -> [TripPlanner.Option] {
        let bike = byBike && hasBike
        return options.filter { ($0.journey.mode == "bike") == bike }
    }

    private func reset() {
        picked = nil
        details = nil
    }

    // MARK: Map

    /// How much of the map's bottom the results cover, so the way is fitted above them.
    private func mapBottom(_ options: [TripPlanner.Option]) -> CGFloat {
        guard !options.isEmpty else { return 120 }
        return panelHeight + 24
    }

    private func map(_ option: TripPlanner.Option?, bottom: CGFloat) -> some View {
        // Drawn as every trip map is (WayDrawing): the same lines, stops, badges and flag as the trip screen.
        let legs = option?.journey.tripLegs ?? []
        let lines = WayDrawing.lines(legs, prefix: "r\(option?.index ?? 0)-")
        var pins = WayDrawing.pins(legs, to: planner.destination?.name ?? "")
        // Before a route is drawn, the place it's for.
        if legs.isEmpty, let to = planner.destination {
            pins.append(.init(id: "to", coordinate: .init(latitude: to.lat, longitude: to.lng), title: to.name, kind: .destination))
        }
        let fit = lines.flatMap(\.points) + pins.map(\.coordinate)
        return CityMap(lines: lines, pins: pins, fit: fit, padding: UIEdgeInsets(top: 190, left: 44, bottom: bottom, right: 44),
                       fitID: (option?.index ?? -1) * 10 + (panelLarge ? 1 : 0))
            .background(Palette.quiet)
    }

    // MARK: Head

    private var head: some View {
        HStack(spacing: 8) {
            CircleButton(symbol: "chevron.left", label: "Change where to", tap: back)
            Button(action: back) {
                VStack(alignment: .leading, spacing: 1) {
                    Text("To \(planner.destination?.name ?? "")").font(.geist(16, .bold)).lineLimit(1)
                    Text("\(planner.whenLabel) · from \(planner.from?.name.lowercased() ?? "your location")").font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 14).frame(height: 48)
                .contentShape(.rect)
            }
            .buttonStyle(PressStyle())
            .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 16))
            // Straight back to the board, from any step of planning.
            CircleButton(symbol: "xmark", label: "Close, back to Travel", tap: close)
        }
        .padding(.horizontal, 12).padding(.top, 6)
        .foregroundStyle(Palette.text)
    }

    private var tools: some View {
        ScrollView(.horizontal) {
            HStack(spacing: 6) {
                Menu {
                    Picker("Sort", selection: $planner.sort) {
                        ForEach(TripPlanner.Sort.allCases) { Text($0.label).tag($0) }
                    }
                } label: {
                    tool(icon: "arrow.up.arrow.down", text: planner.sort.label, chevron: true)
                }
                Button { whenOpen = true } label: { tool(icon: "clock", text: planner.whenLabel, chevron: false) }
                    .buttonStyle(PressStyle())
                Button { withAnimation(.smooth) { conditions.toggle() } } label: { tool(icon: "checkmark", text: "Today’s conditions", chevron: false) }
                    .buttonStyle(PressStyle())
            }
            .padding(.horizontal, 12)
        }
        .scrollIndicators(.hidden)
        .sensoryFeedback(.selection, trigger: planner.sort)
    }

    private func tool(icon: String, text: String, chevron: Bool) -> some View {
        HStack(spacing: 5) {
            Image(systemName: icon).font(.system(size: 13, weight: .semibold))
            Text(text).font(.geist(14, .semibold))
            if chevron { Image(systemName: "chevron.down").font(.system(size: 11, weight: .semibold)) }
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 12).frame(height: 38)
        .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 12))
        .frame(minHeight: 44)
    }

}

extension TripRoutesView {
    private var panelHeight: CGFloat {
        let medium = max(360, screenHeight * 0.52), large = screenHeight * 0.84
        return min(large, max(medium - 80, (panelLarge ? large : medium) - panelDrag))
    }

    /// The ways in a panel over the map's lower half; pulled up it fills most of the screen. A row opens
    /// that way's steps in the same panel, with Show the way always in reach.
    func panel(_ options: [TripPlanner.Option]) -> some View {
        let chosen = options.first { $0.index == details }
        let row = listRows(options).routes.first { $0.option.index == details }
        return VStack(spacing: 0) {
            // The handle: drag or tap to make the panel taller or shorter.
            Capsule().fill(Palette.border).frame(width: 36, height: 5)
                .frame(maxWidth: .infinity).frame(height: 22)
                .contentShape(.rect)
                .onTapGesture { withAnimation(.smooth(duration: 0.35)) { panelLarge.toggle() } }
                .accessibilityLabel(panelLarge ? "Show more map" : "Show more routes")
                .accessibilityAddTraits(.isButton)
            if hasBike, chosen == nil {
                Picker("Travel by", selection: $byBike.animation(.smooth(duration: 0.3))) {
                    Label("Transit", systemImage: "tram.fill").tag(false)
                    Label("Bike", systemImage: "bicycle").tag(true)
                }
                .pickerStyle(.segmented)
                .padding(.horizontal, 16).padding(.bottom, 6)
                .sensoryFeedback(.selection, trigger: byBike)
            }
            if let chosen {
                RouteDetail(planner: planner, option: chosen, also: row?.also ?? [], lines: row?.lines ?? [],
                            kept: planner.added[chosen.index] != nil, best: chosen.index == options.first?.index,
                            back: { withAnimation(.smooth(duration: 0.3)) { details = nil } },
                            // One tap: a trip already running is replaced, said on the new one with Undo (never a question).
                            show: { Task { _ = await planner.start(chosen, session.api, today: today) } },
                            add: planner.target == nil ? nil : { Task { await planner.addToPlan(chosen, session.api) } })
                    .transition(.asymmetric(insertion: .move(edge: .trailing), removal: .move(edge: .trailing)).combined(with: .opacity))
            } else {
                ScrollView {
                    LazyVStack(spacing: 0) {
                        let rows = listRows(options)
                        ForEach(Array(rows.routes.enumerated()), id: \.element.option.id) { i, row in
                            routeButton(row.option, also: row.also, lines: row.lines, options: options)
                            if i < rows.routes.count - 1 || !rows.later.isEmpty { Divider().padding(.leading, 16) }
                        }
                        if !rows.later.isEmpty {
                            Text("Later").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(.horizontal, 16).padding(.top, 14).padding(.bottom, 2)
                            ForEach(Array(rows.later.enumerated()), id: \.element.id) { i, option in
                                routeButton(option, also: [], lines: [], options: options)
                                if i < rows.later.count - 1 { Divider().padding(.leading, 16) }
                            }
                        }
                        if !byBike {
                        Button { Task { await planner.showMore(session.api) } } label: {
                            Label(planner.more ? "Fewer routes" : "Show later routes", systemImage: planner.more ? "arrow.uturn.backward" : "plus")
                                .font(.geist(15, .semibold)).frame(maxWidth: .infinity).frame(height: 52)
                        }
                        .buttonStyle(PressStyle())
                        .foregroundStyle(Palette.navTeal)
                        }
                    }
                    .padding(.bottom, 24)
                }
                .scrollIndicators(.hidden)
                .transition(.opacity)
            }
        }
        .frame(height: panelHeight, alignment: .top)
        .frame(maxWidth: .infinity)
        .background(Palette.surface, in: .rect(topLeadingRadius: 28, topTrailingRadius: 28))
        .shadow(color: .black.opacity(0.18), radius: 18, y: -4)
        .frame(maxHeight: .infinity, alignment: .bottom)
        .ignoresSafeArea(edges: .bottom)
        .gesture(
            DragGesture(minimumDistance: 10)
                .onChanged { panelDrag = $0.translation.height }
                .onEnded { value in
                    withAnimation(.smooth(duration: 0.35)) {
                        if value.predictedEndTranslation.height < -80 { panelLarge = true }
                        else if value.predictedEndTranslation.height > 80 { panelLarge = false }
                        panelDrag = 0
                    }
                }
        )
        .sensoryFeedback(.selection, trigger: details)
    }

    /// Best for you first, then the rest in the order they leave: a list that reads down the clock.
    private func routeButton(_ option: TripPlanner.Option, also: [String], lines: [[LegStrip.Line]], options: [TripPlanner.Option]) -> some View {
        Button {
            withAnimation(.smooth(duration: 0.3)) { picked = option.index; details = option.index }
        } label: {
            RouteRow(planner: planner, option: option, best: !byBike && option.index == options.first?.index && planner.sort == .best,
                     selected: picked == option.index, also: also, lines: lines)
        }
        .buttonStyle(PressStyle())
    }

    /// One row of the list: a route, its later runs, and the other lines on each of its rides.
    struct Row {
        let option: TripPlanner.Option
        /// Later runs: "12:07 · 12:27", or with their line when it differs: "1 at 11:54 · 7 at 11:58".
        let also: [String]
        /// Per ride, the other lines the row's runs take there ("7 / 1").
        let lines: [[LegStrip.Line]]
    }

    /// The list as Google and Apple set it: each different route once (the best first, then by when it
    /// leaves) with its later runs named on its row, so the alternatives share the first screen instead
    /// of one route at ten-minute steps. A route is where it boards and gets off, so lines that do the
    /// same job share a row ("7 / 1", "3 / 4"). The later runs follow under Later, still a tap away.
    /// Another order (Earliest arrival, Least walking…) lists every run as it sorts them.
    private func listRows(_ options: [TripPlanner.Option]) -> (routes: [Row], later: [TripPlanner.Option]) {
        guard planner.sort == .best, let first = options.first else { return (options.map { Row(option: $0, also: [], lines: []) }, []) }
        let departs: (TripPlanner.Option, TripPlanner.Option) -> Bool = { ($0.journey.departDate ?? .distantFuture) < ($1.journey.departDate ?? .distantFuture) }
        var order: [String] = []
        var runs: [String: [TripPlanner.Option]] = [:]
        for option in options {
            let key = Self.route(option.journey)
            if runs[key] == nil { order.append(key) }
            runs[key, default: []].append(option)
        }
        // A route there more than 45 minutes after the best is no alternative to it (as the server ranks
        // them): its runs go under Later, not above them (a night tram at 00:17 among the 23:20s).
        let latest = first.journey.arriveDate.map { $0.addingTimeInterval(45 * 60) }
        let heads = order.compactMap { runs[$0]?.first }
            .filter { head in latest.map { (head.journey.arriveDate ?? .distantFuture) <= $0 } ?? true }
        let routes = [first] + heads.filter { $0.index != first.index }.sorted(by: departs)
        let headIDs = Set(routes.map(\.index))
        let later = options.filter { !headIDs.contains($0.index) }.sorted(by: departs)
        return (routes.map { head in
            let others = (runs[Self.route(head.journey)] ?? []).filter { $0.index != head.index }.sorted(by: departs)
            let firstLines = Set(([head] + others).compactMap { $0.journey.rides.first?.line })
            // A later run names its first line when the row's runs take different ones ("1 at 12:24"),
            // and the line it changes to where that's another ("12:27 then 16").
            let also = others.prefix(3).compactMap { other -> String? in
                guard let ride = other.journey.rides.first else { return other.journey.leaveTime }
                let start = firstLines.count > 1 ? "\(ride.line ?? "") at \(ride.departTime)" : ride.departTime
                let then = zip(other.journey.rides.dropFirst(), head.journey.rides.dropFirst())
                    .filter { $0.line != $1.line }.compactMap(\.0.line)
                return then.isEmpty ? start : start + " then " + then.joined(separator: ", ")
            }
            // The first ride's lines across the row's runs (each named with its time under Also). A later
            // ride shows only the lines that make this run's own connection (its `also`), never another run's.
            let lines = head.journey.rides.indices.map { i in
                i > 0 ? [] : others.compactMap(\.journey.rides.first)
                    .filter { $0.line != head.journey.rides[0].line }
                    .map { LegStrip.Line(line: $0.line ?? "", color: $0.color) }
            }
            return Row(option: head, also: also, lines: lines)
        }, later)
    }

    /// A route as a rider tells routes apart: where each ride boards and gets off, or how it goes when it rides none.
    private static func route(_ journey: TripPlan.Journey) -> String {
        let rides = journey.rides.map { "\(StopName.short($0.from.name ?? ""))>\(StopName.short($0.to.name ?? ""))" }
        return rides.isEmpty ? journey.mode : rides.joined(separator: "|")
    }
}

/// One way in the list, as transit apps set it: leave → there and how long; the lines in order with
/// the walks between; when the first one leaves, from where, and whether it's on time.
private struct RouteRow: View {
    let planner: TripPlanner
    let option: TripPlanner.Option
    let best: Bool
    let selected: Bool
    /// When the same route leaves later ("18:47", "18:57"), named on the row.
    var also: [String] = []
    /// Per ride, the other lines the row's later runs take.
    var lines: [[LegStrip.Line]] = []

    var body: some View {
        let journey = option.journey
        let first = journey.rides.first
        VStack(alignment: .leading, spacing: 7) {
            HStack(alignment: .firstTextBaseline, spacing: 8) {
                Text("\(journey.leaveTime) – \(journey.arriveTime)").font(.geist(18, .bold)).monospacedDigit()
                if best {
                    Text("Best").font(.geist(12, .bold)).foregroundStyle(Palette.navTeal)
                        .padding(.horizontal, 6).frame(height: 20)
                        .background(Palette.selectBackground, in: .rect(cornerRadius: 6))
                }
                Spacer(minLength: 4)
                Text("\(journey.durationMin) min").font(.geist(17, .bold)).monospacedDigit()
            }
            LegStrip(journey: journey, extra: lines)
            HStack(spacing: 6) {
                if let first {
                    // With other lines for the same connection, each says when it comes: "16 at 19:37 or 18 at 19:44".
                    if let also = first.also, !also.isEmpty {
                        let runs = ([(first.line ?? "", first.departTime)] + also.map { ($0.line, $0.departTime) }).sorted { $0.1 < $1.1 }
                        Text(runs.map { "\($0.0) at \($0.1)" }.joined(separator: " or ")
                             + " from \(StopName.short(first.from.name ?? ""))").lineLimit(1).minimumScaleFactor(0.85)
                    } else {
                        Text("\(first.departTime) from \(StopName.short(first.from.name ?? ""))").lineLimit(1)
                    }
                    Text(journey.transfers > 0 ? "· \(journey.transfers) change\(journey.transfers == 1 ? "" : "s")" : "· direct").lineLimit(1)
                } else {
                    Text(journey.mode == "bike" ? "By bike" : "On foot")
                }
                Spacer(minLength: 4)
                status(journey)
            }
            .font(.geist(13.5)).foregroundStyle(Palette.textSoft)
            if !also.isEmpty {
                Label("Also " + also.joined(separator: " · "), systemImage: "arrow.clockwise")
                    .font(.geist(12.5, .medium)).foregroundStyle(Palette.textSoft).monospacedDigit()
            }
            // A change with a minute or less to spare, said where it is; the steps say what comes next if it's missed.
            if let ride = journey.rides.first(where: { $0.change?.worthWarning == true }), let change = ride.change {
                Label("\(change.spareMin == 0 ? "No minute" : "1 min") to change at \(StopName.short(ride.from.name ?? ""))",
                      systemImage: "exclamationmark.triangle")
                    .font(.geist(12.5, .semibold)).foregroundStyle(Palette.warnText)
                    .lineLimit(1).minimumScaleFactor(0.8)
            }
            if let spare = planner.spare(journey) {
                Text(spare >= 0 ? "\(spare) min spare" : "\(-spare) min late").font(.geist(12.5, .bold))
                    .foregroundStyle(spare < 0 ? Palette.conflictText : spare <= 2 ? Palette.warnText : Palette.goodText)
            }
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 16).padding(.vertical, 12)
        .background(selected ? Palette.quiet.opacity(0.6) : .clear)
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
    }

    @ViewBuilder
    private func status(_ journey: TripPlan.Journey) -> some View {
        if journey.cancelled {
            Text("Cancelled").font(.geist(13, .semibold)).foregroundStyle(Palette.conflictText)
        } else if let late = journey.rides.compactMap({ $0.realtime == true ? $0.delayMin : nil }).max(), late >= 2 {
            Text("\(late) min late").font(.geist(13, .semibold)).foregroundStyle(Palette.warnText)
        } else if journey.rides.first?.realtime == true {
            // On time is the tram to catch first, by its board; a live board at the change says nothing of it.
            HStack(spacing: 4) { Circle().fill(Palette.goodText).frame(width: 6, height: 6); Text("On time") }
                .font(.geist(13, .semibold)).foregroundStyle(Palette.goodText)
        } else if !option.lifts.isEmpty {
            Label("Lift out", systemImage: "exclamationmark.triangle").font(.geist(13, .semibold)).foregroundStyle(Palette.warnText)
        }
    }
}

/// The legs in order: a walk as its minutes, a ride as its line's badge, small chevrons between.
struct LegStrip: View {
    /// A line a ride can be, with its colour.
    struct Line: Equatable {
        let line: String
        let color: String?
    }

    let journey: TripPlan.Journey
    /// Per ride, other lines the list's later runs of this route take there.
    var extra: [[Line]] = []

    /// A ride's lines: its own, then the ones that make the same connection, then the later runs'.
    private func lines(_ ride: TripPlan.Leg, at index: Int) -> [Line] {
        var seen = Set<String>()
        let all = [Line(line: ride.line ?? "", color: ride.color)]
            + (ride.also ?? []).map { Line(line: $0.line, color: $0.color) }
            + (extra.indices.contains(index) ? extra[index] : [])
        return all.filter { seen.insert($0.line).inserted }
    }

    var body: some View {
        ViewThatFits(in: .horizontal) {
            strip(walks: true)
            strip(walks: false)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(spoken)
    }

    /// A change within one stop (the 12 to the 16/18 at Ebertplatz): the timetable gives it 0 m and the
    /// stop's minimum change time, six minutes there, where riders take seconds. Nobody knows the stairs,
    /// so no minutes are said for it (the owner, 14 Nov); a walk to another stop keeps its minutes.
    private func inStation(_ leg: TripPlan.Leg) -> Bool {
        leg.mode == "walk" && (leg.distanceM == 0 || (leg.from.name != nil && leg.from.name == leg.to.name))
            && journey.legs.first != leg && journey.legs.last != leg
    }

    private func strip(walks: Bool) -> some View {
        let parts = journey.legs.filter { $0.isRide || (walks && $0.durationMin >= 2 && !inStation($0)) || ($0.mode == "bike") }
        let rides = journey.rides
        return HStack(spacing: 5) {
            ForEach(Array(parts.enumerated()), id: \.offset) { i, leg in
                if i > 0 { Image(systemName: "chevron.compact.right").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft) }
                if leg.isRide {
                    // A ride's lines as one choice: "16 / 18", the mode's symbol once, after them.
                    let index = rides.firstIndex(of: leg) ?? 0
                    let choices = lines(leg, at: index)
                    HStack(spacing: 3) {
                        ForEach(Array(choices.enumerated()), id: \.offset) { n, choice in
                            if n > 0 { Text("/").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft) }
                            LineBadge(line: choice.line, color: choice.color, height: 24, mode: leg.mode, icon: n == choices.count - 1)
                        }
                    }
                } else {
                    HStack(spacing: 2) {
                        Image(systemName: leg.mode == "bike" ? "bicycle" : "figure.walk").font(.system(size: 13, weight: .semibold))
                        Text("\(leg.durationMin)").font(.geist(13, .semibold)).monospacedDigit()
                    }
                    .foregroundStyle(Palette.textSoft)
                    .fixedSize()
                }
            }
        }
    }

    private var spoken: String {
        let rides = journey.rides
        return journey.legs.compactMap { leg in
            guard leg.isRide else { return leg.durationMin >= 2 && !inStation(leg) ? "\(leg.durationMin) minutes \(leg.mode == "bike" ? "by bike" : "walking")" : nil }
            let names = lines(leg, at: rides.firstIndex(of: leg) ?? 0).map(\.line).joined(separator: " or ")
            return "\(leg.mode == "bus" ? "bus" : "tram") \(names)"
        }.joined(separator: ", then ")
    }
}

/// One way opened in the panel: its steps, with Show the way (and Add to plan) pinned under them.
private struct RouteDetail: View {
    let planner: TripPlanner
    let option: TripPlanner.Option
    /// As its row said them: the later runs, and the first ride's other lines.
    let also: [String]
    let lines: [[LegStrip.Line]]
    let kept: Bool
    let best: Bool
    let back: () -> Void
    let show: () -> Void
    let add: (() -> Void)?
    /// Each press of Start trip, for its haptic: felt the moment the finger lifts, before the network answers.
    @State private var pressed = 0

    var body: some View {
        let journey = option.journey
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                Button(action: back) {
                    HStack(spacing: 4) {
                        Image(systemName: "chevron.left").font(.system(size: 14, weight: .semibold))
                        Text("All routes").font(.geist(15, .semibold))
                    }
                    .frame(minHeight: 44).contentShape(.rect)
                }
                .buttonStyle(PressStyle())
                .foregroundStyle(Palette.navTeal)
                Spacer()
                Text("\(journey.durationMin) min").font(.geist(15, .bold))
            }
            .padding(.horizontal, 16)
            VStack(alignment: .leading, spacing: 8) {
                Text("\(journey.leaveTime) – \(journey.arriveTime)").font(.geist(24, .bold)).monospacedDigit()
                LegStrip(journey: journey, extra: lines)
                if !also.isEmpty {
                    Label("Also " + also.joined(separator: " · "), systemImage: "arrow.clockwise")
                        .font(.geist(12.5, .medium)).foregroundStyle(Palette.textSoft).monospacedDigit()
                }
            }
            .padding(.horizontal, 16).padding(.bottom, 10)
            Divider()
            ScrollView {
                // The same step list as the trip screen, Today and Composer (Show stops, live lateness).
                StepList(steps: TodayPage.Step.of(journey.tripLegs, to: planner.destination?.name ?? "", start: nil), legs: journey.tripLegs)
                    .padding(.horizontal, 16).padding(.top, 12).padding(.bottom, 12)
            }
            .scrollIndicators(.hidden)
            HStack(spacing: 8) {
                Button {
                    pressed += 1
                    show()
                } label: {
                    Label(planner.busy == option.index && !kept ? "Starting…" : "Start trip", systemImage: "arrow.right")
                        .font(.geist(16, .semibold)).frame(maxWidth: .infinity).frame(height: 50)
                }
                .buttonStyle(PressStyle())
                .buttonFace(Palette.action, radius: 14)
                .foregroundStyle(Palette.onAction)
                .disabled(journey.cancelled || planner.busy != nil)
                .sensoryFeedback(.impact(weight: .medium), trigger: pressed)
                // Couldn't start: felt as well as read.
                .sensoryFeedback(trigger: planner.startingProblem) { _, now in now == nil ? nil : .error }
                if let add {
                    Button(action: add) {
                        Text(kept ? "✓ In my day" : planner.busy == option.index ? "Adding…" : "Add to my day")
                            .font(.geist(15, .semibold)).padding(.horizontal, 14).frame(height: 50)
                    }
                    .buttonStyle(PressStyle())
                    .background(Palette.quiet, in: .rect(cornerRadius: 14))
                }
            }
            // Clear of the home indicator (34 pt): a tap reaching into it went to the system's swipe
            // first, and Start trip took two (the owner, 13 Nov). Taps on this edge come here first.
            .padding(.horizontal, 16).padding(.top, 10).padding(.bottom, 44)
            .background(Palette.surface)
            .defersSystemGestures(on: .bottom)
        }
        .foregroundStyle(Palette.text)
    }
}

/// What was checked for these routes: the live board, live times, notices, the weather, the day, lifts, the ticket.
private struct ConditionsCard: View {
    @Bindable var planner: TripPlanner
    let answer: TripPlan
    let current: TripPlanner.Option?
    let close: () -> Void
    @Environment(Session.self) private var session

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Text("We checked today’s conditions").font(.geist(16, .bold))
                Spacer()
                Button("Hide", action: close).font(.geist(14, .semibold)).foregroundStyle(Palette.textSoft)
            }
            .padding(.bottom, 6)
            row("clock", "Live times", liveText)
            row("exclamationmark.triangle", "Notices", answer.disruptions.isEmpty ? "No KVB notices on these routes." : answer.disruptions.map(\.headline).joined(separator: " · "))
            row("cloud.sun", "Weather", weatherText)
            if let target = planner.target, let journey = current?.journey, let spare = planner.spare(journey) {
                row("calendar", "Your day", "\(target.title) at \(target.startTime ?? ""). \(spare) min spare on this way.")
            }
            let lifts = answer.lifts.flatMap { $0 }
            row("arrow.up.arrow.down.square", "Lifts on the way", lifts.isEmpty ? "No lift reported out of order." : Set(lifts.map { $0.stop ?? $0.label }).sorted().map { "A lift at \($0) is out of order." }.joined(separator: " "))
            VStack(alignment: .leading, spacing: 7) {
                Text("Ticket").font(.geist(14.5, .bold))
                if let ticket = answer.ticket {
                    Text(planner.deutschlandticket ? "Your Deutschlandticket covers this." : [ticket.label, ticket.priceEur.map { String(format: "€%.2f", $0) }].compactMap { $0 }.joined(separator: " · "))
                        .font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                }
                Picker("Ticket", selection: Binding(get: { planner.deutschlandticket }, set: { held in Task { await planner.setTicket(held, session.api) } })) {
                    Text("I have a Deutschlandticket").tag(true)
                    Text("I don’t have one").tag(false)
                }
                .pickerStyle(.segmented)
            }
            .padding(.top, 10)
        }
        .foregroundStyle(Palette.text)
        .padding(14)
        .glassEffect(.regular, in: .rect(cornerRadius: 22))
    }

    private var liveText: String {
        guard let journey = current?.journey else { return "—" }
        let live = journey.rides.filter { $0.realtime == true }
        if live.isEmpty { return "Timetable times on this route: no live reading." }
        let late = live.compactMap(\.delayMin).max() ?? 0
        let cancelled = journey.rides.contains { $0.cancelled == true }
        return cancelled ? "A ride on this route is cancelled." : late >= 2 ? "Running \(late) min late now." : "Running on time."
    }

    private var weatherText: String {
        guard let hour = answer.weather.first else { return "No forecast for this trip." }
        var parts: [String] = []
        if let condition = hour.condition { parts.append(condition.capitalized) }
        if let t = hour.temperature { parts.append("\(Int(t.rounded()))°") }
        if let p = hour.precipProbability { parts.append("\(p)% rain") }
        return parts.joined(separator: " · ")
    }

    private func row(_ icon: String, _ title: String, _ text: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon).font(.system(size: 16)).frame(width: 22)
            VStack(alignment: .leading, spacing: 1) {
                Text(title).font(.geist(14.5, .bold))
                Text(text).font(.geist(12.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 7)
        .overlay(alignment: .top) { Rectangle().fill(Palette.border).frame(height: 0.5) }
    }
}

/// While the planner works: what's being checked, on flaps.
private struct LoadingCard: View {
    let step: Int

    var body: some View {
        VStack(spacing: 12) {
            FlapWord(text: ["KVB", "LIVE", "RAIN"][min(step, 2)].padding(toLength: 5, withPad: " ", startingAt: 0), small: true)
            Text(step == 0 ? "Checking today’s conditions…" : "Checking the live runs…").font(.geist(17, .bold))
        }
        .foregroundStyle(Palette.text)
        .frame(maxWidth: .infinity)
        .padding(22)
        .background(Palette.surface, in: .rect(cornerRadius: 22))
        .shadow(color: .black.opacity(0.2), radius: 20, y: 12)
    }
}

/// No way, or no start: why, and what to do.
private struct StateCard: View {
    let title: String
    let text: String
    let button: String
    let go: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(title).font(.geist(18, .bold))
            Text(text).font(.geist(15)).foregroundStyle(Palette.textSoft)
            Button(action: go) { Text(button).font(.geist(16, .semibold)).frame(maxWidth: .infinity).frame(height: 48) }
                .buttonStyle(PressStyle())
                .buttonFace(Palette.action, radius: 14)
                .foregroundStyle(Palette.onAction)
        }
        .foregroundStyle(Palette.text)
        .padding(18)
        .background(Palette.surface, in: .rect(cornerRadius: 22))
        .shadow(color: .black.opacity(0.2), radius: 20, y: 12)
    }
}

/// Added to your plan: it's on your day; remind me when to leave; it's watched.
private struct AddedSheet: View {
    let planner: TripPlanner
    let planned: PlannedTrip
    let close: (PlannedTrip?) -> Void
    @Environment(Session.self) private var session

    var body: some View {
        let current = planner.savedSheet ?? planned
        VStack(alignment: .leading, spacing: 14) {
            HStack {
                Text("Added to your plan").font(.posterGrotesque(24))
                Spacer()
                CircleButton(symbol: "xmark", label: "Close") { close(current) }
            }
            Text("It’s on your day, on Today.").font(.geist(14.5)).foregroundStyle(Palette.textSoft)
            HStack(spacing: 12) {
                Image(systemName: "bell").font(.system(size: 18)).frame(width: 24)
                VStack(alignment: .leading, spacing: 1) {
                    Text("Remind me when to leave").font(.geist(15.5, .semibold))
                    Text(current.remind ? (current.remindAt.map { "At \($0), five minutes before you need to go." } ?? "Five minutes before you need to go.") : "Off. It stays on your day.")
                        .font(.geist(13)).foregroundStyle(Palette.textSoft)
                }
                Spacer(minLength: 0)
                Toggle("Remind me when to leave", isOn: Binding(get: { current.remind }, set: { on in Task { await planner.remind(on, session.api) } }))
                    .labelsHidden().tint(Palette.navTeal)
            }
            HStack(spacing: 12) {
                Image(systemName: "eye").font(.system(size: 18)).frame(width: 24)
                VStack(alignment: .leading, spacing: 1) {
                    Text("We keep watching it").font(.geist(15.5, .semibold))
                    Text("If the tram is late or cancelled, you’ll hear in time to change.").font(.geist(13)).foregroundStyle(Palette.textSoft)
                }
            }
            Button { close(nil) } label: { Text("Done").font(.geist(16, .semibold)).frame(maxWidth: .infinity).frame(height: 48) }
                .buttonStyle(PressStyle())
                .background(Palette.quiet, in: .rect(cornerRadius: 14))
        }
        .foregroundStyle(Palette.text)
        .padding(16).padding(.top, 6)
    }
}

/// A short note at the bottom with one action (Undo).
struct UndoToast: View {
    let text: String
    let action: String
    let go: () -> Void

    var body: some View {
        HStack(spacing: 14) {
            Text(text).font(.geist(15, .medium))
            Button(action, action: go).font(.geist(15, .bold)).foregroundStyle(Palette.brandCyan)
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 16).frame(height: 48)
        .background(Color(hex: 0x2A2620), in: .rect(cornerRadius: 14))
        .shadow(color: .black.opacity(0.25), radius: 14, y: 8)
    }
}

extension PlannedTrip: @retroactive Identifiable {}

extension TripPlan.Journey {
    /// A planned route as a trip's legs: one shape for the step list, the map and the trip card, wherever
    /// the route came from.
    var tripLegs: [TodayPage.Trip.Leg] {
        legs.compactMap { leg in
            guard let depart = TodayPage.parse(leg.departAt), let arrive = TodayPage.parse(leg.arriveAt) else { return nil }
            return TodayPage.Trip.Leg(
                mode: leg.mode, line: leg.line, color: leg.color, headsign: leg.headsign.map(StopName.short),
                from: StopName.short(leg.from.name ?? ""), to: StopName.short(leg.to.name ?? ""),
                departAt: depart, arriveAt: arrive, departTime: leg.departTime, arriveTime: leg.arriveTime,
                minutes: leg.durationMin, stops: leg.stops, polyline: leg.polyline,
                stopsOnTheWay: (leg.intermediateStops ?? []).map {
                    TodayPage.Trip.Leg.Stop(name: StopName.short($0.name), time: $0.arriveTime ?? "", latitude: $0.lat, longitude: $0.lng)
                },
                delayMin: leg.delayMin, cancelled: leg.cancelled ?? false,
                also: (leg.also ?? []).map { "\($0.line) at \($0.departTime)" },
                change: leg.change.map {
                    TodayPage.Trip.Leg.Change(spareMin: $0.spareMin, tight: $0.tight,
                                              next: $0.next.map { "\($0.line) at \($0.departTime)" }, thereTime: $0.thereTime)
                })
        }
    }
}

/// Stop names as the signs show them: "Köln Neumarkt" is "Neumarkt".
enum StopName {
    static func short(_ name: String) -> String {
        for prefix in ["Köln ", "Koeln "] where name.hasPrefix(prefix) { return String(name.dropFirst(prefix.count)) }
        return name
    }
}
