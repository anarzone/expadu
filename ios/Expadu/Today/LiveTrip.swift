import API
import Core
import CoreLocation
import Maps
import DesignSystem
import SafariServices
import SwiftUI

struct LiveTrip: View {
    let trip: TodayPage.Trip
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var picked: Int?
    @State private var steps = false
    @State private var ending = false
    @State private var mapOpen = false
    @State private var pulse = false
    /// Our own navigation: the phone's GPS on the trip's lines (Guide).
    @State private var follow = PhoneFollow()
    @State private var replanning = false
    @State private var trouble: String?
    @State private var drag: CGFloat = 0
    @State private var swipedLeft = true
    @State private var back: Task<Void, Never>?
    @AppStorage("speakTurns") private var speak = false

    /// Look a step ahead or back; after a while the card comes back to the step they're on.
    private func look(by delta: Int, from index: Int, current: Int, count: Int) {
        let next = min(max(index + delta, 0), count - 1)
        guard next != index else { return }
        withAnimation(.spring(duration: 0.4, bounce: 0.2)) { picked = next == current ? nil : next }
        back?.cancel()
        back = Task {
            try? await Task.sleep(for: .seconds(6))
            guard !Task.isCancelled else { return }
            withAnimation(.spring(duration: 0.4, bounce: 0.2)) { swipedLeft = false; picked = nil }
        }
    }

    private func end(arrived: Bool) {
        ending = true
        Task { await model.endTrip(session.api, arrived: arrived); ending = false }
    }

    var body: some View {
        TimelineView(.periodic(from: .now, by: 20)) { timeline in
            let here = follow.here
            let live = trip.live(at: timeline.date, picked: picked, guided: here?.step)
            let all = trip.steps
            let last = live.current == all.count - 1
            VStack(alignment: .leading, spacing: 0) {
                if let say = model.cardNote {
                    CardSay(note: say).padding(.bottom, 12)
                }
                HStack(spacing: 8) {
                    HStack(spacing: 5) {
                        Circle().fill(Motion.green).frame(width: 7, height: 7)
                            .background { Circle().fill(Motion.green.opacity(0.25)).scaleEffect(pulse ? 2.4 : 1).opacity(pulse ? 0 : 1) }
                        Text("On your way · live").font(.geist(13.5, .bold))
                    }
                    .foregroundStyle(Motion.green)
                    Spacer(minLength: 0)
                    if let arrive = trip.arriveTime {
                        Text("There \(arrive)").font(.geist(13.5, .semibold)).monospacedDigit().foregroundStyle(Palette.textSoft)
                    }
                }
                .modifier(Rise(order: 0))
                bars(all, current: live.current)
                    .padding(.top, 12).padding(.bottom, 4)
                    .modifier(Rise(order: 1))
                // Swipe sideways: the step before or after; tap: every step. It comes back to now by itself.
                nowCard(live)
                    .id(live.index)
                    .transition(.asymmetric(insertion: .opacity.combined(with: .offset(x: swipedLeft ? 40 : -40)),
                                            removal: .opacity.combined(with: .offset(x: swipedLeft ? -40 : 40))))
                    .offset(x: drag)
                    .contentShape(.rect(cornerRadius: 16))
                    .onTapGesture { withAnimation(Motion.ios(0.35)) { steps.toggle() } }
                    .simultaneousGesture(
                        DragGesture(minimumDistance: 18)
                            .onChanged { value in
                                guard abs(value.translation.width) > abs(value.translation.height) * 1.4 else { return }
                                drag = value.translation.width * 0.35
                            }
                            .onEnded { value in
                                withAnimation(.spring(duration: 0.35, bounce: 0.3)) { drag = 0 }
                                guard abs(value.translation.width) > 50, abs(value.translation.width) > abs(value.translation.height) * 1.4 else { return }
                                swipedLeft = value.translation.width < 0
                                look(by: swipedLeft ? 1 : -1, from: live.index, current: live.current, count: all.count)
                            }
                    )
                    .modifier(Rise(order: 2))
                    .accessibilityAction(named: "Next step") { look(by: 1, from: live.index, current: live.current, count: all.count) }
                    .accessibilityAction(named: "Previous step") { look(by: -1, from: live.index, current: live.current, count: all.count) }
                    .accessibilityAction(named: steps ? "Hide steps" : "All steps") { withAnimation(Motion.ios(0.35)) { steps.toggle() } }
                // On foot: the next turn, by where the phone is on our router's way.
                if picked == nil, let here, let turn = here.turn, !here.offRoute {
                    TurnRow(turn: turn, metres: here.metresToTurn)
                        .padding(.top, 12)
                        .transition(.opacity.combined(with: .offset(y: -6)))
                }
                if let here, let missed = here.missed {
                    TripTrouble(text: "\(missed.name) at \(missed.departTime) has left.", busy: replanning) { replan() }
                        .padding(.top, 12).transition(.opacity)
                } else if let here, here.offRoute, let since = follow.offSince, timeline.date.timeIntervalSince(since) > 15 {
                    TripTrouble(text: "You’re off the way, \(TurnRow.distance(Int(here.offBy))) from it.", busy: replanning) { replan() }
                        .padding(.top, 12).transition(.opacity)
                } else if let trouble {
                    Label(trouble, systemImage: "exclamationmark.circle.fill").font(.geist(13.5, .semibold)).foregroundStyle(Palette.conflictText)
                        .padding(.top, 10)
                }
                if steps {
                    StepList(steps: all, current: live.current)
                        .padding(.top, 14).padding(.horizontal, 2)
                        .transition(.opacity.combined(with: .offset(y: -6)))
                }
                // There: the one thing left to do. On the way, everything else is a long press away.
                if last && picked == nil {
                    action("Done", symbol: "checkmark", grow: true) { end(arrived: true) }
                        .disabled(ending)
                        .padding(.top, 12)
                        .transition(.opacity.combined(with: .scale(scale: 0.95)))
                } else {
                    Text("Swipe for the next step · tap for all · hold for more")
                        .font(.geist(12)).foregroundStyle(Palette.textSoft)
                        .frame(maxWidth: .infinity)
                        .padding(.top, 10)
                        .accessibilityHidden(true)
                }
            }
            .animation(Motion.ios(0.35), value: live.index)
        }
        .padding(14)
        .background(Palette.surface, in: .rect(cornerRadius: 20))
        .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(Motion.green.opacity(0.6), lineWidth: 1.5) }
        .sensoryFeedback(.selection, trigger: picked)
        .contentShape(.contextMenuPreview, .rect(cornerRadius: 20))
        .contextMenu {
            Button { mapOpen = true } label: { Label("Show the map", systemImage: "map") }
            Button { withAnimation(Motion.ios(0.35)) { steps.toggle() } } label: { Label(steps ? "Hide steps" : "All steps", systemImage: "list.bullet") }
            Toggle(isOn: $speak) { Label("Speak turns", systemImage: "speaker.wave.2") }
            Button { replan() } label: { Label("New way from here", systemImage: "arrow.triangle.turn.up.right.diamond") }
                .disabled(follow.point == nil)
            Divider()
            Button(role: .destructive) { end(arrived: false) } label: { Label("End trip", systemImage: "stop.circle") }
        }
        .accessibilityAction(named: "Show the map") { mapOpen = true }
        .accessibilityAction(named: "End trip") { end(arrived: false) }
        .animation(Motion.spring, value: model.cardNote)
        .sheet(isPresented: $mapOpen) { WayMap(title: "Your way to \(trip.to)", legs: trip.legs, guiding: (trip, follow)) }
        .onAppear {
            follow.trip = trip
            follow.start()
            guard !reduceMotion else { return }
            withAnimation(.easeInOut(duration: 1.6).repeatForever(autoreverses: false)) { pulse = true }
        }
        .onDisappear { follow.stop() }
        .onChange(of: trip) { follow.trip = trip }
        .animation(Motion.ios(0.35), value: follow.point)
    }

    /// A new way from where the phone is now, to the same place: it replaces the trip.
    private func replan() {
        guard let from = follow.point else { return }
        replanning = true
        Task {
            trouble = await model.newWay(session.api, trip: trip, from: from)
            replanning = false
            follow.restart()
        }
    }

    /// The steps as bars: done ones full, the one they're on running, the rest empty; a tap shows that step.
    private func bars(_ all: [TodayPage.Step], current: Int) -> some View {
        HStack(spacing: 4) {
            ForEach(Array(all.enumerated()), id: \.offset) { index, step in
                Button { withAnimation(Motion.ios(0.35)) { picked = picked == index || index == current ? nil : index } } label: {
                    BarFill(colour: StepColour.of(step), state: index < current ? .done : index == current ? .on : .ahead)
                        .frame(height: 24)
                        .contentShape(.rect)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(step.title)
            }
        }
    }

    private func nowCard(_ live: TodayPage.Live) -> some View {
        let colour = StepColour.of(live.step)
        return HStack(alignment: .top, spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(live.label.uppercased()).font(.geist(11.5, .bold)).tracking(0.3).foregroundStyle(Palette.textSoft)
                Text(live.step.title).font(.geist(17, .bold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                if !live.step.sub.isEmpty {
                    Text(live.step.sub).font(.geist(13)).foregroundStyle(Palette.textSoft)
                }
            }
            Spacer(minLength: 0)
            VStack(alignment: .trailing, spacing: 4) {
                Text(live.big).font(.posterGrotesque(28)).monospacedDigit().foregroundStyle(colour)
                    .contentTransition(.numericText(countsDown: true))
                Text(live.bigNote).font(.geist(12, .semibold)).foregroundStyle(Palette.textSoft)
            }
        }
        .padding(.vertical, 12).padding(.leading, 14).padding(.trailing, 12)
        .background(colour.opacity(0.09), in: .rect(cornerRadius: 16))
        .background(Palette.surface, in: .rect(cornerRadius: 16))
        .overlay(alignment: .leading) {
            UnevenRoundedRectangle(topLeadingRadius: 16, bottomLeadingRadius: 16).fill(colour).frame(width: 3)
        }
    }

    private func action(_ title: String, symbol: String, grow: Bool = false, run: @escaping () -> Void) -> some View {
        Button(action: run) {
            Label(title, systemImage: symbol).labelStyle(Tight())
                .font(.geist(15, .medium)).foregroundStyle(Palette.text)
                .padding(.horizontal, 14).frame(maxWidth: grow ? .infinity : nil).frame(height: 44)
                .background(Palette.quiet, in: .capsule)
                .contentShape(.capsule)
        }
        .buttonStyle(PressStyle())
    }
}

/// A step's colour: its line's, the ink for walking, the red-orange of arriving.
enum StepColour {
    /// LINE_LOOK.walk.
    static let walk = Color(hex: 0x6A6253)

    static func of(_ step: TodayPage.Step) -> Color {
        step.color.map { LineLook(hex: $0).deep } ?? walk
    }
}

/// One progress bar (.lv-bar): a quiet track, full once done, running while it's the step they're on.
struct BarFill: View {
    enum State { case done, on, ahead }
    let colour: Color
    let state: State
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @SwiftUI.State private var run = false

    var body: some View {
        GeometryReader { proxy in
            let fill: Double = switch state {
            case .done: 1
            case .on: run ? 0.62 : 0.4
            case .ahead: 0
            }
            ZStack(alignment: .leading) {
                Capsule().fill(Palette.quiet)
                Capsule().fill(colour).frame(width: proxy.size.width * fill)
            }
            .frame(height: 5)
            .clipShape(.capsule)
            .frame(maxHeight: .infinity)
        }
        .animation(Motion.ios(0.5), value: state == .done)
        .onAppear {
            guard state == .on, !reduceMotion else { return }
            withAnimation(.easeInOut(duration: 2.4).repeatForever(autoreverses: true)) { run = true }
        }
    }
}

/// Every step of a way (.way .w-s): a ring in its colour, the line to the next (dotted for walking),
/// the step and when; past ones faded, the one they're on in its colour.
struct StepList: View {
    let steps: [TodayPage.Step]
    var current: Int?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ForEach(Array(steps.enumerated()), id: \.offset) { index, step in
                let colour = StepColour.of(step)
                let isLast = index == steps.count - 1
                HStack(alignment: .top, spacing: 12) {
                    Circle().strokeBorder(step.kind == .walk ? Palette.textSoft : colour, lineWidth: 3)
                        .background(Circle().fill(Palette.surface))
                        .frame(width: 17, height: 17)
                        .padding(.top, 1)
                        .background(alignment: .top) {
                            if !isLast {
                                connector(step.kind == .walk, colour: colour).frame(width: 3).padding(.top, 10).frame(maxHeight: .infinity, alignment: .top)
                                    .offset(y: 0)
                            }
                        }
                    VStack(alignment: .leading, spacing: 1) {
                        Text(step.title).font(.geist(14, .semibold)).foregroundStyle(index == current ? colour : Palette.text)
                        if !step.sub.isEmpty { Text(step.sub).font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
                    }
                    Spacer(minLength: 8)
                    Text(step.time).font(.geist(13)).monospacedDigit().foregroundStyle(Palette.textSoft)
                }
                .padding(.bottom, isLast ? 0 : 14)
                .background(alignment: .topLeading) {
                    if !isLast {
                        connector(step.kind == .walk, colour: colour).frame(width: 3).padding(.leading, 7).padding(.top, 10).offset(y: 6)
                    }
                }
                .opacity(current.map { index < $0 ? 0.5 : 1 } ?? 1)
            }
        }
    }

    @ViewBuilder
    private func connector(_ walking: Bool, colour: Color) -> some View {
        if walking {
            Rectangle().fill(.clear).overlay {
                GeometryReader { proxy in
                    Path { path in
                        path.move(to: CGPoint(x: 1.5, y: 0))
                        path.addLine(to: CGPoint(x: 1.5, y: proxy.size.height))
                    }
                    .stroke(Palette.textSoft, style: StrokeStyle(lineWidth: 3, lineCap: .round, dash: [0.1, 6]))
                }
            }
        } else {
            Capsule().fill(colour)
        }
    }
}

/// The way on a map, in place: each leg in its line's colour, walking dotted, start and end marked.
struct WayMap: View {
    let title: String
    let legs: [TodayPage.Trip.Leg]
    /// While the trip is live: our navigation on the map (the phone, the next turn, Speak turns).
    var guiding: (trip: TodayPage.Trip, follow: PhoneFollow)?
    @Environment(\.dismiss) private var dismiss
    @AppStorage("speakTurns") private var speak = false
    @State private var following = true
    @State private var pull: CGFloat = 0

    var body: some View {
        let here = guiding?.follow.here
        ZStack(alignment: .top) {
            CityMap(lines: WayDrawing.lines(legs, doneBefore: here?.leg), pins: WayDrawing.pins(legs, to: legs.last?.to ?? "", start: guiding == nil),
                    fit: WayDrawing.all(legs), padding: UIEdgeInsets(top: 150, left: 50, bottom: 120, right: 50),
                    follow: guiding != nil && following, showsUser: guiding != nil)
                .ignoresSafeArea()
            VStack(spacing: 10) {
                HStack(alignment: .top, spacing: 10) {
                    // Pull the title down: the map shrinks back to where it came from.
                    VStack(alignment: .leading, spacing: 6) {
                        Capsule().fill(Palette.textSoft.opacity(0.5)).frame(width: 36, height: 5).frame(maxWidth: .infinity)
                        Text(title).font(.geist(17, .semibold)).foregroundStyle(Palette.text).lineLimit(2)
                    }
                        .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .glassEffect(.regular, in: .rect(cornerRadius: 22))
                        .contentShape(.rect)
                        .gesture(
                            DragGesture(minimumDistance: 8)
                                .onChanged { value in pull = max(0, value.translation.height) }
                                .onEnded { value in
                                    if value.translation.height > 90 || value.predictedEndTranslation.height > 220 { dismiss() }
                                    withAnimation(.spring(duration: 0.35, bounce: 0.25)) { pull = 0 }
                                }
                        )
                        .accessibilityAction(named: "Close the map") { dismiss() }
                    Button { dismiss() } label: {
                        Image(systemName: "checkmark").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 48, height: 48).background(Palette.action, in: .circle)
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("Done")
                }
                if let here, let turn = here.turn, !here.offRoute {
                    TurnRow(turn: turn, metres: here.metresToTurn)
                        .padding(14)
                        .glassEffect(.regular, in: .rect(cornerRadius: 22))
                        .transition(.opacity.combined(with: .offset(y: -8)))
                } else if let here, here.offRoute {
                    Label("You’re off the way. Go back to the line, or plan a new way on the card.", systemImage: "exclamationmark.triangle.fill")
                        .font(.geist(14, .semibold)).foregroundStyle(Palette.warnText)
                        .padding(14).frame(maxWidth: .infinity, alignment: .leading)
                        .background(Palette.warnBackground, in: .rect(cornerRadius: 18))
                }
            }
            .padding(.horizontal, 14).padding(.top, 14)
        }
        .overlay(alignment: .bottomTrailing) {
            if guiding != nil {
                VStack(spacing: 10) {
                    // Speak turns, with the phone's own voice.
                    Button { speak.toggle() } label: {
                        Image(systemName: speak ? "speaker.wave.2.fill" : "speaker.slash.fill").font(.system(size: 17, weight: .semibold))
                            .foregroundStyle(speak ? Palette.action : Palette.text)
                            .contentTransition(.symbolEffect(.replace))
                            .frame(width: 48, height: 48).glassEffect(.regular.interactive(), in: .circle)
                    }
                    .accessibilityLabel(speak ? "Stop speaking turns" : "Speak turns")
                    Button { following.toggle() } label: {
                        Image(systemName: following ? "location.north.line.fill" : "location").font(.system(size: 17, weight: .semibold))
                            .foregroundStyle(following ? Color(hex: 0x2F6FD0) : Palette.text)
                            .frame(width: 48, height: 48).glassEffect(.regular.interactive(), in: .circle)
                    }
                    .accessibilityLabel(following ? "Show the whole way" : "Follow me")
                }
                .buttonStyle(PressStyle())
                .padding(.trailing, 16).padding(.bottom, 40)
            }
        }
        // Following the finger while pulled, as a sheet does.
        .scaleEffect(1 - min(pull, 300) / 1500, anchor: .top)
        .offset(y: pull * 0.6)
        .animation(Motion.ios(0.3), value: here?.turn)
        .presentationDetents([.large])
    }
}

/// A ticket's half (.tk-top / .tk-bot): rounded outer corners and the two notches the perforation runs between.
nonisolated struct TicketHalf: Shape {
    let top: Bool

    func path(in rect: CGRect) -> Path {
        let radius: CGFloat = 26
        let body = Path(roundedRect: rect, cornerRadii: top
            ? RectangleCornerRadii(topLeading: radius, bottomLeading: 0, bottomTrailing: 0, topTrailing: radius)
            : RectangleCornerRadii(topLeading: 0, bottomLeading: radius, bottomTrailing: radius, topTrailing: 0))
        let y = top ? rect.maxY : rect.minY
        let notches = Path { path in
            path.addEllipse(in: CGRect(x: rect.minX - 11, y: y - 11, width: 22, height: 22))
            path.addEllipse(in: CGRect(x: rect.maxX - 11, y: y - 11, width: 22, height: 22))
        }
        return body.subtracting(notches)
    }
}

/// Other ways (the canvas's sheet of tickets): each way printed in its line's colour, the one the card
/// shows stamped "Your way"; Use this makes another the card's, and the sheet slides away.
struct OtherWaysSheet: View {
    let leave: TodayPage.Leave
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var busy: Int?
    @State private var problem: (way: Int, text: String)?
    @State private var open: Set<Int> = []

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Other ways").font(.posterGrotesque(28)).tracking(-0.4).foregroundStyle(Palette.text)
                        Text("To \(leave.to)" + (leave.start.map { " · be there by \($0)" } ?? "")).font(.geist(13.5)).foregroundStyle(Palette.textSoft)
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
                ForEach(Array(leave.ways.enumerated()), id: \.element.id) { index, way in
                    ticket(way).modifier(Rise(order: index))
                }
            }
            .padding(.horizontal, 16).padding(.top, 20).padding(.bottom, 30)
        }
        .scrollIndicators(.hidden)
        .background(Palette.canvas)
        .flexibleSheet()
        .presentationBackground(Palette.canvas)
    }

    private func ticket(_ way: TodayPage.Way) -> some View {
        let mine = way.id == 0
        let spareTone: (Color, Color) = way.spareMinutes < 0 ? (Palette.conflictText, Palette.conflictBackground)
            : way.spareMinutes <= 2 ? (Palette.warnText, Palette.warnBackground)
            : way.spareMinutes >= 10 ? (Color(hex: 0x17603A), Color(hex: 0xE4F4E8)) : (Palette.text, Palette.quiet)
        return VStack(spacing: 0) {
            TicketTop(way: way, mine: mine)

            // The perforation.
            Line().stroke(Color(hex: 0x211D15, opacity: 0.28), style: StrokeStyle(lineWidth: 2, dash: [6, 5]))
                .frame(height: 2).padding(.horizontal, 14)
                .background(Palette.surface)
            VStack(alignment: .leading, spacing: 8) {
                Text(way.spareWords).font(.geist(13, .bold)).foregroundStyle(spareTone.0)
                    .padding(.horizontal, 10).frame(height: 26)
                    .background(spareTone.1, in: .capsule)
                WhyLines(way: way, start: leave.start)
                if open.contains(way.id) {
                    StepList(steps: TodayPage.Step.of(way.legs, to: leave.to, start: leave.start))
                        .padding(.top, 6)
                        .transition(.opacity.combined(with: .offset(y: -6)))
                }
                Group {
                    if mine {
                        Label("Your way", systemImage: "checkmark").labelStyle(Tight())
                            .font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                            .frame(maxWidth: .infinity).frame(height: 44)
                            .background(Palette.quiet, in: .capsule)
                    } else {
                        Button {
                            busy = way.id
                            Task {
                                if let why = await model.keepWay(session.api, way: way) {
                                    withAnimation(.snappy) { problem = (way.id, why) }
                                } else {
                                    dismiss()
                                }
                                busy = nil
                            }
                        } label: {
                            Group {
                                if busy == way.id { ProgressView().tint(.white) } else { Text("Use this") }
                            }
                            .font(.geist(15, .semibold)).foregroundStyle(.white)
                            .frame(maxWidth: .infinity).frame(height: 44)
                            .background(Palette.action, in: .capsule)
                            .contentShape(.capsule)
                        }
                        .buttonStyle(PressStyle())
                        .disabled(busy != nil)
                    }
                }
                .padding(.top, 4)
                if let problem, problem.way == way.id {
                    Label(problem.text, systemImage: "exclamationmark.circle").labelStyle(Tight())
                        .font(.geist(13, .semibold)).foregroundStyle(Palette.conflictText)
                        .transition(.opacity.combined(with: .offset(y: -6)))
                }
                if !way.legs.isEmpty {
                    Button { withAnimation(Motion.ios(0.4)) { if open.contains(way.id) { open.remove(way.id) } else { open.insert(way.id) } } } label: {
                        HStack(spacing: 5) {
                            Text("Journey details")
                            Image(systemName: "chevron.down").font(.system(size: 11, weight: .bold)).rotationEffect(.degrees(open.contains(way.id) ? 180 : 0))
                        }
                        .font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink)
                        .frame(minHeight: 36)
                    }
                    .buttonStyle(PressStyle())
                }
            }
            .padding(.top, 14).padding(.horizontal, 18).padding(.bottom, 12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Palette.surface)
            .clipShape(TicketHalf(top: false))
        }
        .shadow(color: Color(hex: 0x19140C, opacity: 0.22), radius: 9, y: 10)
    }

}

/// A Travel ticket's top half: the line plates, Leave → There, the minutes, the line's colour.
/// Other ways and Getting there show the same ticket.
struct TicketTop: View {
    let way: TodayPage.Way
    var mine = false

    var body: some View {
        // TRIP_LOOK: the line's colour; a change runs from the first line into the second; bike teal, walking soft brown.
        let look = LineLook(hex: way.lines.first?.color ?? (way.mode == "bike" ? "#00768B" : "#6A6253"))
        let fill = way.lines.count > 1 ? LineLook.fill(from: look, to: LineLook(hex: way.lines[1].color)) : look.fill
        let ink = look.ink
        VStack(alignment: .leading, spacing: 0) {
            if mine {
                Text("YOUR WAY").font(.posterMarker(15)).foregroundStyle(Motion.ink)
                    .padding(.horizontal, 10).padding(.top, 3).padding(.bottom, 2)
                    .background(Color(hex: 0xFFE363))
                    .rotationEffect(.degrees(-2))
                    .padding(.bottom, 10)
            }
            HStack(spacing: 8) {
                if way.lines.isEmpty {
                    plate(way.mode == "bike" ? "Bike" : "Walk", symbol: way.mode == "bike" ? "bicycle" : "figure.walk", colour: look.deep)
                }
                ForEach(Array(way.lines.enumerated()), id: \.offset) { index, line in
                    if index > 0 { Image(systemName: "chevron.right").font(.system(size: 12, weight: .bold)) }
                    plate(Board.badge(line.line), symbol: "tram.fill", colour: LineLook(hex: line.color).deep)
                }
                if let departs = way.departs, !way.lines.isEmpty {
                    Text("at \(departs)").font(.geist(16, .bold))
                }
            }
            HStack(alignment: .bottom, spacing: 10) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Leave").font(.geist(12.5, .semibold)).opacity(0.9)
                    Text(way.leaveTime).font(.posterGrotesque(42)).tracking(-1).monospacedDigit()
                }
                Image(systemName: "arrow.right").font(.system(size: 20, weight: .semibold)).padding(.bottom, 10)
                VStack(alignment: .leading, spacing: 4) {
                    Text("There").font(.geist(12.5, .semibold)).opacity(0.9)
                    Text(way.arriveTime).font(.posterGrotesque(42)).tracking(-1).monospacedDigit()
                }
            }
            .padding(.top, 12)
        }
        .foregroundStyle(ink)
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.top, 16).padding(.horizontal, 18).padding(.bottom, 18)
        .background(alignment: .topTrailing) {
            Text(way.lines.count == 1 ? Board.badge(way.lines[0].line) : "")
                .font(.posterGrotesque(170)).foregroundStyle(ink.opacity(0.13))
                .offset(x: 8, y: -18)
                .lineLimit(1).fixedSize()
        }
        .overlay(alignment: .topTrailing) {
            Text("\(way.minutes) min").font(.geist(13, .bold)).foregroundStyle(ink)
                .padding(.horizontal, 11).frame(height: 28)
                .background(.black.opacity(0.28), in: .capsule)
                .padding(16)
        }
        .background(fill)
        .clipShape(TicketHalf(top: true))
    }

    private func plate(_ text: String, symbol: String, colour: Color) -> some View {
        Label(text, systemImage: symbol).labelStyle(Tight())
            .font(.posterGrotesque(20)).foregroundStyle(colour)
            .padding(.leading, 8).padding(.trailing, 10).frame(height: 32)
            .background(Color(hex: 0xFFF8E6), in: .rect(cornerRadius: 8))
    }
}

/// A ticket's reasons (.tk-why): changes, walking, and how close it cuts it.
