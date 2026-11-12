import API
import Core
import DesignSystem
import SwiftUI

/// One card of an answer, drawn by its type. A card's own words show only when the answer has
/// none of its own (`showText`), as on the web.
struct ComposerCard: View {
    let block: ComposerBlock
    let showText: Bool
    let question: String?

    var body: some View {
        switch block {
        case let .place(place): WhereCard(place: place)
        case let .departures(station): DeparturesCard(station: station)
        case let .trip(trip): TripCard(trip: trip, showText: showText)
        case let .choices(text): if showText { Words(text: text) }
        case let .events(events): EventsCard(events: events)
        case let .places(places): ComposerPlacesCard(places: places)
        case let .checklist(checklist): ChecklistCard(checklist: checklist, showText: showText)
        case let .note(text): Words(text: text)
        case let .facts(facts): FactsCard(facts: facts)
        case .guidance: EmptyView()
        case let .done(done): DoneCard(done: done)
        case let .undone(_, text): QuietLine(symbol: "arrow.uturn.backward", text: text)
        case let .proposal(proposal): ProposalCard(proposal: proposal)
        case let .record(record): RecordCard(record: record)
        case let .planChanges(changes): PlanChangesCard(changes: changes)
        case let .handoff(handoff): HandoffCard(handoff: handoff)
        case let .permission(permission): PermissionCard(permission: permission, question: question)
        }
    }
}

private struct Words: View {
    let text: String

    var body: some View {
        Text(text).font(.geist(16)).foregroundStyle(Palette.text).lineSpacing(3)
            .fixedSize(horizontal: false, vertical: true)
    }
}

private struct QuietLine: View {
    let symbol: String
    let text: String

    var body: some View {
        Label(text, systemImage: symbol).labelStyle(Tight())
            .font(.geist(14.5, .semibold)).foregroundStyle(Palette.textSoft)
    }
}

/// A card's surface, as the canvas's .ac.
private extension View {
    func answerCard() -> some View {
        padding(14).frame(maxWidth: .infinity, alignment: .leading).card(radius: 22)
    }
}

// MARK: - Where, departures, the way

private struct WhereCard: View {
    let place: ComposerBlock.Where

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let area = place.area {
                row(place.basisWords, area, symbol: "location.fill")
            }
            if let stop = place.stop {
                if place.area != nil { PaperworkDivider() }
                row("Nearest stop", "\(stop.name) · \(stop.walkMin) min walk" + (stop.lines.isEmpty ? "" : " · \(stop.lines.joined(separator: ", "))"), symbol: "tram.fill")
            }
        }
        .card(radius: 18)
    }

    private func row(_ label: String, _ value: String, symbol: String) -> some View {
        HStack(spacing: 12) {
            Image(systemName: symbol).font(.system(size: 13, weight: .semibold)).foregroundStyle(TodayLink.ink)
                .frame(width: 30, height: 30).background(Palette.selectBackground, in: .rect(cornerRadius: 9, style: .continuous))
            VStack(alignment: .leading, spacing: 1) {
                Text(label).font(.geist(12.5, .semibold)).foregroundStyle(Palette.textSoft)
                Text(value).font(.geist(15, .semibold)).foregroundStyle(Palette.text)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 14).padding(.vertical, 10)
    }
}

/// A stop's next departures, as Travel's own rows.
private struct DeparturesCard: View {
    let station: ComposerBlock.Station
    @Environment(Session.self) private var session
    /// The stop's board read again while the card is in sight: the same live times as Travel's board.
    @State private var live: Station?

    var body: some View {
        TimelineView(.periodic(from: .now, by: 15)) { timeline in
            let rows = rows(at: timeline.date)
            VStack(alignment: .leading, spacing: 0) {
                HStack {
                    Label(station.name, systemImage: "tram.fill").labelStyle(Tight()).font(.geist(16, .bold)).foregroundStyle(Palette.text)
                    Spacer()
                    Text(basis).font(.geist(12.5, basis == "Live" ? .bold : .semibold))
                        .foregroundStyle(basis == "Live" ? Color(hex: 0x17603A) : Palette.textSoft)
                }
                .padding(.horizontal, 14).padding(.vertical, 12)
                ForEach(rows, id: \.id) { row in
                    PaperworkDivider()
                    HStack(spacing: 12) {
                        LineBadge(line: row.line, color: row.color)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(row.dest).font(.geist(15.5, .semibold)).foregroundStyle(row.cancelled ? Palette.textSoft : Palette.text)
                                .strikethrough(row.cancelled)
                            if let via = row.via { Text("via \(via)").font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1) }
                        }
                        Spacer(minLength: 4)
                        VStack(alignment: .trailing, spacing: 1) {
                            Text(row.minutes <= 0 ? "now" : row.minutes >= 60 ? row.time : "\(row.minutes) min").font(.posterGrotesque(19)).monospacedDigit().foregroundStyle(Palette.text)
                                .contentTransition(.numericText(countsDown: true))
                            // Late as Travel's board says it: from 2 min.
                            if row.delay >= 2 { Text("+\(row.delay) late").font(.geist(12, .bold)).foregroundStyle(Palette.conflictText) }
                        }
                    }
                    .padding(.horizontal, 14).padding(.vertical, 9)
                }
            }
            .animation(.smooth(duration: 0.3), value: rows.map(\.id))
        }
        .card(radius: 22)
        .task {
            // Live while it's on screen, as the board is: read again every 30 s.
            while !Task.isCancelled {
                if let reply = try? await session.api.station(named: station.name), let fresh = reply.station { live = fresh }
                try? await Task.sleep(for: .seconds(30))
            }
        }
    }

    private var basis: String {
        if let live { return live.timeBasis == .realtime && !live.isStale ? "Live" : live.timeBasis == .scheduled ? "Timetable" : "Not live" }
        return station.timeBasis == "realtime" ? "Live" : station.timeBasis == "scheduled" ? "Timetable" : ""
    }

    private struct Row {
        let id: String
        let line: String
        let color: String?
        let dest: String
        let via: String?
        let minutes: Int
        let time: String
        let delay: Int
        let cancelled: Bool
    }

    /// The next run each way that hasn't left, soonest first, counted from now (never the minutes the answer was written with).
    private func rows(at now: Date) -> [Row] {
        if let live {
            return live.lines.flatMap { line in
                line.dirs.compactMap { dir -> Row? in
                    guard let run = dir.runs.first(where: { ($0.date ?? .distantFuture) >= now.addingTimeInterval(-30) }) else { return nil }
                    return Row(id: dir.id, line: line.line, color: line.color, dest: dir.dest, via: dir.via.prefix(2).joined(separator: " · "),
                               minutes: run.minutes(from: now), time: run.time, delay: run.delay ?? 0, cancelled: run.cancelled || dir.cancelled)
                }
            }
            .sorted { $0.minutes < $1.minutes }
        }
        return station.lines.flatMap { line in
            line.dirs.compactMap { dir -> Row? in
                let runs = [dir.next].compactMap { $0 } + (dir.later ?? [])
                guard let run = runs.first(where: { ($0.date ?? .distantFuture) >= now.addingTimeInterval(-30) }) else { return nil }
                let minutes = run.date.map { max(0, Int(($0.timeIntervalSince(now) / 60).rounded(.down))) } ?? run.minutes
                return Row(id: dir.id, line: line.line, color: line.color, dest: dir.dest, via: dir.via?.prefix(2).joined(separator: " · "),
                           minutes: minutes, time: run.time, delay: run.delay ?? 0, cancelled: run.cancelled ?? dir.cancelled ?? false)
            }
        }
        .sorted { $0.minutes < $1.minutes }
    }
}

/// The way somewhere, as a ticket (the canvas's .tk): the line, when to leave and arrive, its
/// steps, and I'm on my way or the map.
private struct TripCard: View {
    let trip: ComposerBlock.Trip
    let showText: Bool
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(ComposerModel.self) private var model
    @State private var open = false
    @State private var map = false
    @Namespace private var mapSpace
    @State private var starting = false
    @State private var problem: String?

    var body: some View {
        let journey = trip.trip.journey
        let ride = journey.legs.first { $0.mode != "walk" && $0.mode != "bike" }
        let legs = Self.legs(journey)
        VStack(alignment: .leading, spacing: 10) {
            if let major = trip.major {
                VStack(alignment: .leading, spacing: 4) {
                    Label(major.title, systemImage: "exclamationmark.triangle.fill").labelStyle(Tight())
                        .font(.geist(15, .bold)).foregroundStyle(Palette.warnText)
                    if let text = major.text { Text(text).font(.geist(14)).foregroundStyle(Palette.text) }
                    if let todo = major.todo { Text(todo).font(.geist(14, .semibold)).foregroundStyle(Palette.text) }
                }
                .padding(12).frame(maxWidth: .infinity, alignment: .leading)
                .background(Palette.warnBackground, in: .rect(cornerRadius: 14, style: .continuous))
            }
            if showText { Words(text: trip.text) }
            VStack(alignment: .leading, spacing: 0) {
                VStack(alignment: .leading, spacing: 10) {
                    HStack(spacing: 8) {
                        if let ride, let line = ride.line {
                            LineBadge(line: line, color: ride.color, height: 30)
                            Text("at \(ride.departTime) from \(ride.from.name)").font(.geist(15, .semibold)).foregroundStyle(Palette.text).lineLimit(1)
                        } else {
                            Label(journey.mode == "bike" ? "By bike" : "On foot", systemImage: journey.mode == "bike" ? "bicycle" : "figure.walk")
                                .labelStyle(Tight()).font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                        }
                        Spacer(minLength: 0)
                        Text("\(journey.durationMin) min").font(.geist(13, .bold)).foregroundStyle(Palette.text)
                            .padding(.horizontal, 9).frame(height: 26).background(Palette.quiet, in: .rect(cornerRadius: 8, style: .continuous))
                    }
                    HStack(alignment: .lastTextBaseline, spacing: 10) {
                        VStack(alignment: .leading, spacing: 0) {
                            Text("Leave").font(.geist(12.5, .semibold)).foregroundStyle(Palette.textSoft)
                            Text(journey.departTime).font(.posterGrotesque(34)).monospacedDigit()
                        }
                        Image(systemName: "arrow.right").font(.system(size: 18, weight: .bold)).foregroundStyle(Palette.textSoft)
                        VStack(alignment: .leading, spacing: 0) {
                            Text("There").font(.geist(12.5, .semibold)).foregroundStyle(Palette.textSoft)
                            Text(journey.arriveTime).font(.posterGrotesque(34)).monospacedDigit()
                        }
                    }
                    .foregroundStyle(Palette.text)
                    HStack(spacing: 12) {
                        Label(journey.transfers == 0 ? "No change" : journey.transfers == 1 ? "1 change" : "\(journey.transfers) changes", systemImage: "checkmark")
                        let walk = journey.legs.filter { $0.mode == "walk" }.reduce(0) { $0 + $1.durationMin }
                        if walk > 0 { Label("\(walk) min walking", systemImage: "figure.walk") }
                    }
                    .labelStyle(Tight()).font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                }
                .padding(14)
                Rectangle().fill(Palette.border).frame(height: 1)
                if open {
                    StepList(steps: TodayPage.Step.of(legs, to: trip.trip.for.place, start: trip.trip.for.start), legs: legs)
                        .padding(14)
                        .transition(.opacity)
                }
                HStack(spacing: 8) {
                    if trip.trip.link != "trip" {
                        Button { Task { await start(journey) } } label: {
                            HStack(spacing: 7) {
                                if starting { ProgressView().tint(.white) } else { Image(systemName: "figure.walk") }
                                Text("Start trip")
                            }
                            .font(.geist(15, .semibold)).foregroundStyle(.white)
                            .frame(maxWidth: .infinity).frame(height: 42)
                            .buttonFace(Palette.action)
                        }
                        .buttonStyle(PressStyle()).disabled(starting)
                    }
                    Button { map = true } label: {
                        Label("Map", systemImage: "map").labelStyle(Tight())
                            .font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                            .padding(.horizontal, 16).frame(height: 42).buttonFace(Palette.quiet)
                    }
                    .buttonStyle(PressStyle())
                    .matchedTransitionSource(id: "map", in: mapSpace)
                    Button { withAnimation(Motion.ios(0.3)) { open.toggle() } } label: {
                        Label(open ? "Hide steps" : "Steps", systemImage: "list.bullet").labelStyle(Tight())
                            .font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                            .padding(.horizontal, 14).frame(height: 42).buttonFace(Palette.quiet)
                    }
                    .buttonStyle(PressStyle())
                }
                .padding(12)
                if let problem {
                    Text(problem).font(.geist(13.5, .semibold)).foregroundStyle(Palette.conflictText).padding(.horizontal, 14).padding(.bottom, 12)
                }
            }
            .card(radius: 22)
            Text(trip.trip.basis == "live" ? "Times from KVB, live where the feed has them." : "Timetable times; live times show on the day.")
                .font(.geist(12.5)).foregroundStyle(Palette.textSoft)
        }
        .fullScreenCover(isPresented: $map) {
            // The map grows out of the button that opened it.
            WayMap(to: trip.trip.for.place, legs: legs).navigationTransition(.zoom(sourceID: "map", in: mapSpace))
        }
    }

    /// I'm on my way: the way there again from where the phone is, and the first one starts. Today
    /// shows it live after.
    private func start(_ journey: ComposerBlock.Journey) async {
        guard let end = journey.legs.last?.to else { return }
        starting = true
        defer { starting = false }
        do throws(APIFailure) {
            let plan = try await session.api.planWay(to: trip.trip.for.place, latitude: end.lat, longitude: end.lng,
                                                     fromLatitude: today.place?.latitude, fromLongitude: today.place?.longitude, startsAt: nil)
            guard !plan.ways.isEmpty else { problem = "No way there right now. Try again in a moment."; return }
            let (running, why) = await today.startWay(session.api, plan: plan, way: 0, replace: false)
            if let why { problem = why } else if running { problem = "You’re already on your way somewhere else. End that trip on Today first." } else {
                model.say("You’re on your way. Today shows it live.")
            }
        } catch {
            problem = ComposerModel.words(error)
        }
    }

    /// The journey as the app's own legs, for its step list and map.
    static func legs(_ journey: ComposerBlock.Journey) -> [TodayPage.Trip.Leg] {
        journey.legs.compactMap { leg in
            guard let depart = TodayPage.parse(leg.departAt), let arrive = TodayPage.parse(leg.arriveAt) else { return nil }
            return TodayPage.Trip.Leg(mode: leg.mode, line: leg.line, color: leg.color, headsign: leg.headsign, from: leg.from.name, to: leg.to.name,
                                      departAt: depart, arriveAt: arrive, departTime: leg.departTime, arriveTime: leg.arriveTime,
                                      minutes: leg.durationMin, stops: leg.stops, polyline: leg.polyline)
        }
    }
}

// MARK: - Events and places

/// Listings as Events' own posters, each with Save and Add to plan.
private struct EventsCard: View {
    let events: [ComposerBlock.Event]
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @State private var saved: Set<Int> = []

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            ScrollView(.horizontal) {
                HStack(alignment: .top, spacing: 12) {
                    ForEach(Array(events.enumerated()), id: \.element.id) { index, event in
                        poster(event, tilt: index.isMultiple(of: 2) ? -1.2 : 1)
                    }
                }
                .padding(.vertical, 8).padding(.horizontal, 2)
            }
            .scrollIndicators(.hidden)
            .scrollClipDisabled()
            Text("From Stadt Köln listings. Adding one isn’t a booking.").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
        }
    }

    private func poster(_ event: ComposerBlock.Event, tilt: Double) -> some View {
        let start = TodayPage.parse(event.occurrenceStart)
        let isSaved = saved.contains(event.id)
        return VStack(alignment: .leading, spacing: 8) {
            Label(event.category.capitalized, systemImage: "calendar").labelStyle(Tight())
                .font(.geist(12.5, .bold)).foregroundStyle(WhatsOnLook.indigo)
            Text(event.title).font(.posterGrotesque(22)).foregroundStyle(WhatsOnLook.indigo).lineLimit(4)
            Spacer(minLength: 0)
            Text([start.map { "\(TodayPage.shortDay(event.date)) \(TodayPage.clock($0))" } ?? event.recurrenceText, event.venue.name, event.priceText].compactMap { $0 }.joined(separator: " · "))
                .font(.geist(13, .semibold)).foregroundStyle(WhatsOnLook.indigo.opacity(0.8)).lineLimit(3)
            HStack(spacing: 6) {
                if event.plannable {
                    let key = "add-\(event.candidate)"
                    Button { Task { await model.add(event.candidate, date: event.date, api: session.api) } } label: {
                        HStack(spacing: 5) {
                            if model.busy == key { ProgressView().controlSize(.small) } else { Image(systemName: "plus") }
                            Text("Add to plan")
                        }
                        .font(.geist(14, .semibold)).foregroundStyle(.white)
                        .padding(.horizontal, 12).frame(height: 36).buttonFace(WhatsOnLook.indigo)
                    }
                    .buttonStyle(PressStyle())
                }
                Button { Task { await save(event, !isSaved) } } label: {
                    FillingBookmark(filled: isSaved, size: 16, weight: .semibold).foregroundStyle(WhatsOnLook.indigo)
                        .frame(width: 36, height: 36)
                }
                .buttonStyle(IconTileStyle(fill: .white.opacity(0.6), size: 36))
                .accessibilityLabel(isSaved ? "Saved: \(event.title)" : "Save \(event.title)")
            }
        }
        .padding(14)
        .frame(width: 214, height: 278, alignment: .topLeading)
        .background(WhatsOnLook.yellow, in: .rect(cornerRadius: 6, style: .continuous))
        .shadow(color: Color(hex: 0x19140C, opacity: 0.35), radius: 10, y: 8)
        .rotationEffect(.degrees(tilt))
    }

    private func save(_ event: ComposerBlock.Event, _ on: Bool) async {
        guard let start = TodayPage.parse(event.occurrenceStart) else { return }
        withAnimation(.easeInOut(duration: 0.45)) { if on { saved.insert(event.id) } else { saved.remove(event.id) } }
        do throws(APIFailure) {
            _ = try await session.api.saveEvent(id: event.id, startsAt: start, saved: on)
            model.say(on ? "Saved for later." : "Removed from saved.")
        } catch {
            withAnimation { if on { saved.remove(event.id) } else { saved.insert(event.id) } }
            if case let .refused(message) = error { model.say(message, problem: true) } else { model.say(ComposerModel.words(error), problem: true) }
        }
    }
}

/// Places as small cards: name, what it is, how far, its hours, why it's suggested.
// MARK: - Paperwork

private struct ChecklistCard: View {
    let checklist: ComposerBlock.Checklist
    let showText: Bool
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if showText { Words(text: checklist.text) }
            VStack(alignment: .leading, spacing: 9) {
                Text(checklist.title).font(.geist(18, .bold)).foregroundStyle(Palette.text)
                HStack(spacing: 4) {
                    ForEach(0..<max(checklist.record.total, 1), id: \.self) { index in
                        RoundedRectangle(cornerRadius: 2, style: .continuous)
                            .fill(index < checklist.record.prepared ? PaperworkLook.purple : PaperworkLook.purple.opacity(0.18)).frame(height: 6)
                    }
                }
                Text("\(checklist.record.prepared) of \(checklist.record.total) ready").font(.geist(14)).foregroundStyle(Palette.textSoft)
                ForEach(checklist.record.documents, id: \.self) { document in
                    Label(document.label, systemImage: document.ready ? "checkmark.circle.fill" : "circle").labelStyle(Tight())
                        .font(.geist(14.5)).foregroundStyle(document.ready ? PaperworkLook.purpleInk : Palette.text)
                }
                if let facts = checklist.facts, !facts.isEmpty { FactsCard(facts: facts) }
                Button { shell.closeComposer(); shell.show(.paperwork) } label: {
                    Label("Open Paperwork", systemImage: "arrow.right").labelStyle(TrailingIcon())
                        .font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 40)
                }
                .buttonStyle(PressStyle())
            }
            .answerCard()
            Text("From your own record. Nothing is sent to any office.").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
        }
    }
}

private struct FactsCard: View {
    let facts: [ComposerFact]

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ForEach(Array(facts.enumerated()), id: \.offset) { index, fact in
                if index > 0 { PaperworkDivider() }
                HStack(alignment: .firstTextBaseline) {
                    Text(fact.label).font(.geist(14)).foregroundStyle(Palette.textSoft)
                    Spacer(minLength: 8)
                    Text(fact.value).font(.geist(14.5, .semibold)).foregroundStyle(fact.known ? Palette.text : Palette.textSoft).multilineTextAlignment(.trailing)
                }
                .padding(.horizontal, 14).padding(.vertical, 10)
            }
        }
        .card(radius: 18)
    }
}

/// A change Composer made: a green line with its Undo, and the day as it is now.
private struct DoneCard: View {
    let done: ComposerBlock.Done
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 9) {
                Image(systemName: "checkmark").font(.system(size: 14, weight: .bold))
                VStack(alignment: .leading, spacing: 1) {
                    Text(done.text).font(.geist(14.5, .semibold))
                    if let sub = done.sub { Text(sub).font(.geist(13)).opacity(0.85) }
                }
                Spacer(minLength: 4)
                if let undo = done.undo, Self.stillOpen(undo.until) {
                    Button("Undo") { Task { await model.undo(undo.token, api: session.api) } }
                        .font(.geist(14.5, .bold)).underline()
                        .disabled(model.busy == "undo-\(undo.token)")
                }
            }
            .foregroundStyle(Color(light: 0x17603A, dark: 0x86E2AA))
            .padding(.horizontal, 12).padding(.vertical, 11)
            .background(Color(light: 0xE4F4E8, dark: 0x1F3A28), in: .rect(cornerRadius: 16, style: .continuous))
            if let day = done.day, !day.isEmpty {
                VStack(alignment: .leading, spacing: 6) {
                    ForEach(day, id: \.self) { row in
                        HStack(spacing: 10) {
                            Text(row.time).font(.geist(14, .bold)).monospacedDigit().frame(width: 46, alignment: .leading)
                            Text(row.what).font(.geist(14.5, row.new ? .semibold : .regular))
                            if row.new { Text("New").font(.geist(11.5, .bold)).foregroundStyle(Color(hex: 0x17603A)) }
                        }
                        .foregroundStyle(Palette.text)
                    }
                }
                .answerCard()
            }
        }
    }

    static func stillOpen(_ until: String) -> Bool {
        TodayPage.parse(until).map { $0 > .now } ?? true
    }
}

/// A change Composer proposes: from → to, and Apply.
private struct ProposalCard: View {
    let proposal: ComposerBlock.Proposal
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session

    var body: some View {
        VStack(alignment: .leading, spacing: 9) {
            Text(proposal.title).font(.geist(16, .bold)).foregroundStyle(Palette.text)
            if proposal.from != nil || proposal.to != nil {
                HStack(spacing: 8) {
                    if let from = proposal.from { Text(from).strikethrough().foregroundStyle(Palette.textSoft) }
                    Image(systemName: "arrow.right").font(.system(size: 13, weight: .bold)).foregroundStyle(Palette.textSoft)
                    if let to = proposal.to { Text(to).fontWeight(.semibold).foregroundStyle(Palette.text) }
                }
                .font(.geist(15))
            }
            if let sub = proposal.sub { Text(sub).font(.geist(14)).foregroundStyle(Palette.textSoft) }
            if let token = proposal.token {
                PaperworkButton(title: "Apply", small: true, working: model.busy == "apply-\(token)") {
                    Task { await model.apply(token, api: session.api) }
                }
            }
        }
        .answerCard()
    }
}

/// Paperwork's rows as recorded; changed in Paperwork.
private struct RecordCard: View {
    let record: ComposerBlock.Record
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let title = record.title {
                Text(title).font(.geist(16, .bold)).foregroundStyle(Palette.text).padding(14)
            }
            ForEach(record.rows, id: \.self) { row in
                PaperworkDivider()
                VStack(alignment: .leading, spacing: 2) {
                    Text(row.label).font(.geist(12.5, .semibold)).foregroundStyle(Palette.textSoft)
                    Text(row.value).font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                    if let note = row.note { Text(note).font(.geist(13)).foregroundStyle(Palette.textSoft) }
                }
                .padding(.horizontal, 14).padding(.vertical, 10)
            }
            PaperworkDivider()
            Button { shell.closeComposer(); shell.show(.paperwork) } label: {
                Label(record.link?.label ?? "Open Paperwork", systemImage: "arrow.right").labelStyle(TrailingIcon())
                    .font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 44)
            }
            .buttonStyle(PressStyle()).padding(.horizontal, 14)
        }
        .card(radius: 22)
    }
}

private struct PlanChangesCard: View {
    let changes: ComposerBlock.PlanChanges
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell

    var body: some View {
        VStack(alignment: .leading, spacing: 9) {
            Text("What changed in your plan").font(.geist(17, .bold)).foregroundStyle(Palette.text)
            ForEach(changes.changes, id: \.self) { change in
                HStack(alignment: .firstTextBaseline, spacing: 8) {
                    Text(Self.word(change.change)).font(.geist(12, .bold)).foregroundStyle(TodayLink.ink)
                        .padding(.horizontal, 7).padding(.vertical, 2)
                        .background(Palette.selectBackground, in: .rect(cornerRadius: 6, style: .continuous))
                    Text(change.title ?? "A task").font(.geist(14.5)).foregroundStyle(Palette.text)
                }
            }
            Button { shell.closeComposer(); shell.show(.paperwork) } label: {
                Label(changes.link?.label ?? "Open your record", systemImage: "arrow.right").labelStyle(TrailingIcon())
                    .font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 40)
            }
            .buttonStyle(PressStyle())
        }
        .answerCard()
    }

    static func word(_ change: String) -> String {
        switch change {
        case "added": "New task"
        case "removed": "No longer needed"
        case "due_moved": "Date moved"
        case "step_added": "New step"
        case "step_removed": "Step gone"
        default: "Changed"
        }
    }
}

/// Where they do it themselves: the place in the app, and what they'll find there.
private struct HandoffCard: View {
    let handoff: ComposerBlock.Handoff
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell

    var body: some View {
        VStack(alignment: .leading, spacing: 9) {
            Words(text: handoff.text)
            Text(handoff.place.crumb).font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
            if let description = handoff.place.description { Text(description).font(.geist(14)).foregroundStyle(Palette.text) }
            if let tab = ComposerLinks.tab(handoff.place.href) {
                Button { shell.closeComposer(); shell.show(tab) } label: {
                    Label(handoff.place.label, systemImage: "arrow.right").labelStyle(TrailingIcon())
                        .font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 40)
                }
                .buttonStyle(PressStyle())
            }
            if let note = handoff.note { Text(note).font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
        }
        .answerCard()
    }
}

/// May Composer read their Paperwork record? Allow is remembered until they turn it off in
/// Composer's settings; the question is then asked again with it.
private struct PermissionCard: View {
    let permission: ComposerBlock.Permission
    let question: String?
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @State private var sends = false
    @State private var declined = false

    var body: some View {
        if declined {
            QuietLine(symbol: "hand.raised", text: "Not now. Composer answers without your record.")
        } else {
            VStack(alignment: .leading, spacing: 9) {
                Text(permission.title).font(.geist(18, .bold)).foregroundStyle(Palette.text)
                if let sub = permission.sub { Text(sub).font(.geist(14)).foregroundStyle(Palette.textSoft) }
                Button { withAnimation(Motion.ios(0.3)) { sends.toggle() } } label: {
                    HStack(spacing: 6) {
                        Text("What Composer sends")
                        Image(systemName: "chevron.down").font(.system(size: 12, weight: .bold)).rotationEffect(.degrees(sends ? 180 : 0))
                    }
                    .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 36)
                }
                .buttonStyle(.plain)
                if sends {
                    VStack(alignment: .leading, spacing: 6) {
                        ForEach(permission.disclosure.notice ?? [], id: \.self) { line in
                            Text(line).font(.geist(13.5)).foregroundStyle(Palette.text)
                        }
                        if let processor = permission.disclosure.processorName { Text("Sent to \(processor).").font(.geist(13)).foregroundStyle(Palette.textSoft) }
                    }
                    .transition(.opacity)
                }
                HStack(spacing: 8) {
                    PaperworkButton(title: "Allow", small: true, grow: true, working: model.busy == "permission") {
                        guard let question else { return }
                        Task { await model.allowPaperwork(permission, question: question, api: session.api, place: today.place, label: nil) }
                    }
                    PaperworkButton(title: "Not now", quiet: true, small: true, grow: true) { withAnimation(Motion.ios(0.3)) { declined = true } }
                }
            }
            .answerCard()
        }
    }
}
