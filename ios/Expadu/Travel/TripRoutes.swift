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
    let back: () -> Void
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var picked: Int?
    @State private var details: Int?
    @State private var conditions = false
    @State private var whenOpen = false
    @State private var printed = false
    @State private var undo: PlannedTrip?

    var body: some View {
        let options = planner.options
        let current = options.first { $0.index == picked } ?? options.first
        ZStack(alignment: .top) {
            map(current)
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
                    StateCard(title: problem, text: problem == "Where are you starting?" ? "We need a starting point to find the way." : "Try a stop nearby, or take off a preference.",
                              button: problem == "Where are you starting?" ? "Add a starting point" : "Change the plan", go: back)
                        .padding(.horizontal, 16).padding(.bottom, 30)
                } else if !options.isEmpty {
                    deck(options)
                }
            }
        }
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
        .onChange(of: planner.answer?.journeys.count) { picked = nil; details = nil; printed = false }
        .onChange(of: planner.sort) { picked = nil; details = nil; printed = false }
    }

    // MARK: Map

    private func map(_ option: TripPlanner.Option?) -> some View {
        let legs = option?.journey.legs ?? []
        let lines: [CityMap.Line] = legs.enumerated().compactMap { i, leg in
            guard let poly = leg.polyline else { return nil }
            let points = Polyline.decode(poly)
            guard points.count > 1 else { return nil }
            let walk = leg.mode == "walk"
            return CityMap.Line(id: "r\(option?.index ?? 0)-\(i)", points: points.map { CLLocationCoordinate2D(latitude: $0.latitude, longitude: $0.longitude) },
                                color: walk ? UIColor(Palette.textSoft) : UIColor(LineLook(hex: leg.color ?? "#00768B").base), width: walk ? 4 : 7, dashed: walk)
        }
        var pins: [CityMap.Pin] = []
        if let from = planner.answer?.from { pins.append(.init(id: "from", coordinate: .init(latitude: from.lat, longitude: from.lng), title: planner.from?.name ?? "You", kind: .start)) }
        if let to = planner.destination { pins.append(.init(id: "to", coordinate: .init(latitude: to.lat, longitude: to.lng), title: to.name, kind: .destination)) }
        for (i, leg) in legs.enumerated() where leg.isRide {
            if let lat = leg.from.lat, let lng = leg.from.lng, let line = leg.line {
                let look = LineLook(hex: leg.color ?? "#00768B")
                pins.append(.init(id: "b\(i)", coordinate: .init(latitude: lat, longitude: lng), title: line, kind: .badge(text: line, fill: UIColor(look.base), ink: UIColor(look.ink))))
            }
        }
        let fit = lines.flatMap(\.points) + pins.map(\.coordinate)
        return CityMap(lines: lines, pins: pins, fit: fit, padding: UIEdgeInsets(top: 170, left: 40, bottom: 380, right: 40), fitID: option?.index ?? -1)
            .background(Palette.quiet)
    }

    // MARK: Head

    private var head: some View {
        HStack(spacing: 8) {
            CircleButton(symbol: "chevron.left", label: "Back", tap: back)
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
                if let spare = planner.spare(journey) {
                    Text(spare >= 0 ? "\(spare) min spare" : "\(-spare) min late")
                        .font(.geist(13, .bold))
                        .padding(.horizontal, 9).frame(height: 26)
                        .background(spare < 0 ? Palette.conflictBackground : spare <= 2 ? Palette.warnBackground : spare >= 10 ? Color.green.opacity(0.15) : Palette.quiet, in: .rect(cornerRadius: 8))
                        .foregroundStyle(spare < 0 ? Palette.conflictText : spare <= 2 ? Palette.warnText : spare >= 10 ? Color(hex: 0x1F7A45) : Palette.text)
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
                    Text("A ride on this way is cancelled · Not offered").font(.geist(13, .semibold)).foregroundStyle(Palette.conflictText)
                }
                if open { JourneySteps(journey: journey, to: planner.destination?.name ?? "") .transition(.opacity.combined(with: .move(edge: .top))) }
                HStack(spacing: 8) {
                    Button(action: show) {
                        Label("Show the way", systemImage: "arrow.right").font(.geist(15, .semibold)).frame(maxWidth: .infinity).frame(height: 44)
                    }
                    .buttonStyle(PressStyle())
                    .buttonFace(Palette.action, radius: 12)
                    .foregroundStyle(Palette.onAction)
                    .disabled(journey.cancelled)
                    if let add {
                        Button(action: add) {
                            Text(kept ? "✓ In your plan" : "Add to plan").font(.geist(15, .semibold)).padding(.horizontal, 12).frame(height: 44)
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
            .foregroundStyle(Palette.text)
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

/// A journey's steps: walk to the stop, each ride with its stops, changes, and you're there.
struct JourneySteps: View {
    let journey: TripPlan.Journey
    let to: String

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ForEach(Array(journey.legs.enumerated()), id: \.offset) { i, leg in
                step(colour: leg.isRide ? LineLook(hex: leg.color ?? "#00768B").base : Palette.textSoft,
                     dashed: !leg.isRide, title: title(leg, first: i == 0), sub: sub(leg), at: leg.departTime)
            }
            step(colour: Palette.action, dashed: false, title: "You’re there", sub: to, at: journey.arriveTime, last: true)
            Text("Live times where the feed has them.").font(.geist(12)).foregroundStyle(Palette.textSoft).padding(.top, 6)
        }
    }

    private func title(_ leg: TripPlan.Leg, first: Bool) -> String {
        let place = Self.short(leg.to.name ?? "")
        switch leg.mode {
        case "walk": return place.isEmpty ? "Walk" : "Walk to \(place)"
        case "bike": return place.isEmpty ? "Cycle" : "Cycle to \(place)"
        default:
            let what = leg.mode == "bus" ? "Bus" : "Tram"
            return first ? "\(what) \(leg.line ?? "") to \(place)" : "\(what) \(leg.line ?? "") to \(place)"
        }
    }

    private func sub(_ leg: TripPlan.Leg) -> String {
        if leg.isRide {
            var parts: [String] = []
            if let from = leg.from.name { parts.append("From \(Self.short(from))") }
            if let headsign = leg.headsign { parts.append("towards \(Self.short(headsign))") }
            if let stops = leg.stops { parts.append("\(stops) stop\(stops == 1 ? "" : "s")") }
            if let delay = leg.delayMin, delay >= 2, leg.realtime == true { parts.append("\(delay) min late") }
            if leg.cancelled == true { parts.append("Cancelled") }
            return parts.joined(separator: " · ")
        }
        return "\(leg.durationMin) min" + (leg.distanceM.map { " · \($0) m" } ?? "")
    }

    private func step(colour: Color, dashed: Bool, title: String, sub: String, at: String, last: Bool = false) -> some View {
        HStack(alignment: .top, spacing: 12) {
            ZStack(alignment: .top) {
                if !last {
                    Rectangle().fill(colour).frame(width: 4).mask { if dashed { VStack(spacing: 3) { ForEach(0..<12, id: \.self) { _ in Rectangle().frame(height: 3) } } } else { Rectangle() } }
                        .padding(.top, 10)
                }
                Circle().fill(Palette.surface).overlay { Circle().strokeBorder(colour, lineWidth: 3.5) }.frame(width: 16, height: 16).padding(.top, 2)
            }
            .frame(width: 16)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.geist(14.5, .semibold))
                if !sub.isEmpty { Text(sub).font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
            }
            .padding(.bottom, 12)
            Spacer(minLength: 0)
            Text(at).font(.geist(13.5, .semibold)).monospacedDigit()
        }
    }

    static func short(_ name: String) -> String {
        name.hasPrefix("Köln ") ? String(name.dropFirst(5)) : name
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
            row("exclamationmark.triangle", "Notices", answer.disruptions.isEmpty ? "No KVB notices on these ways." : answer.disruptions.map(\.headline).joined(separator: " · "))
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
        if live.isEmpty { return "Timetable times on this way: no live reading." }
        let late = live.compactMap(\.delayMin).max() ?? 0
        let cancelled = journey.rides.contains { $0.cancelled == true }
        return cancelled ? "A ride on this way is cancelled." : late >= 2 ? "Running \(late) min late now." : "Running on time."
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
                .buttonFace(Palette.text, radius: 14)
                .foregroundStyle(Palette.surface)
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
                    Text(current.remind ? (current.remindDate.map { "At \(TripPlanner.clock($0)), five minutes before you need to go." } ?? "Five minutes before you need to go.") : "Off. It stays on your day.")
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
