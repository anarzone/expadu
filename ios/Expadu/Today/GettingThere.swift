import Core
import DesignSystem
import Maps
import SwiftUI

/// Getting there, pushed from an event's details as the canvas draws it: the way on a small map,
/// the way as a Travel ticket with its steps, I'm on my way (live in place once started), the
/// other ways to pick from, and what's good to know on the way. Every way is the planner's own.
struct GettingThere: View {
    let listing: WhatsOn.Listing
    let day: WhatsOn
    @Binding var plan: WayPlan?
    @Binding var problem: String?
    let replan: () async -> Void
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var picked = 0
    @State private var flips = 0
    @State private var mapOpen = false
    @State private var pinch: CGFloat = 1
    @Namespace private var mapZoom
    @State private var starting = false
    @State private var replaceAsked = false
    @State private var startProblem: String?

    private var idea: TodayPage.Idea { listing.idea }
    private var way: TodayPage.Way? { plan.flatMap { $0.ways.indices.contains(picked) ? $0.ways[picked] : $0.ways.first } }
    /// The trip they're on, when it's to this event: live here, as on Today.
    private var live: TodayPage.Trip? { today.page?.trip.flatMap { $0.to == idea.title ? $0 : nil } }

    var body: some View {
        VStack(spacing: 0) {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    if let plan, let way {
                        content(plan, way)
                    } else if let problem {
                        trouble(problem)
                    } else {
                        VStack(spacing: 12) {
                            ProgressView()
                            Text("Finding routes…").font(.geist(14)).foregroundStyle(Palette.textSoft)
                        }
                        .frame(maxWidth: .infinity).padding(.top, 80)
                    }
                }
                .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, day.isToday ? 110 : 40)
            }
            .scrollIndicators(.hidden)
        }
        // The one thing to do here is always on screen: it floats, the page scrolls under it.
        .overlay(alignment: .bottom) { if let way, live == nil { actions(way) } }
        .background(Palette.canvas.ignoresSafeArea())
        // Back is the system's own (glass, and the edge swipe works); swiping the sheet down closes it all.
        .toolbarBackgroundVisibility(.hidden, for: .navigationBar)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                VStack(alignment: .leading, spacing: 1) {
                    Text("Getting there").font(.posterGrotesque(20)).tracking(-0.3).foregroundStyle(Palette.text)
                    Text(sub).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                }
                .fixedSize()
            }
            .sharedBackgroundVisibility(.hidden)
        }
        .fullScreenCover(isPresented: $mapOpen) {
            if let way {
                WayMap(to: idea.place ?? idea.title, legs: live?.legs ?? way.legs, ways: live == nil ? plan?.ways ?? [] : [],
                       picked: $picked,
                       start: day.isToday && live == nil ? { mapOpen = false; start(replace: false) } : nil,
                       starting: starting,
                       travel: {
                           mapOpen = false
                           dismiss()
                           shell.show(.travel)
                       },
                       know: plan.map { goodToKnow($0, way) } ?? [],
                       starts: idea.aimFor().map(TodayPage.clock))
                    .navigationTransition(.zoom(sourceID: "way-map", in: mapZoom))
            }
        }
        .confirmationDialog("Replace the trip you’re on?", isPresented: $replaceAsked, titleVisibility: .visible) {
            Button("Replace") { start(replace: true) }
            Button("Keep", role: .cancel) {}
        } message: {
            Text("You’re already on a trip somewhere else.")
        }
    }

    // MARK: Top

    private var sub: String {
        "To \(idea.title)" + (idea.aimFor().map { " · starts \(TodayPage.clock($0))" } ?? (idea.startsAt != nil && idea.timed ? " · on now" : ""))
    }

    // MARK: Content

    @ViewBuilder
    private func content(_ plan: WayPlan, _ way: TodayPage.Way) -> some View {
        if let notice = plan.notices.first(where: { $0.major && !Set($0.lines).isDisjoint(with: way.lines.map(\.line)) }) {
            Label { Text(notice.title).font(.geist(14, .semibold)) } icon: { Image(systemName: "exclamationmark.triangle.fill") }
                .foregroundStyle(Palette.warnText)
                .padding(12).frame(maxWidth: .infinity, alignment: .leading)
                .background(Palette.warnBackground, in: .rect(cornerRadius: 14))
                .padding(.bottom, 12)
        }
        if let live {
            LiveTrip(trip: live)
                .padding(.bottom, 14)
                .transition(.opacity.combined(with: .scale(scale: 0.96)))
        }
        // Tap or pinch out: the map grows into the full map from where it sits; swipe down to put it back.
        WayPreview(legs: live?.legs ?? way.legs, key: live == nil ? "p\(way.id)-" : "live-", to: idea.place ?? idea.title)
            .frame(height: 190)
            .clipShape(.rect(cornerRadius: 18))
            .overlay { RoundedRectangle(cornerRadius: 18).strokeBorder(Palette.border) }
            .contentShape(.rect(cornerRadius: 18))
            .matchedTransitionSource(id: "way-map", in: mapZoom)
            .scaleEffect(pinch)
            .onTapGesture { mapOpen = true }
            .gesture(
                MagnifyGesture()
                    .onChanged { value in pinch = min(max(value.magnification, 1), 1.12) }
                    .onEnded { value in
                        withAnimation(.spring(duration: 0.3)) { pinch = 1 }
                        if value.magnification > 1.08 { mapOpen = true }
                    }
            )
            .sensoryFeedback(.impact(weight: .light), trigger: mapOpen) { _, open in open }
            .accessibilityElement()
            .accessibilityLabel("Map of your route")
            .accessibilityHint("Opens the full map")
            .accessibilityAddTraits(.isButton)
            .accessibilityAction { mapOpen = true }
        if live == nil {
            VStack(spacing: 0) {
                TicketTop(way: way, mine: true)
                Line().stroke(Color(hex: 0x211D15, opacity: 0.28), style: StrokeStyle(lineWidth: 2, dash: [6, 5]))
                    .frame(height: 2).padding(.horizontal, 14)
                    .background(Palette.surface)
                VStack(alignment: .leading, spacing: 10) {
                    if idea.aimFor() != nil {
                        Text(way.spareWords).font(.geist(13, .bold)).foregroundStyle(spareInk(way))
                            .padding(.horizontal, 10).frame(height: 26)
                            .background(spareTint(way), in: .rect(cornerRadius: 7, style: .continuous))
                    }
                    // On foot: our router's own turns.
                    if onFoot(way), let turns = way.legs.first?.turns, !turns.isEmpty {
                        TurnList(turns: turns, to: idea.place ?? idea.title)
                    } else {
                        StepList(steps: TodayPage.Step.of(way.legs, to: idea.place ?? idea.title, start: idea.aimFor().map(TodayPage.clock)))
                    }
                }
                .padding(.top, 14).padding(.horizontal, 18).padding(.bottom, 14)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Palette.surface)
                .clipShape(TicketHalf(top: false))
            }
            .shadow(color: Color(hex: 0x19140C, opacity: 0.22), radius: 9, y: 10)
            .padding(.top, 14)
            .id(flips)
            .modifier(TicketFlip(trigger: flips))
        }
        heading("Other routes")
        VStack(spacing: 0) {
            ForEach(Array(plan.ways.enumerated()), id: \.offset) { index, other in
                if index > 0 { Rectangle().fill(Palette.border).frame(height: 1).padding(.leading, 59) }
                Button {
                    guard index != picked else { return }
                    withAnimation(Motion.ios(0.45)) { picked = index; flips += 1 }
                } label: {
                    WayRow(way: other, on: index == picked)
                }
                .buttonStyle(.plain)
                .accessibilityAddTraits(index == picked ? [.isSelected] : [])
            }
        }
        .clipShape(.rect(cornerRadius: 22))
        .card(radius: 22)
        .sensoryFeedback(.selection, trigger: picked)
        let know = goodToKnow(plan, way)
        if !know.isEmpty {
            heading("Good to know")
            VStack(spacing: 0) {
                ForEach(Array(know.enumerated()), id: \.offset) { index, row in
                    if index > 0 { Rectangle().fill(Palette.border).frame(height: 1).padding(.leading, 59) }
                    KnowRow(row: row)
                }
            }
            .clipShape(.rect(cornerRadius: 22))
            .card(radius: 22)
        }
        Button {
            dismiss()
            shell.show(.travel)
        } label: {
            HStack(spacing: 5) {
                Text("Plan a different trip in Travel")
                Image(systemName: "chevron.right").font(.system(size: 11, weight: .bold))
            }
            .font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink)
            .frame(maxWidth: .infinity).frame(minHeight: 48)
        }
        .buttonStyle(PressStyle())
        .padding(.top, 14)
        Text(day.isToday ? "Times from KVB, live where the feed has them." : "A guide for \(TodayPage.shortDay(day.date)). Live times show on the day.")
            .font(.geist(12)).foregroundStyle(Palette.textSoft).frame(maxWidth: .infinity)
    }

    /// I'm on my way, floating as the event page's action does: when to leave and the spare time on
    /// the left, the button filling the rest. On the day itself; another day only plans.
    @ViewBuilder
    private func actions(_ way: TodayPage.Way) -> some View {
        if day.isToday {
            VStack(alignment: .leading, spacing: 10) {
                if let startProblem {
                    Label(startProblem, systemImage: "exclamationmark.circle.fill")
                        .font(.geist(13.5, .semibold)).foregroundStyle(Palette.conflictText)
                        .fixedSize(horizontal: false, vertical: true)
                        .padding(.horizontal, 14).padding(.vertical, 10)
                        .background(Palette.surface, in: .rect(cornerRadius: 16))
                        .transition(.opacity.combined(with: .offset(y: 8)))
                }
                HStack(spacing: 10) {
                    VStack(alignment: .leading, spacing: 1) {
                        Text("Leave \(way.leaveTime)").font(.geist(16, .bold)).foregroundStyle(Palette.text).monospacedDigit()
                            .contentTransition(.numericText())
                        Text(idea.aimFor() == nil ? "There \(way.arriveTime)" : way.spareWords).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                    }
                    .minimumScaleFactor(0.8)
                    .padding(.leading, 14)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Button { start(replace: false) } label: {
                        HStack(spacing: 8) {
                            if starting { ProgressView().tint(.white) } else { Image(systemName: "figure.walk") }
                            Text("Start trip").lineLimit(1)
                        }
                        .font(.geist(17, .semibold)).foregroundStyle(.white)
                        .fixedSize()
                        .padding(.horizontal, 20).frame(height: 50)
                        .buttonFace(Palette.action)
                    }
                    .buttonStyle(PressStyle())
                    .disabled(starting)
                }
                .padding(6)
                .glassEffect(.regular, in: .rect(cornerRadius: 28, style: .continuous))
                .shadow(color: .black.opacity(0.12), radius: 14, y: 6)
                .animation(.easeInOut(duration: 0.3), value: way.leaveTime)
            }
            .padding(.horizontal, 16).padding(.bottom, 6)
        }
    }

    private func heading(_ title: String) -> some View {
        Text(title).font(.posterGrotesque(20)).foregroundStyle(Palette.text)
            .padding(.top, 26).padding(.bottom, 10).padding(.horizontal, 4)
            .accessibilityAddTraits(.isHeader)
    }

    private func trouble(_ text: String) -> some View {
        VStack(spacing: 12) {
            Image(systemName: "location.slash").font(.system(size: 26)).foregroundStyle(Palette.textSoft)
            Text(text).font(.geist(15)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.center)
            Button("Try again") { Task { await replan() } }.font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink)
        }
        .frame(maxWidth: .infinity).padding(.top, 60).padding(.horizontal, 20)
    }

    private func spareInk(_ way: TodayPage.Way) -> Color {
        way.status == "late" ? Palette.conflictText : way.spareMinutes <= 2 ? Palette.warnText : way.spareMinutes >= 10 ? Palette.goodText : Palette.text
    }

    private func spareTint(_ way: TodayPage.Way) -> Color {
        way.status == "late" ? Palette.conflictBackground : way.spareMinutes <= 2 ? Palette.warnBackground : way.spareMinutes >= 10 ? Palette.goodBackground : Palette.quiet
    }

    // MARK: Good to know

    private func goodToKnow(_ plan: WayPlan, _ way: TodayPage.Way) -> [KnowRow.Row] {
        var rows: [KnowRow.Row] = []
        if let rain = plan.rain {
            rows.append(.init(symbol: "cloud.rain.fill", tint: Color(hex: 0x3F86D6), title: "Rain likely around \(rain.at)", note: "\(rain.chance)% chance on the way. Take an umbrella."))
        }
        if way.lines.isEmpty {
            rows.append(.init(symbol: way.mode == "bike" ? "bicycle" : "figure.walk", tint: Color(hex: 0x2F9E5B), title: "No ticket needed",
                              note: way.mode == "bike" ? "By bike all the way." : "On foot all the way."))
        } else if let ticket = plan.ticket {
            if ticket.covered {
                rows.append(.init(symbol: "ticket.fill", tint: Color(hex: 0x2F9E5B), title: "Covered by your Deutschlandticket", note: ticket.how ?? "No other ticket needed."))
            } else {
                let price = ticket.price.map { String(format: "%.2f €", $0).replacingOccurrences(of: ".", with: ",") }
                rows.append(.init(symbol: "ticket.fill", tint: Color(hex: 0x2F9E5B), title: [ticket.label, price].compactMap { $0 }.joined(separator: " · "),
                                  note: ticket.how ?? "A single ticket for this trip."))
            }
        }
        for lift in plan.lifts {
            rows.append(.init(symbol: "arrow.up.arrow.down", tint: Color(hex: 0xE09A06), title: "Out of order: \(lift)", note: "Allow a few extra minutes if you need step-free access."))
        }
        for notice in plan.notices where !notice.major {
            rows.append(.init(symbol: "tram.fill", tint: Color(hex: 0x6A459B), title: notice.title, note: notice.lines.isEmpty ? "On your route" : "Line " + notice.lines.joined(separator: ", ")))
        }
        return rows
    }

    // MARK: On foot

    /// On foot or by bike: the way is our router's turns.
    private func onFoot(_ way: TodayPage.Way) -> Bool { way.lines.isEmpty && (way.mode == "walk" || way.mode == "bike") }

    // MARK: Start

    private func start(replace: Bool) {
        guard let plan else { return }
        starting = true
        Task {
            let (running, why) = await today.startWay(session.api, plan: plan, way: picked, replace: replace)
            starting = false
            if running { replaceAsked = true }
            withAnimation(.spring(duration: 0.45, bounce: 0.3)) { startProblem = why }
            if why != nil { await replan() }
        }
    }
}

/// One of the other ways (.go-alt): its line's tile, what it is, when, how long, and a tick on the one picked.
private struct WayRow: View {
    let way: TodayPage.Way
    let on: Bool

    var body: some View {
        let look = LineLook(hex: way.lines.first?.color ?? (way.mode == "bike" ? "#00768B" : "#6A6253"))
        HStack(spacing: 13) {
            Image(systemName: way.lines.isEmpty ? (way.mode == "bike" ? "bicycle" : "figure.walk") : "tram.fill")
                .font(.system(size: 14, weight: .semibold)).foregroundStyle(look.ink)
                .frame(width: 32, height: 32).background(look.fill, in: .rect(cornerRadius: 9))
            VStack(alignment: .leading, spacing: 1) {
                Text(title).font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                Text("Leave \(way.leaveTime) · there \(way.arriveTime)").font(.geist(13.5)).foregroundStyle(Palette.textSoft)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Text("\(way.minutes) min").font(.geist(14, .semibold)).foregroundStyle(Palette.text)
            Image(systemName: "checkmark").font(.system(size: 14, weight: .bold)).foregroundStyle(Motion.green)
                .opacity(on ? 1 : 0).scaleEffect(on ? 1 : 0.4)
        }
        .padding(.horizontal, 14).padding(.vertical, 10).frame(minHeight: 58)
        .background(on ? Palette.quiet.opacity(0.6) : .clear)
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
    }

    private var title: String {
        if way.lines.isEmpty { return way.mode == "bike" ? "Bike" : "Walk" }
        // "Tram 1", "Tram 1 › Bus 146": the rides as Travel names them.
        let rides = way.legs.filter(\.rides).map(\.name)
        return rides.isEmpty ? way.lines.map { "Line \(Board.badge($0.line))" }.joined(separator: " › ") : rides.joined(separator: " › ")
    }
}

/// A Good to know row: a coloured tile, its line and a note.
struct KnowRow: View {
    struct Row {
        let symbol: String
        let tint: Color
        let title: String
        let note: String
    }

    let row: Row

    var body: some View {
        HStack(alignment: .top, spacing: 13) {
            Image(systemName: row.symbol).font(.system(size: 14, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 32, height: 32).background(row.tint, in: .rect(cornerRadius: 9))
            VStack(alignment: .leading, spacing: 2) {
                Text(row.title).font(.geist(15.5, .semibold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                Text(row.note).font(.geist(13.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, 14).padding(.vertical, 12)
        .accessibilityElement(children: .combine)
    }
}

/// The way on a small map (.go-map): its lines in their colours, walking dotted, from and to. Ours: MapLibre on OpenStreetMap.
private struct WayPreview: View {
    let legs: [TodayPage.Trip.Leg]
    let key: String
    let to: String

    var body: some View {
        CityMap(lines: WayDrawing.lines(legs, prefix: key), pins: WayDrawing.pins(legs, to: to),
                fit: WayDrawing.all(legs), padding: UIEdgeInsets(top: 36, left: 36, bottom: 36, right: 36), interactive: false)
            .allowsHitTesting(false)
    }
}

/// The ticket turning over to the way picked (the canvas's tkFx flip).
private struct TicketFlip: ViewModifier {
    let trigger: Int
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var angle: Double = 0

    func body(content: Content) -> some View {
        content
            .rotation3DEffect(.degrees(angle), axis: (x: 1, y: 0, z: 0), anchor: .top, perspective: 0.6)
            .onAppear {
                guard trigger > 0, !reduceMotion else { return }
                angle = -70
                withAnimation(.spring(duration: 0.6, bounce: 0.35)) { angle = 0 }
            }
    }
}
