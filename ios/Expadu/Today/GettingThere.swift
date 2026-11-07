import Core
import DesignSystem
import MapKit
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
    @State private var starting = false
    @State private var replaceAsked = false
    @State private var startProblem: String?

    private var idea: TodayPage.Idea { listing.idea }
    private var way: TodayPage.Way? { plan.flatMap { $0.ways.indices.contains(picked) ? $0.ways[picked] : $0.ways.first } }
    /// The trip they're on, when it's to this event: live here, as on Today.
    private var live: TodayPage.Trip? { today.page?.trip.flatMap { $0.to == idea.title ? $0 : nil } }

    var body: some View {
        VStack(spacing: 0) {
            top
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    if let plan, let way {
                        content(plan, way)
                    } else if let problem {
                        trouble(problem)
                    } else {
                        VStack(spacing: 12) {
                            ProgressView()
                            Text("Finding the ways…").font(.geist(14)).foregroundStyle(Palette.textSoft)
                        }
                        .frame(maxWidth: .infinity).padding(.top, 80)
                    }
                }
                .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 40)
            }
            .scrollIndicators(.hidden)
        }
        .background(Palette.canvas.ignoresSafeArea())
        .toolbar(.hidden, for: .navigationBar)
        .sheet(isPresented: $mapOpen) { if let way { WayMap(title: "Your way to \(idea.place ?? idea.title)", legs: way.legs) } }
        .confirmationDialog("Replace the trip you’re on?", isPresented: $replaceAsked, titleVisibility: .visible) {
            Button("Replace") { start(replace: true) }
            Button("Keep", role: .cancel) {}
        } message: {
            Text("You’re already on your way somewhere else.")
        }
    }

    // MARK: Top

    private var top: some View {
        HStack(alignment: .center, spacing: 12) {
            Button { dismiss() } label: {
                Image(systemName: "chevron.left").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.text)
                    .frame(width: 44, height: 44)
                    .glassEffect(.regular.interactive(), in: .circle)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Back")
            VStack(alignment: .leading, spacing: 2) {
                Text("Getting there").font(.posterGrotesque(24)).tracking(-0.3).foregroundStyle(Palette.text)
                Text(sub).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 16).padding(.top, 18).padding(.bottom, 10)
        // The page scrolls under it (.go-top), never through it.
        .background(Palette.canvas)
        .zIndex(1)
    }

    private var sub: String {
        "To \(idea.title)" + ((idea.timed ? idea.startsAt : nil).map { " · starts \(TodayPage.clock($0))" } ?? "")
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
        Button { mapOpen = true } label: {
            WayPreview(way: way, to: idea.place ?? idea.title)
                .frame(height: 190)
                .clipShape(.rect(cornerRadius: 18))
                .overlay { RoundedRectangle(cornerRadius: 18).strokeBorder(Palette.border) }
                .overlay(alignment: .bottomTrailing) {
                    Label("Map", systemImage: "map").labelStyle(Tight())
                        .font(.geist(13, .semibold)).foregroundStyle(Palette.text)
                        .padding(.horizontal, 12).frame(height: 32)
                        .glassEffect(.regular, in: .capsule)
                        .padding(10)
                }
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel("Open the map")
        if live == nil {
            VStack(spacing: 0) {
                TicketTop(way: way, mine: true)
                Line().stroke(Color(hex: 0x211D15, opacity: 0.28), style: StrokeStyle(lineWidth: 2, dash: [6, 5]))
                    .frame(height: 2).padding(.horizontal, 14)
                    .background(Palette.surface)
                VStack(alignment: .leading, spacing: 10) {
                    if idea.timed, idea.startsAt != nil {
                        Text(way.spareWords).font(.geist(13, .bold)).foregroundStyle(spareInk(way))
                            .padding(.horizontal, 10).frame(height: 26)
                            .background(spareTint(way), in: .capsule)
                    }
                    StepList(steps: TodayPage.Step.of(way.legs, to: idea.place ?? idea.title, start: idea.timed ? idea.startsAt.map(TodayPage.clock) : nil))
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
            actions
        }
        heading("Other ways")
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

    /// I'm on my way: on the day itself; another day only plans.
    @ViewBuilder
    private var actions: some View {
        if day.isToday {
            Button { start(replace: false) } label: {
                HStack(spacing: 8) {
                    if starting { ProgressView().tint(.white) } else { Image(systemName: "figure.walk") }
                    Text("I’m on my way")
                }
                .font(.geist(17, .semibold)).foregroundStyle(.white)
                .frame(maxWidth: .infinity).frame(height: 50)
                .background(Palette.action, in: .capsule)
            }
            .buttonStyle(PressStyle())
            .disabled(starting)
            .padding(.top, 16)
            if let startProblem {
                Label(startProblem, systemImage: "exclamationmark.circle.fill")
                    .font(.geist(13.5, .semibold)).foregroundStyle(Palette.conflictText)
                    .padding(.top, 8)
                    .transition(.opacity.combined(with: .offset(y: -6)))
            }
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
        way.status == "late" ? Palette.conflictText : way.spareMinutes <= 2 ? Palette.warnText : way.spareMinutes >= 10 ? Color(hex: 0x17603A) : Palette.text
    }

    private func spareTint(_ way: TodayPage.Way) -> Color {
        way.status == "late" ? Palette.conflictBackground : way.spareMinutes <= 2 ? Palette.warnBackground : way.spareMinutes >= 10 ? Color(hex: 0xE4F4E8) : Palette.quiet
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
            rows.append(.init(symbol: "tram.fill", tint: Color(hex: 0x6A459B), title: notice.title, note: notice.lines.isEmpty ? "On your way" : "Line " + notice.lines.joined(separator: ", ")))
        }
        return rows
    }

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
private struct KnowRow: View {
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

/// The way on a small map (.go-map): its lines in their colours, walking dotted, from and to.
private struct WayPreview: View {
    let way: TodayPage.Way
    let to: String

    var body: some View {
        let paths = way.legs.map { leg in (leg, (leg.polyline.map { Polyline.decode($0) } ?? []).map { CLLocationCoordinate2D(latitude: $0.latitude, longitude: $0.longitude) }) }
        let all = paths.flatMap(\.1)
        Map(initialPosition: Self.position(all), interactionModes: []) {
            ForEach(Array(paths.enumerated()), id: \.offset) { _, item in
                let (leg, points) = item
                if points.count > 1 {
                    MapPolyline(coordinates: points)
                        .stroke(leg.rides ? LineLook(hex: leg.color ?? "#1A4CD4").deep : Palette.textSoft,
                                style: StrokeStyle(lineWidth: leg.rides ? 5 : 3.5, lineCap: .round, lineJoin: .round, dash: leg.rides ? [] : [1, 6]))
                }
            }
            if let first = all.first {
                Annotation("You", coordinate: first) {
                    Circle().fill(Color(hex: 0x2F6FD0)).frame(width: 12, height: 12).overlay { Circle().strokeBorder(.white, lineWidth: 2.5) }
                }
            }
            if let last = all.last {
                Marker(to, systemImage: "flag.fill", coordinate: last).tint(Palette.action)
            }
        }
        .allowsHitTesting(false)
    }

    private static func position(_ points: [CLLocationCoordinate2D]) -> MapCameraPosition {
        guard let first = points.first else { return .automatic }
        var minLat = first.latitude, maxLat = first.latitude, minLng = first.longitude, maxLng = first.longitude
        for point in points {
            minLat = min(minLat, point.latitude); maxLat = max(maxLat, point.latitude)
            minLng = min(minLng, point.longitude); maxLng = max(maxLng, point.longitude)
        }
        let center = CLLocationCoordinate2D(latitude: (minLat + maxLat) / 2, longitude: (minLng + maxLng) / 2)
        return .region(MKCoordinateRegion(center: center, span: MKCoordinateSpan(latitudeDelta: max((maxLat - minLat) * 1.5, 0.006), longitudeDelta: max((maxLng - minLng) * 1.5, 0.006))))
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
