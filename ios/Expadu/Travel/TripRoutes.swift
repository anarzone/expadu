import API
import Core
import CoreLocation
import DesignSystem
import Maps
import SwiftUI

/// The routes (the canvas's results): the way drawn on the map, and each way printed as a ticket in
/// its lines' colours: when to leave and when you're there, why it's good, its steps, and Show the
/// way or Add to plan. Sorted as you pick; today's conditions say what was checked.
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
    @State private var printed = false
    /// A plain list (the default) or the tickets, as they last chose.
    @AppStorage("routesList") private var asList = true
    /// The list's panel pulled up to fill most of the screen.
    @State private var panelLarge = false
    @State private var panelDrag: CGFloat = 0
    @State private var screenHeight: CGFloat = 874
    @State private var undo: PlannedTrip?

    var body: some View {
        let options = planner.options
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
                } else if !options.isEmpty, !asList {
                    deck(options)
                }
            }
            if asList, !planner.loading, planner.problem == nil, !options.isEmpty {
                panel(options)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .onGeometryChange(for: CGFloat.self) { $0.size.height } action: { screenHeight = $0 }
        .animation(.smooth(duration: 0.35), value: asList)
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
            Button("OK", role: .cancel) { planner.startingProblem = nil }
        } message: { Text(planner.startingProblem ?? "") }
        .alert("Replace the trip you’re on?", isPresented: Binding(get: { planner.ask != nil }, set: { if !$0 { planner.ask = nil } })) {
            Button("Keep", role: .cancel) { planner.ask = nil }
            Button("Replace") {
                if let option = planner.ask { Task { _ = await planner.start(option, replace: true, session.api, today: today) } }
                planner.ask = nil
            }
        } message: { Text("You can only follow one trip at a time.") }
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
        // A new answer or order prints the deck again (and shows it: it used to stay hidden).
        .onChange(of: planner.answer?.journeys.map(\.departAt)) { reprint() }
        .onChange(of: planner.sort) { reprint() }
        .onChange(of: planner.prefs) { reprint() }
    }

    private func reprint() {
        picked = nil
        details = nil
        printed = false
        Task { @MainActor in
            try? await Task.sleep(for: .milliseconds(60))
            printed = true
        }
    }

    // MARK: Map

    /// How much of the map's bottom the results cover, so the way is fitted above them.
    private func mapBottom(_ options: [TripPlanner.Option]) -> CGFloat {
        guard !options.isEmpty else { return 120 }
        return asList ? panelHeight + 24 : 420
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
                       fitID: (option?.index ?? -1) * 10 + (panelLarge ? 1 : 0) + (asList ? 2 : 0))
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
                Button { withAnimation(.smooth(duration: 0.35)) { asList.toggle(); details = nil } } label: {
                    tool(icon: asList ? "rectangle.stack" : "list.bullet", text: asList ? "Tickets" : "List", chevron: false)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel(asList ? "Show as tickets" : "Show as a list")
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

    // MARK: Deck

    private func deck(_ options: [TripPlanner.Option]) -> some View {
        VStack(spacing: 8) {
            ScrollView(.horizontal) {
                LazyHStack(spacing: 12) {
                    ForEach(Array(options.enumerated()), id: \.element.id) { i, option in
                        TicketView(planner: planner, option: option, stamp: i == 0 ? planner.sort.label : nil, open: details == option.index,
                                   kept: planner.added[option.index] != nil, toggle: {
                                       withAnimation(.smooth(duration: 0.35)) { details = details == option.index ? nil : option.index }
                                   }, show: {
                                       Task { _ = await planner.start(option, replace: false, session.api, today: today) }
                                   }, add: planner.target == nil ? nil : {
                                       Task { await planner.addToPlan(option, session.api) }
                                   })
                        .containerRelativeFrame(.horizontal) { width, _ in width - 36 }
                        .id(option.index)
                        // Printed one after another as the deck comes in.
                        .offset(y: printed || reduceMotion ? 0 : 40)
                        .opacity(printed || reduceMotion ? 1 : 0)
                        .animation(.spring(duration: 0.5, bounce: 0.25).delay(Double(min(i, 3)) * 0.12), value: printed)
                    }
                    Button { Task { await planner.showMore(session.api) } } label: {
                        VStack(alignment: .leading) {
                            Image(systemName: planner.more ? "arrow.uturn.backward" : "plus").font(.system(size: 20, weight: .semibold))
                            Spacer()
                            Text(planner.more ? "Fewer routes" : "More routes").font(.geist(16, .bold))
                        }
                        .padding(16).frame(width: 150, height: 150, alignment: .leading)
                        .background(Palette.surface, in: .rect(cornerRadius: 22))
                        .foregroundStyle(Palette.text)
                    }
                    .buttonStyle(PressStyle())
                }
                .scrollTargetLayout()
            }
            .contentMargins(.horizontal, 18, for: .scrollContent)
            .scrollTargetBehavior(.viewAligned)
            .scrollPosition(id: $picked, anchor: .center)
            .scrollIndicators(.hidden)
            .frame(maxHeight: 520)
            HStack(spacing: 6) {
                ForEach(options) { option in
                    Circle().fill(option.index == (picked ?? options.first?.index) ? Palette.text : Palette.text.opacity(0.25)).frame(width: 6, height: 6)
                }
            }
            .padding(.bottom, 14)
            .accessibilityHidden(true)
        }
        .onAppear { printed = true }
        .sensoryFeedback(.selection, trigger: picked)
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
        return VStack(spacing: 0) {
            // The handle: drag or tap to make the panel taller or shorter.
            Capsule().fill(Palette.border).frame(width: 36, height: 5)
                .frame(maxWidth: .infinity).frame(height: 22)
                .contentShape(.rect)
                .onTapGesture { withAnimation(.smooth(duration: 0.35)) { panelLarge.toggle() } }
                .accessibilityLabel(panelLarge ? "Show more map" : "Show more routes")
                .accessibilityAddTraits(.isButton)
            if let chosen {
                RouteDetail(planner: planner, option: chosen, kept: planner.added[chosen.index] != nil, best: chosen.index == options.first?.index,
                            back: { withAnimation(.smooth(duration: 0.3)) { details = nil } },
                            show: { Task { _ = await planner.start(chosen, replace: false, session.api, today: today) } },
                            add: planner.target == nil ? nil : { Task { await planner.addToPlan(chosen, session.api) } })
                    .transition(.asymmetric(insertion: .move(edge: .trailing), removal: .move(edge: .trailing)).combined(with: .opacity))
            } else {
                ScrollView {
                    LazyVStack(spacing: 0) {
                        let rows = listRows(options)
                        ForEach(Array(rows.routes.enumerated()), id: \.element.option.id) { i, row in
                            routeButton(row.option, also: row.also, options: options)
                            if i < rows.routes.count - 1 || !rows.later.isEmpty { Divider().padding(.leading, 16) }
                        }
                        if !rows.later.isEmpty {
                            Text("Later").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(.horizontal, 16).padding(.top, 14).padding(.bottom, 2)
                            ForEach(Array(rows.later.enumerated()), id: \.element.id) { i, option in
                                routeButton(option, also: [], options: options)
                                if i < rows.later.count - 1 { Divider().padding(.leading, 16) }
                            }
                        }
                        Button { Task { await planner.showMore(session.api) } } label: {
                            Label(planner.more ? "Fewer routes" : "Show later routes", systemImage: planner.more ? "arrow.uturn.backward" : "plus")
                                .font(.geist(15, .semibold)).frame(maxWidth: .infinity).frame(height: 52)
                        }
                        .buttonStyle(PressStyle())
                        .foregroundStyle(Palette.navTeal)
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
    private func routeButton(_ option: TripPlanner.Option, also: [String], options: [TripPlanner.Option]) -> some View {
        Button {
            withAnimation(.smooth(duration: 0.3)) { picked = option.index; details = option.index }
        } label: {
            RouteRow(planner: planner, option: option, best: option.index == options.first?.index && planner.sort == .best,
                     selected: picked == option.index, also: also)
        }
        .buttonStyle(PressStyle())
    }

    /// The list as Google and Apple set it: each different route once (the best first, then by when it
    /// leaves) with its later runs named on its row, so the alternatives share the first screen instead
    /// of one route at ten-minute steps. The later runs follow under Later, still a tap away. Another
    /// order (Earliest arrival, Least walking…) lists every run as it sorts them.
    private func listRows(_ options: [TripPlanner.Option]) -> (routes: [(option: TripPlanner.Option, also: [String])], later: [TripPlanner.Option]) {
        guard planner.sort == .best, let first = options.first else { return (options.map { ($0, []) }, []) }
        let departs: (TripPlanner.Option, TripPlanner.Option) -> Bool = { ($0.journey.departDate ?? .distantFuture) < ($1.journey.departDate ?? .distantFuture) }
        var order: [String] = []
        var runs: [String: [TripPlanner.Option]] = [:]
        for option in options {
            let key = Self.route(option.journey)
            if runs[key] == nil { order.append(key) }
            runs[key, default: []].append(option)
        }
        let heads = order.compactMap { runs[$0]?.first }
        let routes = [first] + heads.filter { $0.index != first.index }.sorted(by: departs)
        let headIDs = Set(routes.map(\.index))
        let later = options.filter { !headIDs.contains($0.index) }.sorted(by: departs)
        return (routes.map { head in
            let others = (runs[Self.route(head.journey)] ?? []).filter { $0.index != head.index }.sorted(by: departs)
            return (head, others.prefix(3).map(\.journey.leaveTime))
        }, later)
    }

    /// A route as a rider tells routes apart: the lines in order, or how it goes when it rides none.
    private static func route(_ journey: TripPlan.Journey) -> String {
        let lines = journey.rides.map { $0.line ?? "?" }
        return lines.isEmpty ? journey.mode : lines.joined(separator: ">")
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
            LegStrip(journey: journey)
            HStack(spacing: 6) {
                if let first {
                    // With earlier lines for the same connection, each says when it comes: "16 at 19:37 or 18 at 19:44".
                    if let also = journey.firstRideAlso, !also.isEmpty {
                        Text((also.map { "\($0.line) at \($0.departTime)" } + ["\(first.line ?? "") at \(first.departTime)"]).joined(separator: " or ")
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
        } else if journey.rides.contains(where: { $0.realtime == true }) {
            HStack(spacing: 4) { Circle().fill(Palette.goodText).frame(width: 6, height: 6); Text("On time") }
                .font(.geist(13, .semibold)).foregroundStyle(Palette.goodText)
        } else if !option.lifts.isEmpty {
            Label("Lift out", systemImage: "exclamationmark.triangle").font(.geist(13, .semibold)).foregroundStyle(Palette.warnText)
        }
    }
}

/// The legs in order: a walk as its minutes, a ride as its line's badge, small chevrons between.
struct LegStrip: View {
    let journey: TripPlan.Journey

    var body: some View {
        ViewThatFits(in: .horizontal) {
            strip(walks: true)
            strip(walks: false)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(spoken)
    }

    private func strip(walks: Bool) -> some View {
        let parts = journey.legs.filter { $0.isRide || (walks && $0.durationMin >= 2) || ($0.mode == "bike") }
        let firstRide = parts.firstIndex { $0.isRide }
        return HStack(spacing: 5) {
            ForEach(Array(parts.enumerated()), id: \.offset) { i, leg in
                if i > 0 { Image(systemName: "chevron.compact.right").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.textSoft) }
                // The first ride's other lines that make the same connection, before it: "16 / 18".
                if i == firstRide, let also = journey.firstRideAlso, !also.isEmpty {
                    ForEach(Array(Set(also.map(\.line))).sorted(), id: \.self) { line in
                        let look = LineLook(hex: also.first { $0.line == line }?.color ?? "#00768B")
                        Text(line).font(.geist(13.5, .bold)).monospacedDigit().foregroundStyle(look.ink)
                            .padding(.horizontal, 7).frame(height: 24)
                            .background(look.base, in: .rect(cornerRadius: 6))
                            .fixedSize()
                        Text("/").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                    }
                }
                if leg.isRide {
                    let look = LineLook(hex: leg.color ?? "#00768B")
                    HStack(spacing: 3) {
                        Image(systemName: leg.mode == "bus" ? "bus.fill" : "tram.fill").font(.system(size: 11, weight: .bold))
                        Text(leg.line ?? "").font(.geist(13.5, .bold)).monospacedDigit()
                    }
                    .foregroundStyle(look.ink)
                    .padding(.horizontal, 7).frame(height: 24)
                    .background(look.base, in: .rect(cornerRadius: 6))
                    .fixedSize()
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
        journey.legs.compactMap { leg in
            leg.isRide ? "\(leg.mode == "bus" ? "bus" : "tram") \(leg.line ?? "")" : leg.durationMin >= 2 ? "\(leg.durationMin) minutes \(leg.mode == "bike" ? "by bike" : "walking")" : nil
        }.joined(separator: ", then ")
    }
}

/// One way opened in the panel: its steps, with Show the way (and Add to plan) pinned under them.
private struct RouteDetail: View {
    let planner: TripPlanner
    let option: TripPlanner.Option
    let kept: Bool
    let best: Bool
    let back: () -> Void
    let show: () -> Void
    let add: (() -> Void)?

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
                LegStrip(journey: journey)
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
                Button(action: show) {
                    Label(planner.busy == option.index && !kept ? "Starting…" : "Start trip", systemImage: "arrow.right")
                        .font(.geist(16, .semibold)).frame(maxWidth: .infinity).frame(height: 50)
                }
                .buttonStyle(PressStyle())
                .buttonFace(Palette.action, radius: 14)
                .foregroundStyle(Palette.onAction)
                .disabled(journey.cancelled || planner.busy != nil)
                if let add {
                    Button(action: add) {
                        Text(kept ? "✓ In my day" : planner.busy == option.index ? "Adding…" : "Add to my day")
                            .font(.geist(15, .semibold)).padding(.horizontal, 14).frame(height: 50)
                    }
                    .buttonStyle(PressStyle())
                    .background(Palette.quiet, in: .rect(cornerRadius: 14))
                }
            }
            .padding(.horizontal, 16).padding(.top, 10).padding(.bottom, 30)
            .background(Palette.surface)
        }
        .foregroundStyle(Palette.text)
    }
}

/// A ride as a line: its badge, where it's heading, and the stop you board at.
struct RideLine: View {
    let leg: TripPlan.Leg

    var body: some View {
        let look = LineLook(hex: leg.color ?? "#00768B")
        HStack(spacing: 8) {
            Text(leg.line ?? "").font(.geist(13, .bold)).monospacedDigit()
                .foregroundStyle(look.ink)
                .padding(.horizontal, 6).frame(minWidth: 30).frame(height: 22)
                .background(look.base, in: .rect(cornerRadius: 6))
            VStack(alignment: .leading, spacing: 0) {
                Text(leg.headsign.map { "towards \(StopName.short($0))" } ?? (leg.mode == "bus" ? "Bus" : "Tram"))
                    .font(.geist(14, .semibold)).lineLimit(1)
                Text("from \(StopName.short(leg.from.name ?? "")) at \(leg.departTime)" + (leg.stops.map { " · \($0) stop\($0 == 1 ? "" : "s")" } ?? ""))
                    .font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
            }
            Spacer(minLength: 0)
            if let delay = leg.delayMin, delay >= 2, leg.realtime == true {
                Text("+\(delay)").font(.geist(12.5, .bold)).foregroundStyle(Palette.warnText)
            }
        }
        .accessibilityElement(children: .combine)
    }
}

/// One way printed as a ticket: the lines' colours across its top, Leave and There, how long; then
/// the spare minutes, why it's good, and its steps under Journey details.
private struct TicketView: View {
    let planner: TripPlanner
    let option: TripPlanner.Option
    let stamp: String?
    let open: Bool
    let kept: Bool
    let toggle: () -> Void
    let show: () -> Void
    let add: (() -> Void)?

    var body: some View {
        let journey = option.journey
        let rides = journey.rides
        let looks = rides.map { LineLook(hex: $0.color ?? "#00768B") }
        let bike = journey.mode == "bike"
        let walk = journey.mode == "walk"
        VStack(spacing: 0) {
            ZStack(alignment: .topLeading) {
                Group {
                    if looks.count > 1 { LineLook.fill(from: looks[0], to: looks[looks.count - 1]) } else { (looks.first ?? LineLook(hex: walk ? "#6A6253" : "#00768B")).fill }
                }
                if rides.count == 1, let line = rides[0].line {
                    Text(line).font(.posterGrotesque(130)).tracking(-6)
                        .foregroundStyle((looks.first?.ink ?? .white).opacity(0.14))
                        .lineLimit(1).fixedSize()
                        .offset(x: 150, y: -14)
                }
                VStack(alignment: .leading, spacing: 10) {
                    HStack(spacing: 6) {
                        if bike {
                            plate(icon: "bicycle", text: "Bike")
                        } else if walk {
                            plate(icon: "figure.walk", text: "Walk")
                        } else {
                            ForEach(Array(rides.enumerated()), id: \.offset) { _, ride in plate(icon: icon(ride.mode), text: ride.line ?? ride.mode) }
                        }
                        Text(lineText(journey)).font(.geist(13, .semibold)).lineLimit(1)
                        Spacer(minLength: 0)
                        if let stamp {
                            Text(stamp.uppercased()).font(.geist(10.5, .heavy)).tracking(0.6)
                                .padding(.horizontal, 7).padding(.vertical, 4)
                                .overlay { RoundedRectangle(cornerRadius: 5).strokeBorder(lineWidth: 2) }
                                .rotationEffect(.degrees(-6))
                        }
                    }
                    HStack(alignment: .bottom, spacing: 10) {
                        VStack(alignment: .leading, spacing: 0) {
                            Text("Leave").font(.geist(12.5, .medium)).opacity(0.85)
                            Text(journey.leaveTime).font(.posterGrotesque(32)).monospacedDigit().fixedSize()
                        }
                        Image(systemName: "arrow.right").font(.system(size: 20, weight: .bold)).padding(.bottom, 8)
                        VStack(alignment: .leading, spacing: 0) {
                            Text("There").font(.geist(12.5, .medium)).opacity(0.85)
                            Text(journey.arriveTime).font(.posterGrotesque(32)).monospacedDigit().fixedSize()
                        }
                        Spacer(minLength: 0)
                        Text("\(journey.durationMin) min").font(.geist(13, .bold)).fixedSize()
                            .padding(.horizontal, 9).frame(height: 26)
                            .background(.white.opacity(0.22), in: .rect(cornerRadius: 8))
                            .padding(.bottom, 6)
                    }
                }
                .padding(16)
            }
            .foregroundStyle(looks.first?.ink ?? .white)
            .frame(height: 150)
            .clipped()
            // The perforation.
            Perforation().stroke(style: StrokeStyle(lineWidth: 1.5, dash: [5, 5])).foregroundStyle(Palette.border).frame(height: 1)
            VStack(alignment: .leading, spacing: 10) {
                // Which tram, which way, from where: what to look for at the stop.
                ForEach(Array(rides.enumerated()), id: \.offset) { _, ride in RideLine(leg: ride) }
                if let spare = planner.spare(journey) {
                    Text(spare >= 0 ? "\(spare) min spare" : "\(-spare) min late")
                        .font(.geist(13, .bold))
                        .padding(.horizontal, 9).frame(height: 26)
                        .background(spare < 0 ? Palette.conflictBackground : spare <= 2 ? Palette.warnBackground : spare >= 10 ? Palette.goodBackground : Palette.quiet, in: .rect(cornerRadius: 8))
                        .foregroundStyle(spare < 0 ? Palette.conflictText : spare <= 2 ? Palette.warnText : spare >= 10 ? Palette.goodText : Palette.text)
                }
                let reasons = why(journey)
                // Short reasons side by side; a long one (a notice) on its own line, wrapped.
                FlowLayout(spacing: 6) {
                    ForEach(reasons.filter { $0.text.count <= 28 }, id: \.text) { item in
                        Label(item.text, systemImage: item.icon).font(.geist(13, .medium)).labelStyle(.titleAndIcon)
                            .foregroundStyle(item.warn ? Palette.warnText : Palette.text)
                    }
                }
                ForEach(reasons.filter { $0.text.count > 28 }, id: \.text) { item in
                    Label(item.text, systemImage: item.icon).font(.geist(13, .medium)).labelStyle(.titleAndIcon)
                        .lineLimit(2).fixedSize(horizontal: false, vertical: true)
                        .foregroundStyle(item.warn ? Palette.warnText : Palette.text)
                }
                if journey.cancelled {
                    Text("A ride on this route is cancelled · Not offered").font(.geist(13, .semibold)).foregroundStyle(Palette.conflictText)
                }
                if open {
                    ScrollView { StepList(steps: TodayPage.Step.of(journey.tripLegs, to: planner.destination?.name ?? "", start: nil), legs: journey.tripLegs) }
                        .frame(maxHeight: 220)
                        .scrollBounceBehavior(.basedOnSize)
                        .transition(.opacity.combined(with: .move(edge: .top)))
                }
                HStack(spacing: 8) {
                    Button(action: show) {
                        Label(planner.busy == option.index && !kept ? "Starting…" : "Start trip", systemImage: "arrow.right").font(.geist(15, .semibold)).frame(maxWidth: .infinity).frame(height: 44)
                    }
                    .buttonStyle(PressStyle())
                    .buttonFace(Palette.action, radius: 12)
                    .foregroundStyle(Palette.onAction)
                    .disabled(journey.cancelled || planner.busy != nil)
                    if let add {
                        Button(action: add) {
                            Text(kept ? "✓ In my day" : planner.busy == option.index ? "Adding…" : "Add to my day").font(.geist(15, .semibold)).padding(.horizontal, 12).frame(height: 44)
                                .contentTransition(.opacity)
                        }
                        .buttonStyle(PressStyle())
                        .background(Palette.quiet, in: .rect(cornerRadius: 12))
                        .sensoryFeedback(.success, trigger: kept)
                    }
                }
                Button(action: toggle) {
                    HStack(spacing: 4) {
                        Text("Journey details")
                        Image(systemName: "chevron.down").font(.system(size: 11, weight: .semibold)).rotationEffect(.degrees(open ? 180 : 0))
                    }
                    .font(.geist(14, .semibold)).foregroundStyle(Palette.navTeal).frame(minHeight: 36)
                }
                .buttonStyle(.plain)
            }
            .padding(16)
            .foregroundStyle(Palette.text)
        }
        .background(Palette.surface)
        .clipShape(.rect(cornerRadius: 22))
        .shadow(color: .black.opacity(0.22), radius: 16, y: 12)
        .accessibilityElement(children: .contain)
    }

    private func plate(icon: String, text: String) -> some View {
        Label(text, systemImage: icon).font(.geist(13, .bold)).labelStyle(.titleAndIcon)
            .padding(.horizontal, 7).frame(height: 24)
            .background(.white, in: .rect(cornerRadius: 6))
            .foregroundStyle(Color(hex: 0x211D15))
    }

    private func icon(_ mode: String) -> String {
        switch mode {
        case "bus": "bus.fill"
        case "rail", "regional", "suburban", "train", "highspeed": "train.side.front.car"
        case "subway": "tram.tunnel.fill"
        default: "tram.fill"
        }
    }

    private func lineText(_ journey: TripPlan.Journey) -> String {
        if journey.mode == "bike" { return "By bike" }
        if journey.mode == "walk" { return "On foot" }
        let first = journey.rides.first.map { "at \($0.departTime)" } ?? ""
        return journey.transfers > 0 ? "\(first) · \(journey.transfers) change\(journey.transfers == 1 ? "" : "s")" : first
    }

    private struct Why: Hashable { let icon: String; let text: String; let warn: Bool }

    private func why(_ journey: TripPlan.Journey) -> [Why] {
        var out: [Why] = []
        if journey.mode == "transit" {
            out.append(journey.transfers == 0 ? Why(icon: "checkmark", text: "No change", warn: false) : Why(icon: "tram", text: "\(journey.transfers) change\(journey.transfers == 1 ? "" : "s")", warn: false))
        }
        let walk = journey.walkMinutes
        if walk > 0, journey.mode != "bike" { out.append(Why(icon: "figure.walk", text: "\(walk) min walking", warn: false)) }
        if journey.mode == "bike" { out.append(Why(icon: "bicycle", text: "\(journey.durationMin) min cycling", warn: false)) }
        if let late = journey.rides.compactMap({ $0.realtime == true ? $0.delayMin : nil }).max(), late >= 3 {
            out.append(Why(icon: "clock.badge.exclamationmark", text: "\(late) min late now", warn: true))
        }
        if let lift = option.lifts.first { out.append(Why(icon: "exclamationmark.triangle", text: "Lift out of order at \(lift.stop ?? lift.label)", warn: true)) }
        // Only what the forecast says for the hour they set off: likely rain, or dry; nothing without one.
        if let answer = planner.answer, let rain = rainChance(answer, at: journey.departDate) {
            if rain >= 50 { out.append(Why(icon: "cloud.rain", text: "Rain likely on the way", warn: true)) }
            else if rain < 20 { out.append(Why(icon: "sun.max", text: "Dry on the way", warn: false)) }
        }
        let lines = Set(journey.rides.compactMap(\.line))
        if let notice = planner.answer?.disruptions.first(where: { !Set($0.lines ?? []).isDisjoint(with: lines) }) {
            out.append(Why(icon: "exclamationmark.triangle", text: notice.headline, warn: true))
        }
        return out
    }

    private func rainChance(_ answer: TripPlan, at date: Date?) -> Int? {
        guard let date else { return nil }
        return answer.weather.min { abs((TravelJSONAccessDates.date($0.at) ?? .distantPast).timeIntervalSince(date)) < abs((TravelJSONAccessDates.date($1.at) ?? .distantPast).timeIntervalSince(date)) }?.precipProbability
    }
}

nonisolated private struct Perforation: Shape {
    func path(in rect: CGRect) -> Path {
        var path = Path()
        path.move(to: CGPoint(x: 0, y: rect.midY))
        path.addLine(to: CGPoint(x: rect.maxX, y: rect.midY))
        return path
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
                delayMin: leg.delayMin, cancelled: leg.cancelled ?? false)
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
