import API
import Core
import CoreLocation
import Maps
import DesignSystem
import SafariServices
import SwiftUI

// MARK: - Your day

/// The day as a line from now down through the plans; the next one is big and carries its notes,
/// what to bring and the Leave card; free time between shows what's open then.
struct YourDay: View {
    let page: TodayPage
    @State private var doneOpen = false

    private var done: [TodayPage.Plan] { page.plans.filter(\.completed) }

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            SectionHead(title: "Your day") {
                if !page.ahead.isEmpty {
                    Text(page.ahead.count == 1 ? "1 plan" : "\(page.ahead.count) plans").font(.geist(13.5, .medium)).foregroundStyle(Palette.textSoft)
                }
            }
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 8) {
                    PulseDot()
                    Text("Now · \(TodayPage.clock(page.now))").font(.geist(13, .bold)).foregroundStyle(Self.line)
                }
                .frame(height: 22).padding(.bottom, 6)
                // A trip to something that isn't a plan (an event's Getting there) takes its place in time:
                // plans before its leave time come first, the trip and where it goes, then the rest.
                let tripHere = page.trip.flatMap { trip in page.ahead.contains(where: { $0.title == trip.to }) ? nil : trip }
                let leaveAt = tripHere == nil ? nil : page.tripLeave?.time
                let before = leaveAt.map { at in page.ahead.filter { ($0.start ?? "") < at } } ?? []
                let after = page.ahead.filter { plan in !before.contains { $0.id == plan.id } }
                if !done.isEmpty { DoneFold(page: page, done: done, open: $doneOpen) }
                plans(before, firstIsNext: true)
                if let trip = tripHere {
                    LiveTrip(trip: trip).padding(.bottom, 14)
                        .transition(.asymmetric(insertion: .scale(scale: 0.985).combined(with: .opacity),
                                                removal: .opacity.combined(with: .offset(y: -10)).combined(with: .scale(scale: 0.97))))
                }
                // Free time is before they leave; once on the way it isn't theirs to fill.
                if page.free?.anchorTitle == nil, let free = page.free, tripHere == nil || page.tripLeave != nil { FreeTime(page: page, free: free) }
                if let trip = tripHere {
                    // Where the day is going: the trip's own destination, as a stop on the line.
                    TripEnd(trip: trip).padding(.bottom, 12)
                } else if page.ahead.isEmpty {
                    EmptyDay(page: page).padding(.top, 4).padding(.bottom, 12)
                }
                plans(after, firstIsNext: before.isEmpty && tripHere == nil)
                AddPlan(date: page.date)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.top, 14).padding(.trailing, 14).padding(.bottom, 6).padding(.leading, 40)
            .background(alignment: .leading) {
                Rectangle().fill(Self.line).frame(width: 3).clipShape(.capsule)
                    .padding(.top, 26).padding(.bottom, 30).padding(.leading, 21)
            }
            .card(radius: 22)
        }
    }

    static let line = Color(hex: 0xE6421F)

    /// Plans on the line, each with what follows it (free time, or the way to the next).
    @ViewBuilder
    private func plans(_ list: [TodayPage.Plan], firstIsNext: Bool) -> some View {
        ForEach(Array(list.enumerated()), id: \.element.id) { index, plan in
            Stop(page: page, plan: plan, next: firstIsNext && index == 0)
            if let free = page.free, free.anchorTitle == plan.title {
                FreeTime(page: page, free: free)
            } else if let link = page.link(after: plan), index < list.count - 1 {
                LinkLine(link: link)
            }
        }
    }
}

/// The destination of a trip that isn't a plan (an event's Getting there): its time and name.
struct TripEnd: View {
    let trip: TodayPage.Trip

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 10) {
            Text(trip.startTime ?? trip.arriveTime ?? "").font(.posterGrotesque(20)).foregroundStyle(Palette.text).monospacedDigit()
            VStack(alignment: .leading, spacing: 2) {
                Text(trip.to).font(.geist(16, .semibold)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                if let there = trip.arriveTime {
                    Text(trip.startTime == nil ? "You’re there" : "Starts · you’re there at \(there)").font(.geist(13)).foregroundStyle(Palette.textSoft)
                }
            }
        }
        .accessibilityElement(children: .combine)
    }
}

/// A day with nothing ahead (the canvas's .ln-empty): what that means now, and two ways on.
struct EmptyDay: View {
    let page: TodayPage
    @Environment(\.shell) private var shell

    var body: some View {
        let evening = TodayPage.greeting(at: page.now, name: "").hasPrefix("Guten Abend")
        let words: (String, String, String) = page.plans.isEmpty
            ? (page.welcome ? ("No plans yet", "Add one, or let Composer plan your day.", "Plan my day") : ("No plans added for today", "Your day is open.", "Plan the rest"))
            : evening ? ("No more plans today", "Tomorrow is still open.", "Plan tomorrow") : ("No plans left today", "Everything is done.", "Plan the rest")
        VStack(alignment: .leading, spacing: 0) {
            Text(words.0).font(.geist(16, .semibold)).foregroundStyle(Palette.text)
            Text(words.1).font(.geist(13.5)).foregroundStyle(Palette.textSoft).padding(.top, 2)
            HStack(spacing: 8) {
                Button { shell.openComposer() } label: {
                    AskComposerLabel(title: words.2, height: 44)
                }
                .buttonStyle(PressStyle())
                Button { shell.show(.travel) } label: {
                    Text("Find a route").font(.geist(15, .medium)).foregroundStyle(Palette.text).lineLimit(1)
                        .fixedSize()
                        .padding(.horizontal, 16).frame(height: 44)
                        .buttonFace(Palette.quiet)
                }
                .buttonStyle(PressStyle())
            }
            .padding(.top, 12)
        }
    }
}

struct PulseDot: View {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        Circle().fill(YourDay.line).frame(width: 13, height: 13)
            // Core Animation pulses the ring: nothing is redrawn per frame (Turning.swift).
            .background { PulseRing(colour: YourDay.line, animated: !reduceMotion) }
            .offset(x: -25)
            .padding(.trailing, -13)
            .accessibilityHidden(true)
    }
}

/// A plan on the line. The next one is big, with its deadline, what to bring and the Leave card.
struct Stop: View {
    let page: TodayPage
    let plan: TodayPage.Plan
    let next: Bool
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @State private var details = false
    @State private var editing = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .top, spacing: 8) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(plan.start ?? "Today")
                        .font(.posterGrotesque(next ? 46 : 19)).tracking(next ? -1 : 0).monospacedDigit()
                    Text(plan.title)
                        .font(next ? .posterGrotesque(22) : .geist(16, .semibold))
                        .tracking(next ? -0.2 : 0)
                        .padding(.top, next ? 6 : 1)
                    if let meta {
                        Text(meta).font(.geist(13.5)).foregroundStyle(Palette.textSoft).padding(.top, 2)
                    }
                }
                Spacer(minLength: 0)
                Menu {
                    Button(details ? "Hide details" : "Details", systemImage: "info.circle") { withAnimation(Motion.ios(0.35)) { details.toggle() } }
                    if plan.editable {
                        Button("Edit plan", systemImage: "pencil") { editing = true }
                    }
                    Button("Mark as done", systemImage: "checkmark.circle") {
                        Task { await model.markDone(session.api, plan: plan, date: page.date) }
                    }
                    if plan.editable {
                        Button("Remove", systemImage: "trash", role: .destructive) {
                            Task { await model.change(session.api, action: "remove", id: plan.id, date: page.date, symbol: "trash") }
                        }
                    }
                } label: {
                    Image(systemName: "ellipsis").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.textSoft)
                        .frame(width: 44, height: 44).contentShape(.rect)
                }
                .accessibilityLabel("More for \(plan.title)")
            }
            if details {
                // The canvas's Details: what's known about the plan, in place.
                VStack(alignment: .leading, spacing: 6) {
                    detail("clock", [plan.start, plan.end].compactMap { $0 }.joined(separator: "–"))
                    if let place = plan.place { detail("mappin", place) }
                    detail(plan.editable ? "person" : "doc.text", plan.editable ? "Your own plan" : "From Paperwork")
                }
                .padding(.vertical, 10).padding(.horizontal, 12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Palette.quiet, in: .rect(cornerRadius: 14))
                .padding(.top, 8)
                .transition(.opacity.combined(with: .offset(y: -6)))
            }
            if next {
                if let deadline = page.deadline(for: plan) {
                    DeadlineNote(deadline: deadline).padding(.top, 10)
                }
                if let bring = plan.bring {
                    BringCard(bring: bring, at: plan.start).padding(.top, 10)
                }
            }
            // The trip goes under the plan it's for: the next one, or a later one while this one is under way.
            if let trip = page.trip, trip.to == plan.title {
                // lvlive in, tlaway out.
                LiveTrip(trip: trip).padding(.top, 12)
                    .transition(.asymmetric(insertion: .scale(scale: 0.985).combined(with: .opacity),
                                            removal: .opacity.combined(with: .offset(y: -10)).combined(with: .scale(scale: 0.97))))
            } else if page.trip == nil, let leave = page.leave, leave.isKnown, forThis {
                LeaveCard(leave: leave, place: plan.shortPlace).padding(.top, 12)
                    .transition(.opacity)
            }
        }
        .foregroundStyle(Palette.text)
        .padding(.top, 6).padding(.bottom, 14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .overlay(alignment: .topLeading) {
            Circle().fill(Palette.surface).frame(width: 11, height: 11)
                .overlay { Circle().strokeBorder(YourDay.line, lineWidth: 3) }
                .offset(x: -24, y: next ? 22 : 12)
        }
        .accessibilityElement(children: .contain)
        .sheet(isPresented: $editing) { AddPlanSheet(date: page.date, editing: plan) }
    }

    private func detail(_ symbol: String, _ text: String) -> some View {
        Label(text, systemImage: symbol).labelStyle(Tight()).font(.geist(14)).foregroundStyle(Palette.text)
    }

    /// The Leave card's plan is this one (by the server's id; with none, the next plan).
    private var forThis: Bool {
        guard let id = page.leave?.planId else { return next }
        return id == plan.id
    }

    private var meta: String? {
        let place = plan.place ?? plan.subtitle
        let parts = [place, plan.end.map { "until \($0)" }].compactMap { $0 }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }
}

/// Paperwork's urgent date, under the appointment it's for.
struct DeadlineNote: View {
    let deadline: TodayPage.Deadline

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 8) {
            Image(systemName: "clock").font(.system(size: 14, weight: .semibold)).foregroundStyle(Color(hex: 0xC98208))
            (Text(deadline.headline + ".").font(.geist(14, .semibold)).foregroundStyle(Palette.text)
                + Text(" This appointment is the next step.").font(.geist(14)).foregroundStyle(Palette.textSoft))
        }
    }
}

/// What to bring, from Paperwork: "1 of 5 documents ready". A tap opens the list under it; ticking
/// here ticks it in Paperwork, the same record.
struct BringCard: View {
    let bring: TodayPage.Plan.Bring
    /// The appointment's time: "all set for 14:00".
    let at: String?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var open = false
    @State private var list: BringList?
    @State private var busy: String?
    @State private var problem: String?
    private let ink = Palette.bringText

    private var ready: Int { list?.documents.filter(\.ready).count ?? bring.ready }
    private var total: Int { list?.documents.count ?? bring.total }
    private var allSet: Bool { total > 0 && ready == total }

    var body: some View {
        VStack(spacing: 8) {
            Button {
                withAnimation(reduceMotion ? nil : Motion.ios(0.35)) { open.toggle() }
                if open, list == nil { Task { await load() } }
            } label: {
                HStack(spacing: 12) {
                    Image(systemName: allSet ? "checkmark" : "doc.text.fill").font(.system(size: 16, weight: allSet ? .bold : .regular))
                        .foregroundStyle(allSet ? Motion.green : ink)
                        .contentTransition(.symbolEffect(.replace))
                    VStack(alignment: .leading, spacing: 1) {
                        Text(allSet ? "What to bring · all set\(at.map { " for \($0)" } ?? "")" : "What to bring · \(list?.title ?? bring.title ?? "Paperwork")")
                            .font(.geist(12)).opacity(0.8).lineLimit(1)
                        Text(allSet ? "All \(total) documents ready" : "\(ready) of \(total) documents ready").font(.geist(15, .bold))
                            .contentTransition(.numericText(value: Double(ready)))
                    }
                    Spacer(minLength: 0)
                    Image(systemName: "chevron.down").font(.system(size: 12, weight: .semibold))
                        .rotationEffect(.degrees(open ? 0 : -90))
                }
                .foregroundStyle(ink)
                .padding(.horizontal, 14).padding(.vertical, 11)
                .background(Palette.bringBackground, in: .rect(cornerRadius: 14))
                .contentShape(.rect(cornerRadius: 14))
            }
            .buttonStyle(PressStyle())
            .accessibilityHint(open ? "Hides the list" : "Shows the list")
            .sensoryFeedback(.success, trigger: allSet) { _, now in now }

            if open {
                VStack(alignment: .leading, spacing: 2) {
                    if let list {
                        ForEach(list.documents) { document in
                            Button { Task { await tick(document) } } label: { BringRow(document: document, busy: busy == document.id) }
                                .buttonStyle(PressStyle())
                                .disabled(busy != nil)
                        }
                        Text("From your Paperwork record. Ticking here ticks it there.")
                            .font(.geist(12)).foregroundStyle(Palette.textSoft).padding(.top, 4)
                    } else if let problem {
                        Text(problem).font(.geist(13.5)).foregroundStyle(Palette.conflictText).padding(.vertical, 6)
                    } else {
                        ProgressView().frame(maxWidth: .infinity).padding(.vertical, 10)
                    }
                }
                .padding(.horizontal, 12).padding(.vertical, 10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Palette.quiet, in: .rect(cornerRadius: 14))
                // .chk opens down from the card (the canvas's open keyframes).
                .transition(.asymmetric(insertion: .opacity.combined(with: .offset(y: -8)), removal: .opacity))
            }
        }
    }

    private func load() async {
        guard let process = bring.process else { return }
        do {
            let loaded = try await session.api.bringList(process: process)
            withAnimation(reduceMotion ? nil : .snappy) { list = loaded; problem = nil }
        } catch {
            problem = "Couldn’t load the list. Try again in a moment."
        }
    }

    private func tick(_ document: BringList.Document) async {
        guard let process = bring.process, var list else { return }
        // Ticked at once; the record follows, and goes back if it says no.
        let before = list
        if let index = list.documents.firstIndex(where: { $0.id == document.id }) { list.documents[index].ready.toggle() }
        busy = document.id
        withAnimation(reduceMotion ? nil : Motion.spring) { self.list = list }
        do {
            let saved = try await session.api.tick(process: process, document: document.id, ready: !document.ready)
            withAnimation(reduceMotion ? nil : .snappy) { self.list = saved; problem = nil }
            await model.load(session.api, quietly: true)
        } catch {
            withAnimation(reduceMotion ? nil : .snappy) { self.list = before }
            problem = "Couldn’t save that. Try again in a moment."
        }
        busy = nil
    }
}

/// One document: a box that ticks with a pop, the name struck through once it's ready.
private struct BringRow: View {
    let document: BringList.Document
    let busy: Bool

    var body: some View {
        HStack(spacing: 10) {
            ZStack {
                RoundedRectangle(cornerRadius: 7).strokeBorder(Palette.textSoft, lineWidth: 1.5).opacity(document.ready ? 0 : 1)
                RoundedRectangle(cornerRadius: 7).fill(Motion.green).opacity(document.ready ? 1 : 0)
                Image(systemName: "checkmark").font(.system(size: 11, weight: .bold)).foregroundStyle(.white)
                    .scaleEffect(document.ready ? 1 : 0.3).opacity(document.ready ? 1 : 0)
            }
            .frame(width: 22, height: 22)
            VStack(alignment: .leading, spacing: 1) {
                Text(document.title).font(.geist(14)).strikethrough(document.ready)
                    .foregroundStyle(document.ready ? Palette.textSoft : Palette.text)
                    .fixedSize(horizontal: false, vertical: true)
                if let note = document.note, !document.ready {
                    Text(note).font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(2)
                }
            }
            Spacer(minLength: 0)
            if busy { ProgressView().controlSize(.mini) }
        }
        .frame(minHeight: 36)
        .contentShape(.rect)
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(document.ready ? [.isSelected] : [])
    }
}

/// The canvas's motion curves (today.css): --ios, and the springs its flips and notes use.
enum Motion {
    static let ios = Animation.timingCurve(0.32, 0.72, 0, 1, duration: 0.4)
    static func ios(_ duration: Double) -> Animation { .timingCurve(0.32, 0.72, 0, 1, duration: duration) }
    static let spring = Animation.spring(duration: 0.6, bounce: 0.35)
    static let green = Color(hex: 0x2F9E5B)
    static let ink = Color(hex: 0x211D15)
}

/// A value turning over into place (lvflip: rotateX from -90°), each after the one before.
struct Flip: ViewModifier {
    let trigger: Int
    var order = 0
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var angle = 0.0

    func body(content: Content) -> some View {
        content
            .rotation3DEffect(.degrees(angle), axis: (x: 1, y: 0, z: 0), anchor: UnitPoint(x: 0.5, y: 0.6), perspective: 0.6)
            .opacity(angle == 0 ? 1 : 0)
            .onChange(of: trigger) {
                guard !reduceMotion else { return }
                angle = -90
                withAnimation(Motion.spring.delay(0.06 + 0.08 * Double(order))) { angle = 0 }
            }
    }
}

/// Rising in after the card turns live (tlup: 8pt up, fading in), each part a little after the last.
struct Rise: ViewModifier {
    let order: Int
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown || reduceMotion ? 1 : 0)
            .offset(y: shown || reduceMotion ? 0 : 8)
            .onAppear { withAnimation(Motion.ios.delay(0.06 * Double(order))) { shown = true } }
    }
}

/// The card's own note (the canvas's .lv-say): green, dropped in at its top, with Undo when it can.
struct CardSay: View {
    let note: TodayModel.Note
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: note.symbol).font(.system(size: 13, weight: .bold))
            Text(note.text).font(.geist(13.5, .semibold)).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
            if note.undo != nil {
                Button("Undo") { Task { await model.undoCard(session.api) } }
                    .font(.geist(13.5, .bold))
                    .padding(.horizontal, 12).frame(height: 30)
                    .background(Color(hex: 0x17603A, opacity: 0.12), in: .rect(cornerRadius: 8, style: .continuous))
                    .contentShape(.rect.inset(by: -7))
            }
        }
        .foregroundStyle(Color(hex: 0x17603A))
        .padding(.vertical, 8).padding(.leading, 12).padding(.trailing, 8)
        .background(Color(hex: 0xE4F4E8), in: .rect(cornerRadius: 14))
        .transition(.asymmetric(insertion: .opacity.combined(with: .offset(y: -8)).combined(with: .scale(scale: 0.97, anchor: .top)), removal: .opacity))
    }
}

/// When to leave: a ring counting down, the line, when you're there, the minutes to spare,
/// the walk to the platform, and the trip's two actions. A changed way turns its values over and
/// the card glows green (lvflip, lvglow); its note sits at its top.
struct LeaveCard: View {
    let leave: TodayPage.Leave
    let place: String?
    private let warn = Color(hex: 0xC98208)
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var starting = false
    @State private var othersOpen = false
    @State private var replaceAsked = false
    @State private var glow = 0.0

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            if let say = model.cardNote {
                CardSay(note: say).padding(.top, -2)
            }
            HStack(spacing: 12) {
                ZStack {
                    Circle().stroke(Palette.quiet, lineWidth: 4)
                    Circle().trim(from: 0, to: ringFill).stroke(warn, style: StrokeStyle(lineWidth: 4, lineCap: .round)).rotationEffect(.degrees(-90))
                        .animation(Motion.ios(0.8), value: ringFill)
                    // Minutes in the last hour; further off, hours ("10 h").
                    let minutes = leave.leaveInMinutes ?? 0
                    VStack(spacing: -1) {
                        Text(minutes >= 60 ? "\(minutes / 60)" : "\(minutes)").font(.posterGrotesque(15)).contentTransition(.numericText(countsDown: true))
                        Text(minutes >= 60 ? "h" : "min").font(.geist(9, .semibold)).foregroundStyle(Palette.textSoft)
                    }
                }
                .frame(width: 46, height: 46)
                VStack(alignment: .leading, spacing: 3) {
                    Text("Leave at \(leave.leaveTime ?? "")").font(.geist(17, .bold)).modifier(Flip(trigger: model.cardFlips))
                    if leave.live {
                        HStack(spacing: 5) {
                            Circle().frame(width: 7, height: 7)
                            Text("Live · KVB").font(.geist(12.5, .bold))
                        }
                        .foregroundStyle(Motion.green)
                    }
                }
            }
            HStack(spacing: 6) {
                if let line = leave.line, let departs = leave.departs {
                    tile(label: nil, badge: Board.badge(line), line: LineLook(hex: leave.color ?? "#1A4CD4"), value: departs, note: leave.from ?? "", order: 1)
                }
                tile(label: "There", badge: nil, line: nil, value: leave.arriveTime ?? "", note: place ?? leave.to, order: 2)
                if let spare = leave.spareMinutes, spare < 0 {
                    // Late: by how much, in the warning colour, never a negative spare.
                    tile(label: "Late", badge: nil, line: nil, value: "\(-spare) min", note: leave.start.map { "after \($0)" } ?? "", warning: true, order: 3)
                } else if let spare = leave.spareMinutes {
                    tile(label: "Spare", badge: nil, line: nil, value: "\(spare) min", note: leave.start.map { "before \($0)" } ?? "", order: 3)
                }
            }
            if let note = leave.note {
                Label(note, systemImage: "figure.walk").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                    .labelStyle(Tight())
            }
            HStack(spacing: 8) {
                Button { start(replace: false) } label: {
                    Group {
                        if starting {
                            ProgressView().tint(.white)
                        } else {
                            Label("I’m on my way", systemImage: "figure.walk")
                        }
                    }
                    .font(.geist(15, .semibold)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity).frame(height: 44)
                    .buttonFace(Palette.action)
                    .contentShape(.capsule)
                }
                .buttonStyle(PressStyle())
                .disabled(starting)
                Button { othersOpen = true } label: {
                    Label("Other ways", systemImage: "arrow.triangle.branch")
                        .font(.geist(15, .medium)).foregroundStyle(Palette.text)
                        .padding(.horizontal, 16).frame(height: 44)
                        .buttonFace(Palette.quiet)
                        .contentShape(.capsule)
                }
                .buttonStyle(PressStyle())
                .disabled(leave.ways.count < 2)
            }
            .labelStyle(Tight())
        }
        .padding(14)
        .background(Palette.surface, in: .rect(cornerRadius: 20))
        .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(warn.opacity(0.55), lineWidth: 1.5) }
        // lvglow: green for a moment after the way changed, the halo fading out.
        .overlay { RoundedRectangle(cornerRadius: 20).strokeBorder(Motion.green, lineWidth: 2).opacity(glow) }
        .background { RoundedRectangle(cornerRadius: 20).stroke(Motion.green.opacity(0.14), lineWidth: 12).opacity(glow) }
        .animation(Motion.spring, value: model.cardNote)
        .onChange(of: model.cardFlips) {
            guard !reduceMotion else { return }
            glow = 1
            withAnimation(.easeOut(duration: 1.0).delay(0.4)) { glow = 0 }
        }
        .sheet(isPresented: $othersOpen) { OtherWaysSheet(leave: leave) }
        .confirmationDialog("Replace the trip you’re on?", isPresented: $replaceAsked, titleVisibility: .visible) {
            Button("Replace") { start(replace: true) }
            Button("Keep", role: .cancel) {}
        } message: {
            Text("You’re already on your way somewhere else.")
        }
    }

    /// I'm on my way, on the way the card shows; a trip already running asks first.
    private func start(replace: Bool) {
        // No way loaded yet: never a dead button. Look again and say what came of it.
        guard let way = leave.ways.first else {
            starting = true
            Task {
                await model.findWay(session.api)
                starting = false
            }
            return
        }
        starting = true
        Task {
            let running = await model.startTrip(session.api, way: way, replace: replace)
            starting = false
            if running { replaceAsked = true }
        }
    }

    /// Fuller as the time to leave comes closer, over the last quarter hour.
    private var ringFill: Double {
        max(0.04, 1 - Double(min(leave.leaveInMinutes ?? 15, 15)) / 15)
    }

    private func tile(label: String?, badge: String?, line: LineLook?, value: String, note: String, warning: Bool = false, order: Int) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            if let badge, let line {
                Label(badge, systemImage: "tram.fill").labelStyle(.titleAndIcon)
                    .font(.geist(12, .bold)).foregroundStyle(line.ink)
                    .padding(.horizontal, 6).frame(height: 20)
                    .background(line.fill, in: .rect(cornerRadius: 5))
            } else if let label {
                Text(label.uppercased()).font(.geist(10.5, .bold)).tracking(0.4).foregroundStyle(warning ? Palette.conflictText : Palette.textSoft)
            }
            Text(value).font(.posterGrotesque(19)).monospacedDigit().foregroundStyle(warning ? Palette.conflictText : Palette.text)
                .modifier(Flip(trigger: model.cardFlips, order: order))
            Text(note).font(.geist(12)).foregroundStyle(Palette.textSoft).lineLimit(1).minimumScaleFactor(0.85)
        }
        .padding(.vertical, 9).padding(.horizontal, 10)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Palette.quiet, in: .rect(cornerRadius: 12))
    }
}

/// On your way, live (the canvas's .trip-l): progress bars for the steps, the step they're on by the
/// clock in its line's colour, every step when asked, the way on a map, and End. It turns live in
/// place: the card settles in, its parts rise one after another, the dot pulses.

struct WhyLines: View {
    let way: TodayPage.Way
    let start: String?

    var body: some View {
        let lines: [(String, String)] = [
            way.lines.isEmpty ? nil : ("checkmark", way.transfers == 0 ? "No change" : way.transfers == 1 ? "1 change" : "\(way.transfers) changes"),
            way.walkMinutes > 0 ? ("figure.walk", "\(way.walkMinutes) min walking") : nil,
            way.spareMinutes < 0 ? ("exclamationmark.triangle.fill", "\(-way.spareMinutes) min after your \(start ?? "start")")
                : way.spareMinutes <= 2 ? ("exclamationmark.triangle.fill", "Only \(way.spareMinutes) min before your \(start ?? "start")") : nil,
        ].compactMap { $0 }
        FlowLines(lines: lines)
    }
}

struct FlowLines: View {
    let lines: [(String, String)]

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            ForEach(Array(lines.enumerated()), id: \.offset) { _, line in
                Label(line.1, systemImage: line.0).labelStyle(Tight())
                    .font(.geist(13.5, .medium)).foregroundStyle(Palette.text)
            }
        }
    }
}

nonisolated struct Line: Shape {
    func path(in rect: CGRect) -> Path {
        Path { path in
            path.move(to: CGPoint(x: rect.minX, y: rect.midY))
            path.addLine(to: CGPoint(x: rect.maxX, y: rect.midY))
        }
    }
}

/// The way between two plans: "About 25 min · 15 min to spare".
struct LinkLine: View {
    let link: TodayPage.Link

    var body: some View {
        let words = link.words
        Label(words.line, systemImage: words.tone == .conflict ? "exclamationmark.triangle.fill" : "arrow.down")
            .labelStyle(Tight())
            .font(.geist(13, words.tone == .ok ? .regular : .semibold))
            .foregroundStyle(words.tone == .conflict ? Palette.conflictText : words.tone == .tight ? Palette.warnText : Palette.textSoft)
            .padding(.bottom, 14)
    }
}

/// Free time on the line, and what's open then nearby.
struct FreeTime: View {
    let page: TodayPage
    let free: TodayPage.Free
    @State private var mapOpen = false

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if let line = page.freeLine {
                let parts = line.components(separatedBy: " · ")
                (Text(parts[0]).font(.geist(14, .semibold)).foregroundStyle(Palette.text)
                    + Text(parts.count > 1 ? " · " + parts.dropFirst().joined(separator: " · ") : "").font(.geist(14)).foregroundStyle(Palette.textSoft))
            }
            VStack(alignment: .leading, spacing: 0) {
                Text(free.heading).font(.geist(12.5, .bold)).foregroundStyle(Palette.textSoft)
                    .padding(.horizontal, 12).padding(.top, 8).padding(.bottom, 2)
                ForEach(free.places) { place in
                    HStack(spacing: 12) {
                        Image(systemName: PlaceSymbol.name(place.kind)).font(.system(size: 14, weight: .semibold))
                            .frame(width: 32, height: 32).background(Palette.surface, in: .rect(cornerRadius: 9, style: .continuous))
                        VStack(alignment: .leading, spacing: 1) {
                            Text(place.name).font(.geist(15.5, .semibold)).lineLimit(1)
                            Text(place.line).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                        // A place to spend the time can go in the plan; an errand (pharmacy, bakery) just shows.
                        if PlaceSymbol.stays(place.kind) { AddButton(candidate: place.candidate, date: page.date, title: place.name) }
                    }
                    .foregroundStyle(Palette.text)
                    .padding(.horizontal, 12).padding(.vertical, 6)
                }
                if free.places.contains(where: { $0.latitude != nil }) {
                    Button { mapOpen = true } label: {
                        Label("See them on the map", systemImage: "map").labelStyle(Tight())
                            .font(.geist(13.5, .semibold)).foregroundStyle(TodayLink.ink)
                            .padding(.horizontal, 12).padding(.vertical, 8)
                    }
                    .buttonStyle(PressStyle())
                }
            }
            .padding(.vertical, 4)
            .background(Palette.quiet, in: .rect(cornerRadius: 16))
        }
        .padding(.top, 8).padding(.bottom, 16)
        .sheet(isPresented: $mapOpen) { NearbyMap(page: page, free: free) }
    }
}

/// The free time's places on a map, in place (the canvas's map with its Nearby card): each marked,
/// the list under the map to add one, and more places in Travel.
struct NearbyMap: View {
    let page: TodayPage
    let free: TodayPage.Free
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell

    var body: some View {
        ZStack(alignment: .bottom) {
            let pins = free.places.compactMap { place -> CityMap.Pin? in
                guard let lat = place.latitude, let lng = place.longitude else { return nil }
                return CityMap.Pin(id: "p\(place.id)", coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lng), title: place.name, kind: .place(symbol: PlaceSymbol.name(place.kind)))
            }
            CityMap(lines: [], pins: pins, fit: pins.map(\.coordinate),
                    padding: UIEdgeInsets(top: 90, left: 50, bottom: 360, right: 50), showsUser: true)
                .ignoresSafeArea()
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    Label("Nearby", systemImage: "mappin").labelStyle(Tight())
                        .font(.geist(13, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 10).frame(height: 28)
                        .background(Color(hex: 0x2F78C8), in: .rect(cornerRadius: 8))
                    VStack(alignment: .leading, spacing: 1) {
                        Text(free.anchorArea.map { "Near \($0)" } ?? "Near \(free.near ?? "you")").font(.geist(16, .bold))
                        if let line = page.freeLine { Text(line).font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
                    }
                }
                .padding(.bottom, 8)
                ForEach(free.places) { place in
                    HStack(spacing: 12) {
                        Image(systemName: PlaceSymbol.name(place.kind)).font(.system(size: 14, weight: .semibold))
                            .frame(width: 32, height: 32).background(Palette.quiet, in: .rect(cornerRadius: 9, style: .continuous))
                        VStack(alignment: .leading, spacing: 1) {
                            Text(place.name).font(.geist(15.5, .semibold)).lineLimit(1)
                            Text(place.line).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                        // A place to spend the time can go in the plan; an errand (pharmacy, bakery) just shows.
                        if PlaceSymbol.stays(place.kind) { AddButton(candidate: place.candidate, date: page.date, title: place.name) }
                    }
                    .padding(.vertical, 6)
                    Divider().overlay(Palette.border)
                }
                Button { dismiss(); shell.show(.travel) } label: {
                    Label("More places in Travel", systemImage: "chevron.right").labelStyle(TrailingIcon())
                        .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                        .frame(maxWidth: .infinity).frame(height: 44)
                }
                .buttonStyle(PressStyle())
            }
            .foregroundStyle(Palette.text)
            .padding(16)
            .background(Palette.surface, in: .rect(cornerRadius: 26))
            .shadow(color: Color(hex: 0x19140C, opacity: 0.2), radius: 14, y: 8)
            .padding(10)
        }
        .overlay(alignment: .topTrailing) {
            Button { dismiss() } label: {
                Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
                    .frame(width: 44, height: 44)
            }
            .buttonStyle(IconTileStyle(fill: Palette.surface, size: 44))
            .padding(16)
            .accessibilityLabel("Close")
        }
        .presentationDetents([.large])
    }
}

struct AddPlan: View {
    let date: String
    @State private var open = false

    var body: some View {
        Button { open = true } label: {
            Label("Add a plan", systemImage: "plus")
                .labelStyle(Tight())
                .font(.geist(15.5, .semibold)).foregroundStyle(Palette.action)
                .frame(height: 50).contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .sheet(isPresented: $open) { AddPlanSheet(date: date) }
    }
}

/// Plans marked done today, folded away; each can be put back.
struct DoneFold: View {
    let page: TodayPage
    let done: [TodayPage.Plan]
    @Binding var open: Bool
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Button { withAnimation(.snappy) { open.toggle() } } label: {
                HStack(spacing: 6) {
                    Image(systemName: "checkmark").font(.system(size: 12, weight: .bold))
                    Text("\(done.count) done").font(.geist(13.5, .semibold))
                    Image(systemName: "chevron.down").font(.system(size: 11, weight: .semibold)).rotationEffect(.degrees(open ? 180 : 0))
                }
                .foregroundStyle(Palette.textSoft)
                .frame(minHeight: 32)
            }
            .buttonStyle(PressStyle())
            if open {
                ForEach(done) { plan in
                    HStack(spacing: 8) {
                        Text([plan.start, plan.title].compactMap { $0 }.joined(separator: " ")).font(.geist(14, .semibold)).strikethrough()
                            .foregroundStyle(Palette.textSoft)
                        Spacer(minLength: 0)
                        Button("Restore") { Task { await model.restore(session.api, plan: plan, date: page.date) } }
                            .font(.geist(13, .semibold)).foregroundStyle(TodayLink.ink)
                    }
                    .transition(.opacity.combined(with: .move(edge: .top)))
                }
            }
        }
        .padding(.bottom, 10)
    }
}

/// A plan of their own (the canvas's Add a plan): What, Where (optional) and At, then Add to today.
/// Opened from a plan's menu it edits that plan instead.
struct AddPlanSheet: View {
    let date: String
    var editing: TodayPage.Plan?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var place = ""
    @State private var at = Calendar.current.date(bySettingHour: 16, minute: 0, second: 0, of: .now) ?? .now
    @State private var picking = false
    @State private var saving = false
    @FocusState private var focus: Field?

    enum Field { case what, place }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text(editing == nil ? "Add a plan" : "Edit plan").font(.posterGrotesque(26)).tracking(-0.3)
                Spacer()
                Button { dismiss() } label: {
                    Image(systemName: "xmark").font(.system(size: 16, weight: .semibold))
                        .frame(width: 44, height: 44)
                }
                .buttonStyle(IconTileStyle(fill: Palette.surface, size: 44))
                .accessibilityLabel("Close")
            }
            .foregroundStyle(Palette.text)
            .padding(.bottom, 6)
            field("What?") {
                TextField("e.g. Doctor", text: $name).focused($focus, equals: .what).submitLabel(.next)
                    .onSubmit { focus = .place }
            }
            field("Where? Optional") {
                TextField("A place or address", text: $place).focused($focus, equals: .place).submitLabel(.done)
            }
            Button { focus = nil; withAnimation(Motion.ios(0.35)) { picking.toggle() } } label: {
                field("At") { Text(TodayPage.clock(at)).monospacedDigit().frame(maxWidth: .infinity, alignment: .leading) }
            }
            .buttonStyle(.plain)
            if picking {
                DatePicker("At", selection: $at, displayedComponents: .hourAndMinute)
                    .datePickerStyle(.wheel).labelsHidden()
                    .frame(maxWidth: .infinity)
                    .transition(.opacity.combined(with: .offset(y: -6)))
            }
            Button(action: save) {
                Group {
                    if saving { ProgressView().tint(.white) } else { Text(editing == nil ? "Add to today" : "Save") }
                }
                .font(.geist(16, .semibold))
                .foregroundStyle(ready ? .white : Palette.textSoft)
                .frame(maxWidth: .infinity).frame(height: 52)
                .buttonFace(ready ? Palette.action : Palette.quiet)
            }
            .buttonStyle(PressStyle())
            .disabled(!ready || saving)
            .padding(.top, 6)
            .animation(.snappy, value: ready)
        }
        .padding(20)
        .background(Palette.canvas)
        .presentationDetents(picking ? [.large] : [.height(430)])
        .presentationDragIndicator(.visible)
        .presentationBackground(Palette.canvas)
        .onAppear {
            if let editing {
                name = editing.title
                place = editing.place ?? ""
                if let start = editing.start, let time = Self.time(start) { at = time }
            } else {
                focus = .what
            }
        }
    }

    private var ready: Bool { !name.trimmingCharacters(in: .whitespaces).isEmpty }

    /// The canvas's .fld2: a small label over the value, on the card with its hairline.
    private func field(_ label: String, @ViewBuilder value: () -> some View) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(label).font(.geist(12)).foregroundStyle(Palette.textSoft)
            value().font(.geist(16, .medium)).foregroundStyle(Palette.text)
        }
        .padding(.vertical, 9).padding(.horizontal, 12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Palette.surface, in: .rect(cornerRadius: 14))
        .overlay { RoundedRectangle(cornerRadius: 14).strokeBorder(Palette.border) }
    }

    private func save() {
        saving = true
        let start = TodayPage.clock(at)
        let end = TodayPage.clock(at.addingTimeInterval(TimeInterval((editing.flatMap(Self.length) ?? 60) * 60)))
        Task {
            let done: Bool
            if let editing {
                done = await model.editPlan(session.api, plan: editing, date: date, name: name, start: start, end: end, place: place)
            } else {
                done = await model.addPlan(session.api, name: name, date: date, start: start, end: end, place: place)
            }
            saving = false
            if done { dismiss() }
        }
    }

    private static func time(_ clock: String) -> Date? {
        let parts = clock.split(separator: ":").compactMap { Int($0) }
        guard parts.count == 2 else { return nil }
        return Calendar.current.date(bySettingHour: parts[0], minute: parts[1], second: 0, of: .now)
    }

    /// How long the plan runs, so moving it keeps its length.
    private static func length(_ plan: TodayPage.Plan) -> Int? {
        guard let start = plan.start, let end = plan.end, let a = time(start), let b = time(end) else { return nil }
        let minutes = Int(b.timeIntervalSince(a) / 60)
        return minutes > 0 ? minutes : minutes + 24 * 60
    }
}

/// Add an event or a place to a day's plan, in place: the + turns to a check once it's in.
struct AddButton: View {
    let candidate: String
    let date: String
    let title: String
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        let added = model.added.contains(candidate)
        Button { Task { await model.change(session.api, action: "add", date: date, candidate: candidate, symbol: "calendar.badge.plus") } } label: {
            Image(systemName: added ? "checkmark" : "plus")
                .font(.system(size: 16, weight: .semibold))
                .foregroundStyle(added ? Motion.green : Palette.action)
                .contentTransition(.symbolEffect(.replace))
        }
        .buttonStyle(IconTileStyle(fill: Palette.surface, tint: Motion.green.opacity(added ? 0.14 : 0), size: 38))
        .animation(.easeInOut(duration: 0.35), value: added)
        .disabled(added || candidate.isEmpty)
        .accessibilityLabel(added ? "\(title) is in your plan" : "Add \(title) to your plan")
        .sensoryFeedback(.success, trigger: added)
    }
}
