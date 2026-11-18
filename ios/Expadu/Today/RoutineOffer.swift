import API
import Core
import DesignSystem
import SwiftUI

/// The routine their trips show, offered on Today (canvas "Expadu iPhone: Routines", look A · Week
/// grid; owner's go on 17 Nov): their own trips to the place as dots across the week, three weeks side
/// by side in each day's column, and a glass lens they drag to their usual time. The trips under it
/// light up. A day tapped is left out. Offered, never assumed: Tell me when to leave, or Not now, each
/// with Undo.
struct RoutineOffer: View {
    let offer: RoutinesState.Offer
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var minute: Int
    @State private var days: [Bool]
    @State private var dragFrom: Int?
    @State private var busy = false
    @State private var arrived = false

    init(offer: RoutinesState.Offer) {
        self.offer = offer
        _minute = State(initialValue: Routines.minute(offer.at))
        _days = State(initialValue: (1...7).map { offer.weekdays.contains($0) })
    }

    private static let teal = Color(light: 0x00768B, dark: 0x5CCBE0)
    private static let field = Color(light: 0xF6F1E6, dark: 0x2B2920)
    private static let height: CGFloat = 250
    private static let axis: CGFloat = 38

    /// Two hours around the usual time, from a half hour.
    private var top: Int { ((Routines.minute(offer.at) - 60) / 30) * 30 }
    private var span: Int { 120 }

    private func near(_ trip: RoutinesState.Trip) -> Bool {
        abs(trip.minute - minute) <= 15 && days[trip.weekday - 1]
    }

    private var weekdays: [Int] { (1...7).filter { days[$0 - 1] } }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Label("A rhythm in your trips", systemImage: "waveform.path.ecg")
                .font(.geist(12.5, .semibold)).foregroundStyle(Self.teal)
            HStack(alignment: .lastTextBaseline) {
                VStack(alignment: .leading, spacing: 3) {
                    Text("To \(offer.toLabel)").font(.posterGrotesque(25)).foregroundStyle(Palette.text)
                    Text("\(Text("\(offer.trips.filter(near).count)").foregroundStyle(Self.teal).bold()) of your \(offer.trips.count) trips there\nin three weeks")
                        .font(.geist(13.5)).foregroundStyle(Palette.textSoft).monospacedDigit()
                        .contentTransition(.numericText())
                }
                Spacer(minLength: 8)
                VStack(alignment: .trailing, spacing: 0) {
                    Text("usually leaving").font(.geist(11.5, .semibold)).foregroundStyle(Palette.textSoft)
                    Text(Routines.clock(minute)).font(.posterGrotesque(46)).tracking(-1).monospacedDigit()
                        .foregroundStyle(Palette.text).contentTransition(.numericText())
                }
            }
            .padding(.top, 4)

            dayPucks.padding(.top, 12)
            grid.padding(.top, 8)

            Text("Each dot is a trip you started in the last three weeks. Drag the glass to your usual time, and tap a day to leave it out.")
                .font(.geist(12.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                .padding(.top, 9).padding(.horizontal, 2)

            Button {
                busy = true
                Task {
                    let at = Routines.clock(minute)
                    if await model.confirmRoutine(session.api, offer, weekdays: weekdays, at: at) {
                        await NotificationAsk.shared.afterReminder(.init(title: "Time to leave for \(offer.toLabel)", body: "You usually leave at \(at).", at: nil), session.api)
                    }
                    busy = false
                }
            } label: {
                Text("Tell me when to leave").font(.geist(16.5, .semibold)).foregroundStyle(Palette.onAction)
                    .frame(maxWidth: .infinity, minHeight: 52)
            }
            .buttonFace(Palette.action, radius: 18)
            .disabled(busy || weekdays.isEmpty)
            .padding(.top, 10)
            Button("Not now") { busy = true; Task { await model.notNowRoutine(session.api, offer); busy = false } }
                .font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                .frame(maxWidth: .infinity, minHeight: 44)
                .disabled(busy)
        }
        .padding(.horizontal, 16).padding(.top, 16).padding(.bottom, 8)
        .card(radius: 28)
        .opacity(arrived ? 1 : 0).offset(y: arrived ? 0 : 14).scaleEffect(arrived ? 1 : 0.98)
        .onAppear { withAnimation(reduceMotion ? nil : .spring(duration: 0.5, bounce: 0.15)) { arrived = true } }
        .sensoryFeedback(.selection, trigger: minute)
    }

    // MARK: Parts

    private var dayPucks: some View {
        HStack(spacing: 0) {
            Color.clear.frame(width: Self.axis, height: 1)
            ForEach(0..<7, id: \.self) { i in
                Button {
                    withAnimation(.snappy) { days[i].toggle() }
                } label: {
                    Text(["M", "T", "W", "T", "F", "S", "S"][i]).font(.geist(13, .bold))
                        .foregroundStyle(days[i] ? Palette.onInk : Palette.textSoft)
                        .frame(width: 34, height: 34)
                        .background(days[i] ? Palette.text : Palette.quiet, in: .rect(cornerRadius: 11, style: .continuous))
                }
                .buttonStyle(.plain)
                .frame(maxWidth: .infinity)
                .accessibilityLabel(["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"][i])
                .accessibilityAddTraits(days[i] ? .isSelected : [])
            }
        }
    }

    private var grid: some View {
        GeometryReader { proxy in
            let width = proxy.size.width
            let column = (width - Self.axis) / 7
            let y = { (m: Int) -> CGFloat in CGFloat(m - top) / CGFloat(span) * Self.height }
            let lane = { (trip: RoutinesState.Trip) -> CGFloat in
                Self.axis + CGFloat(trip.weekday - 1) * column + column * (0.2 + 0.3 * CGFloat(Self.week(trip.date)))
            }
            ZStack(alignment: .topLeading) {
                // Hours.
                ForEach([top + 30, top + 60, top + 90], id: \.self) { m in
                    Path { $0.move(to: CGPoint(x: 0, y: y(m))); $0.addLine(to: CGPoint(x: width, y: y(m))) }
                        .stroke(Palette.border, style: StrokeStyle(lineWidth: 1, dash: [3, 3]))
                    Text(Routines.clock(m)).font(.geist(10.5, .semibold)).foregroundStyle(Palette.textSoft).monospacedDigit()
                        .position(x: 20, y: y(m) - 8)
                }
                // A day left out, hatched.
                ForEach(0..<7, id: \.self) { i in
                    Hatch().stroke(Palette.borderStrong.opacity(0.18), lineWidth: 5)
                        .frame(width: column, height: Self.height)
                        .clipped()
                        .offset(x: Self.axis + CGFloat(i) * column)
                        .opacity(days[i] ? 0 : 1)
                }
                // The band they drag, under the trips: the ones it covers light up, never blurred.
                lens(width: width, y: y)
                // Their trips.
                ForEach(offer.trips, id: \.self) { trip in
                    let inside = trip.minute >= top && trip.minute <= top + span
                    let lit = near(trip)
                    Circle()
                        .fill(inside ? (lit ? Self.teal : Palette.borderStrong.opacity(0.55)) : .clear)
                        .overlay { if !inside { Circle().strokeBorder(Palette.borderStrong, lineWidth: 1.5) } }
                        .frame(width: 11, height: 11)
                        .shadow(color: lit ? Self.teal.opacity(0.45) : .clear, radius: 5, y: 2)
                        .scaleEffect(lit ? 1.15 : 1)
                        .position(x: lane(trip), y: inside ? y(trip.minute) : (trip.minute > top + span ? Self.height - 24 : 24))
                        .opacity(days[trip.weekday - 1] || !inside ? 1 : 0.35)
                        .animation(.snappy, value: lit)
                        .allowsHitTesting(false)
                    if !inside {
                        Text(trip.at).font(.geist(9.5, .semibold)).foregroundStyle(Palette.textSoft).monospacedDigit()
                            .position(x: lane(trip), y: trip.minute > top + span ? Self.height - 10 : 10)
                    }
                }
            }
        }
        .frame(height: Self.height)
        .background(Self.field, in: .rect(cornerRadius: 18, style: .continuous))
        .clipShape(.rect(cornerRadius: 18, style: .continuous))
        .accessibilityElement(children: .contain)
    }

    /// The clear band they drag: glass-like (a bright top edge, a darker bottom one, a soft shine),
    /// drawn under the trips so they stay sharp. iOS's own glass bends what's under it.
    private func lens(width: CGFloat, y: (Int) -> CGFloat) -> some View {
        let shape = RoundedRectangle(cornerRadius: 16, style: .continuous)
        return shape
            .fill(LinearGradient(colors: [.white.opacity(0.75), .white.opacity(0.35), .white.opacity(0.55)], startPoint: .top, endPoint: .bottom))
            .overlay { shape.fill(LinearGradient(colors: [.white.opacity(0.7), .clear, .clear, .white.opacity(0.3)], startPoint: .topLeading, endPoint: .bottomTrailing)) }
            .overlay { shape.strokeBorder(Palette.text.opacity(dragFrom == nil ? 0.22 : 0.0), lineWidth: 1) }
            .overlay { shape.strokeBorder(Self.teal.opacity(dragFrom == nil ? 0 : 0.6), lineWidth: 1.5) }
            .frame(width: width - Self.axis + 4 - 6, height: CGFloat(30) / CGFloat(span) * Self.height)
            .shadow(color: .black.opacity(dragFrom == nil ? 0.12 : 0.2), radius: dragFrom == nil ? 10 : 16, y: dragFrom == nil ? 6 : 10)
            .scaleEffect(dragFrom == nil ? 1 : 1.015)
            .animation(.snappy, value: dragFrom == nil)
            .overlay(alignment: .trailing) {
                VStack(spacing: 3) {
                    Capsule().fill(Palette.text.opacity(0.5)).frame(width: 15, height: 2)
                    Capsule().fill(Palette.text.opacity(0.5)).frame(width: 15, height: 2)
                }
                .padding(.trailing, 9)
            }
            .offset(x: Self.axis - 4, y: y(minute - 15))
            .gesture(
                DragGesture(minimumDistance: 2)
                    .onChanged { drag in
                        let from = dragFrom ?? minute
                        if dragFrom == nil { dragFrom = minute }
                        let moved = Int((drag.translation.height / Self.height * CGFloat(span) / 5).rounded()) * 5
                        minute = min(top + span - 15, max(top + 15, from + moved))
                    }
                    .onEnded { _ in dragFrom = nil }
            )
            .accessibilityElement()
            .accessibilityLabel("Usually leaving")
            .accessibilityValue(Routines.clock(minute))
            .accessibilityAdjustableAction { direction in
                switch direction {
                case .increment: minute = min(top + span - 15, minute + 5)
                case .decrement: minute = max(top + 15, minute - 5)
                @unknown default: break
                }
            }
    }

    /// Which of the three weeks a trip was in: 0 the oldest, 2 this one.
    private static func week(_ date: String) -> Int {
        let format = Date.ISO8601FormatStyle().year().month().day()
        guard let day = try? format.parse(date) else { return 2 }
        let ago = Calendar.current.dateComponents([.day], from: Calendar.current.startOfDay(for: day), to: Calendar.current.startOfDay(for: .now)).day ?? 0
        return 2 - min(2, max(0, ago / 7))
    }
}

/// Diagonal lines filling their frame: a day left out.
private nonisolated struct Hatch: Shape {
    func path(in rect: CGRect) -> Path {
        var path = Path()
        var x = -rect.height
        while x < rect.width {
            path.move(to: CGPoint(x: x, y: rect.height))
            path.addLine(to: CGPoint(x: x + rect.height, y: 0))
            x += 12
        }
        return path
    }
}

/// The routine just set, where the offer was: it folds into this.
struct RoutineSetChip: View {
    let routine: RoutinesState.Routine
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var ring = false

    var body: some View {
        HStack(spacing: 13) {
            Image(systemName: "bell.fill").font(.system(size: 20, weight: .semibold))
                .foregroundStyle(Color(hex: 0xFFE363))
                .rotationEffect(.degrees(ring ? 0 : 14), anchor: .top)
                .frame(width: 46, height: 46)
                .background(Palette.text, in: .rect(cornerRadius: 14, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text("To \(routine.toLabel) · \(routine.at)").font(.posterGrotesque(19)).foregroundStyle(Palette.text).monospacedDigit()
                Text("\(Routines.days(routine.weekdays)) · I’ll tell you at \(Routines.clock(Routines.minute(routine.at) - 5))")
                    .font(.geist(13.5)).foregroundStyle(Palette.textSoft).monospacedDigit()
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 16).padding(.vertical, 14)
        .card(radius: 24)
        .onAppear { withAnimation(reduceMotion ? nil : .spring(duration: 0.7, bounce: 0.6).delay(0.3)) { ring = true } }
        .accessibilityElement(children: .combine)
    }
}
