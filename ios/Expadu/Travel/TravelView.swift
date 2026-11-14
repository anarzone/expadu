import API
import Core
import DesignSystem
import SwiftUI

/// Travel, as the canvas has it (Main.dc.html): the board in its line's colour to the top. The
/// station's name (tap: Find a stop), the map, a rail of its lines; your next trip and KVB's big
/// notice; then every departure as a band in its own colour with the minutes on split flaps. A band
/// opens its departure in a sheet; "Where to?" plans a trip.
struct TravelView: View {
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.sectionBarCompact) private var barCompact
    @Environment(\.sectionBarTop) private var barTop
    @State private var rowBottom: CGFloat = 0
    @State private var model = TravelModel()
    @Environment(TripPlanner.self) private var planner

    var body: some View {
        let lead = model.lead
        ZStack(alignment: .top) {
            lead.deep.ignoresSafeArea()
            // The open line's number, huge and faint behind the board.
            if let line = model.open?.line ?? model.line, !model.map {
                Text(line)
                    .font(.posterGrotesque(400)).tracking(-20)
                    .foregroundStyle(lead.ink.opacity(0.1))
                    .lineLimit(1).fixedSize()
                    .offset(x: -26, y: 70)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .allowsHitTesting(false)
                    .transition(.opacity)
            }
            if model.map {
                TravelMap(model: model)
                    .ignoresSafeArea()
                    .transition(.opacity)
            }
            VStack(alignment: .leading, spacing: 0) {
                header
                content
            }
        }
        .foregroundStyle(model.map ? Palette.text : lead.ink)
        .animation(reduceMotion ? nil : .easeInOut(duration: 0.55), value: model.leadLine?.line)
        // Where to? sits in the bottom row, between the folded section bar and Composer's button; when
        // the bar opens it moves up and stretches over the bar, full width, as iOS 26's own bottom
        // accessory does (Music, Podcasts), so the search is never hidden by the menu.
        .overlay(alignment: .bottom) {
            ZStack(alignment: .bottom) {
                // Where this row ends on the screen, to set Where to? over the open bar by the bar's own top.
                Color.clear.frame(height: 1)
                    .onGeometryChange(for: CGFloat.self) { $0.frame(in: .global).maxY } action: { rowBottom = $0 }
                WhereTo(open: { planner.open(from: model.station, favourite: model.favourite) },
                        speak: { planner.open(from: model.station, favourite: model.favourite, listening: true) })
                    .padding(.leading, barCompact ? 16 + 56 + 10 : 16).padding(.trailing, barCompact ? 16 + 62 + 10 : 16)
                    // Folded: where it has always sat. Open: 10 pt over the bar's top, wherever that is.
                    .padding(.bottom, barCompact || barTop == 0 ? 28 : max(28, rowBottom - barTop + 10))
            }
            .animation(reduceMotion ? nil : .spring(duration: 0.4, bounce: 0.2), value: barCompact)
            .ignoresSafeArea(edges: .bottom)
        }
        .task { await model.start(session.api) }
        .onDisappear { model.stop() }
        .sheet(isPresented: Binding(get: { model.open != nil }, set: { if !$0 { withAnimation(.smooth) { model.open = nil } } })) {
            DepartureSheet(model: model)
                .presentationDetents([.medium, .large])
                .presentationBackgroundInteraction(.enabled(upThrough: .medium))
                .presentationBackground(Palette.surface)
                .presentationCornerRadius(32)
        }
        .sheet(isPresented: $model.picker) {
            StopPicker(model: model)
                .presentationDetents([.large])
                .presentationBackground(Palette.surface)
                .presentationCornerRadius(32)
        }
        .overlay {
            if let notice = model.notice {
                NoticePopup(notice: notice, close: { withAnimation(.smooth(duration: 0.3)) { model.notice = nil } })
                    .transition(.opacity.combined(with: .scale(scale: 0.96)))
            }
        }
    }

    // MARK: Header

    private var header: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .center, spacing: 8) {
                Button { model.picker = true } label: {
                    HStack(spacing: 8) {
                        Text(model.station?.name ?? model.loadingName ?? "Nearby")
                            .font(.posterGrotesque((model.station?.name.count ?? 0) > 10 ? 38 : 44))
                            .textCase(.uppercase).tracking(-0.8)
                            .lineLimit(1).minimumScaleFactor(0.55)
                        if model.station != nil { Image(systemName: "chevron.down").font(.system(size: 16, weight: .bold)) }
                    }
                    .contentShape(.rect)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("\(model.station?.name ?? "Nearby"). Find a stop")
                .accessibilityAddTraits(.isHeader)
                Spacer(minLength: 0)
                if model.station != nil {
                    Button { Task { await model.toggleMap(session.api) } } label: {
                        Image(systemName: model.map ? "list.bullet" : "map")
                            .font(.system(size: 16, weight: .semibold))
                            .frame(width: 38, height: 38)
                            .contentTransition(.symbolEffect(.replace))
                    }
                    .buttonStyle(PressStyle())
                    .glassEffect(.clear.interactive(), in: .circle)
                    .accessibilityLabel(model.map ? "Show the board" : "Show lines and stops")
                }
                AccountButton()
            }
            if !model.subLine.isEmpty {
                HStack(spacing: 6) {
                    if model.live { PulseRing(colour: .white, animated: !reduceMotion).frame(width: 8, height: 8).overlay(Circle().fill(.white)) }
                    Text(model.subLine).font(.geist(13, .medium)).lineLimit(1)
                    if model.line == nil, model.station?.lifts?.out.isEmpty == false {
                        Label("Lift out of order", systemImage: "arrow.up.arrow.down.square")
                            .font(.geist(11.5, .bold)).labelStyle(.titleAndIcon)
                            .padding(.horizontal, 7).frame(height: 19)
                            .background(Palette.noticeBackground, in: .rect(cornerRadius: 10))
                            .foregroundStyle(Palette.conflictText)
                    }
                }
                .opacity(0.95)
                .frame(height: 30)
            }
            if let station = model.station, station.lines.count > 1 {
                LineRail(model: model)
                    .padding(.horizontal, -16)
                    .padding(.top, 4)
            }
        }
        .padding(.horizontal, 16)
        .padding(.top, 8)
        .padding(.bottom, 10)
    }

    // MARK: Content

    @ViewBuilder
    private var content: some View {
        switch model.phase {
        case .finding:
            BoardState(title: model.loadingName == nil ? "Finding the closest station…" : "Reading the board…") {
                FlapWord(text: "------------", small: true)
            }
        case .noLocation:
            BoardState(title: "Where are you?") {
                Text("We need a starting point to show the stop closest to you.").font(.geist(16))
                HStack(spacing: 10) {
                    Button { Task { await model.useLocation(session.api) } } label: {
                        Label("Use my location", systemImage: "location").font(.geist(15, .semibold)).padding(.horizontal, 14).frame(height: 44)
                    }
                    .buttonStyle(PressStyle())
                    .glassEffect(.clear.interactive(), in: .rect(cornerRadius: 14))
                    Button { model.picker = true } label: {
                        Label("Find a stop", systemImage: "magnifyingglass").font(.geist(15, .semibold)).padding(.horizontal, 14).frame(height: 44)
                    }
                    .buttonStyle(PressStyle())
                    .glassEffect(.clear.interactive(), in: .rect(cornerRadius: 14))
                }
            }
        case let .problem(text):
            BoardState(title: text) {
                Text(model.signalLost ? "We keep trying by ourselves; the board comes up as soon as we get through." : "Try a stop nearby, or plan the whole way below.").font(.geist(16))
                Button { Task { await model.load(session.api) } } label: {
                    Text("Try again").font(.geist(15, .semibold)).padding(.horizontal, 16).frame(height: 44)
                }
                .buttonStyle(PressStyle())
                .glassEffect(.clear.interactive(), in: .rect(cornerRadius: 14))
            }
        case .ready:
            if !model.map { board }
        }
    }

    private var board: some View {
        ScrollView {
            LazyVStack(spacing: 0) {
                if model.line == nil, let leave = today.page?.leave, leave.leaveTime != nil, today.page?.trip == nil {
                    LeaveRow(leave: leave, dim: model.open != nil) { planner.leaveOpen = true }
                } else if model.line == nil, let trip = today.page?.trip {
                    TripCard(trip: trip, dim: model.open != nil)
                }
                if let notice = model.station?.majorNotice, model.line == nil || notice.lines.contains(model.line ?? "") {
                    NoticeBanner(notice: notice, dim: model.open != nil) { withAnimation(.smooth(duration: 0.3)) { model.notice = notice } }
                }
                let rows = model.departures
                ForEach(rows) { departure in
                    Band(departure: departure, now: model.now, look: model.look(departure.line.line),
                         notice: departure.dir.disrupted ? model.station?.notice(for: departure.line.line) : nil,
                         grey: !departure.run.timeBasis.isLive || model.station?.isStale == true,
                         selected: model.open?.line == departure.line.line && model.open?.direction == (departure.dir.direction ?? 0),
                         dim: model.open != nil && model.open?.line != departure.line.line) {
                        model.openDeparture(departure, session.api)
                    }
                    .transition(.opacity)
                }
                if rows.isEmpty {
                    VStack(alignment: .leading, spacing: 12) {
                        Text(model.line.map { "Nothing on line \($0) right now" } ?? "Nothing leaves here right now")
                            .font(.posterGrotesque(26))
                        if model.line != nil {
                            Button { withAnimation(.snappy) { model.line = nil } } label: {
                                Text("Show all lines").font(.geist(15, .semibold)).padding(.horizontal, 14).frame(height: 44)
                            }
                            .buttonStyle(PressStyle())
                            .glassEffect(.clear.interactive(), in: .rect(cornerRadius: 14))
                        }
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(16)
                }
            }
            .animation(reduceMotion ? nil : .snappy(duration: 0.3), value: model.line)
            // The last rows scroll up clear of Where to? and the buttons beside it.
            .padding(.bottom, 120)
        }
        .scrollIndicators(.hidden)
        .refreshable { await model.load(session.api) }
        // Swipe the board sideways: the next line, as the canvas's pages.
        .simultaneousGesture(
            DragGesture(minimumDistance: 30)
                .onEnded { value in
                    guard abs(value.translation.width) > 60, abs(value.translation.width) > abs(value.translation.height) * 1.5,
                          let lines = model.station?.lineNames, lines.count > 1 else { return }
                    let pages: [String?] = [nil] + lines
                    let i = pages.firstIndex(of: model.line) ?? 0
                    let next = pages[(i + (value.translation.width < 0 ? 1 : -1) + pages.count) % pages.count]
                    withAnimation(.snappy) { model.line = next }
                }
        )
    }
}

// MARK: - Rail

/// Every line here as its badge, "All" first; one tap shows only that line, a second all again.
private struct LineRail: View {
    let model: TravelModel

    var body: some View {
        ScrollView(.horizontal) {
            HStack(spacing: 3) {
                chip(title: "All", fill: .clear, ink: nil, on: model.line == nil, mark: false, dim: false) { model.line = nil }
                ForEach(model.station?.lineNames ?? [], id: \.self) { line in
                    let look = model.look(line)
                    chip(title: line, fill: look.base, ink: look.ink, on: model.line == line,
                         mark: model.station?.majorNotice?.lines.contains(line) == true,
                         dim: model.nextMinutes(line) == nil) {
                        model.line = model.line == line ? nil : line
                    }
                }
            }
            .padding(.horizontal, 16)
        }
        .scrollIndicators(.hidden)
        .sensoryFeedback(.selection, trigger: model.line)
    }

    private func chip(title: String, fill: Color, ink: Color?, on: Bool, mark: Bool, dim: Bool, tap: @escaping () -> Void) -> some View {
        Button { withAnimation(.snappy) { tap() } } label: {
            Text(title)
                .font(.geist(13.5, .bold)).monospacedDigit()
                .foregroundStyle(ink ?? (on ? Color(hex: 0x211D15) : Color.white))
                .frame(minWidth: 30).frame(height: 28).padding(.horizontal, 4)
                .background(fill == .clear ? Color.clear : fill, in: .rect(cornerRadius: 8))
                .padding(3)
                .background(on ? Palette.posterPaper : .clear, in: .rect(cornerRadius: 12))
                .shadow(color: .black.opacity(on ? 0.35 : 0), radius: 9, y: 6)
                .overlay(alignment: .topTrailing) {
                    if mark {
                        Circle().fill(Palette.posterPaper).frame(width: 10, height: 10)
                            .overlay { Circle().strokeBorder(Palette.conflictText, lineWidth: 3) }
                            .offset(x: 1, y: -2)
                    }
                }
                .opacity(dim ? 0.4 : 1)
                .frame(minHeight: 44)
                .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel(title == "All" ? "All departures" : "Line \(title)")
        .accessibilityAddTraits(on ? .isSelected : [])
    }
}

// MARK: - Rows

/// One line one way in its own colour: the number, where it goes and by way of, the minutes on flaps.
struct Band: View {
    let departure: Departure
    let now: Date
    let look: LineLook
    let notice: Station.Notice?
    let grey: Bool
    let selected: Bool
    let dim: Bool
    let tap: () -> Void

    var body: some View {
        let run = departure.run
        Button(action: tap) {
            HStack(spacing: 10) {
                Text(departure.line.line)
                    .font(.posterGrotesque(departure.line.line.count > 2 ? 22 : 29)).tracking(-0.6)
                    .lineLimit(1).minimumScaleFactor(0.7)
                    .frame(width: 56, alignment: .leading)
                VStack(alignment: .leading, spacing: 1) {
                    HStack(spacing: 5) {
                        Image(systemName: "arrow.right").font(.system(size: 12, weight: .bold))
                        Text(departure.dir.dest).lineLimit(1)
                    }
                    .font(.geist(17, .bold)).tracking(-0.2)
                    HStack(spacing: 6) {
                        Text(via).lineLimit(1)
                        status
                    }
                    .font(.geist(12, .medium)).opacity(0.92)
                }
                Spacer(minLength: 4)
                // Minutes on the flaps; an hour or more away, its clock time (a capped "99" said nothing).
                if run.minutes(from: now) >= 60 {
                    HStack(spacing: 1.5) {
                        ForEach(Array(run.time.enumerated()), id: \.offset) { _, ch in
                            if ch == ":" {
                                Text(":").font(.geist(18, .bold))
                            } else {
                                FlapDigit(character: ch, grey: grey || run.cancelled, width: 19, height: 30, size: 20)
                            }
                        }
                    }
                } else {
                    HStack(spacing: 2) {
                        ForEach(Array(String(format: "%02d", run.minutes(from: now)).enumerated()), id: \.offset) { _, digit in
                            FlapDigit(character: digit, grey: grey || run.cancelled)
                        }
                    }
                }
            }
            .foregroundStyle(look.ink)
            .padding(.leading, 14).padding(.trailing, 16)
            .frame(height: 56)
            .background(look.fill)
            .overlay(alignment: .leading) { if selected { Rectangle().fill(Palette.posterPaper).frame(width: 5) } }
            .opacity(dim ? 0.4 : 1)
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(spoken)
        .accessibilityHint("Shows its stops and next times")
    }

    private var via: String {
        // "via Merkenich" on a tram to Merkenich says nothing.
        let stops = departure.dir.via.filter { !$0.isEmpty && !TravelModel.same($0, departure.dir.dest) }
        if run.cancelled || late != nil || notice != nil { return stops.first.map { "via \($0)" } ?? "" }
        return stops.isEmpty ? "" : "via " + stops.prefix(2).joined(separator: " · ")
    }

    private var run: Station.Run { departure.run }
    private var late: Int? { (run.delay ?? 0) >= 2 ? run.delay : nil }

    @ViewBuilder
    private var status: some View {
        if run.cancelled {
            HStack(spacing: 4) { Text(run.time).strikethrough(); Text("Cancelled") }.font(.geist(12, .semibold))
        } else if let late {
            Text("\(late) min late").font(.geist(11.5, .bold)).padding(.horizontal, 7).frame(height: 19)
                .background(Palette.warnBackground, in: .rect(cornerRadius: 10)).foregroundStyle(Palette.warnText)
        } else if let notice, notice.isMajor {
            Text(notice.headline.count > 22 ? "Line trouble" : notice.headline).font(.geist(11.5, .bold)).padding(.horizontal, 7).frame(height: 19)
                .background(Palette.noticeBackground, in: .rect(cornerRadius: 10)).foregroundStyle(Palette.conflictText)
        }
    }

    private var spoken: String {
        let minutes = run.minutes(from: now)
        var text = "Line \(departure.line.line) to \(departure.dir.dest), " + (minutes == 0 ? "now" : minutes >= 60 ? "at \(run.time)" : "in \(minutes) minutes")
        if run.cancelled { text += ", cancelled" } else if let late { text += ", \(late) minutes late" }
        if grey { text += ", timetable time" }
        return text
    }
}

/// One split-flap digit: a dark card with the hinge line across its middle; the digit drops in.
struct FlapDigit: View {
    let character: Character
    var grey = false
    var width: CGFloat = 26
    var height: CGFloat = 40
    var size: CGFloat = 29

    var body: some View {
        Text(String(character))
            .font(.geist(size, .bold)).monospacedDigit()
            .foregroundStyle(grey ? Color(hex: 0xBDB39F) : Color(hex: 0xFFF6E3))
            .frame(width: width, height: height)
            .background(LinearGradient(colors: [Color(hex: 0x2A2620), Color(hex: 0x1B1812)], startPoint: .top, endPoint: .bottom), in: .rect(cornerRadius: 5))
            .overlay { Rectangle().fill(.black.opacity(0.75)).frame(height: 1) }
            .contentTransition(.numericText(countsDown: true))
            .animation(.snappy(duration: 0.35), value: character)
    }
}

/// A word on small flaps (Finding the closest station…).
struct FlapWord: View {
    let text: String
    var small = false
    @State private var shown: [Character] = []
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        HStack(spacing: 1.8) {
            ForEach(Array(shown.enumerated()), id: \.offset) { _, ch in
                FlapDigit(character: ch, width: small ? 19 : 26, height: small ? 30 : 40, size: small ? 20 : 29)
            }
        }
        .task(id: text) {
            let target = Array(text)
            shown = Array(repeating: " ", count: target.count)
            let letters = Array("ABCDEFGHKLMNOPRSTUVWZ0123456789")
            for step in 0..<(reduceMotion ? 1 : 6) {
                try? await Task.sleep(for: .milliseconds(80))
                shown = target.enumerated().map { i, ch in step >= 5 || i < step * 2 ? ch : letters.randomElement() ?? ch }
            }
            shown = target
        }
        .accessibilityHidden(true)
    }
}

/// Your next trip, from your day: a ring counting down to leaving, and where it's to.
/// KVB's big notice for a line here, in English; a tap opens it.
private struct NoticeBanner: View {
    let notice: Station.Notice
    let dim: Bool
    let tap: () -> Void

    var body: some View {
        Button(action: tap) {
            HStack(spacing: 10) {
                Image(systemName: "exclamationmark").font(.system(size: 13, weight: .heavy))
                    .foregroundStyle(.white).frame(width: 26, height: 26).background(Color(hex: 0xC2321A), in: .circle)
                VStack(alignment: .leading, spacing: 1) {
                    Text(notice.headline).font(.geist(15.5, .bold)).lineLimit(1)
                    Text(notice.lines.count == 1 ? "Line \(notice.lines[0]) · from KVB" : "Lines \(notice.lines.joined(separator: ", ")) · from KVB")
                        .font(.geist(12.5)).foregroundStyle(Palette.noticeText.opacity(0.7)).lineLimit(1)
                }
                Spacer(minLength: 0)
            }
            .foregroundStyle(Palette.noticeText)
            .padding(.horizontal, 14).frame(height: 56)
            .background(Palette.noticeBackground)
            .shadow(color: .black.opacity(0.3), radius: 9, y: 8)
            .opacity(dim ? 0.4 : 1)
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .zIndex(1)
    }
}

/// A board with nothing to show yet: what's happening, and what to do.
private struct BoardState<Extra: View>: View {
    let title: String
    @ViewBuilder let extra: Extra

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(title).font(.posterGrotesque(34)).tracking(-0.4)
            extra
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 16).padding(.top, 30)
    }
}

/// "Where to?": Plan a trip, typed or said.
private struct WhereTo: View {
    let open: () -> Void
    let speak: () -> Void

    var body: some View {
        HStack(spacing: 4) {
            Button(action: open) {
                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass").font(.system(size: 17, weight: .semibold))
                    Text("Where to?").font(.geist(17))
                    Spacer(minLength: 0)
                }
                .frame(maxHeight: .infinity)
                .contentShape(.rect)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Where to? Plan a trip")
            Button(action: speak) {
                Image(systemName: "mic.fill").font(.system(size: 17)).frame(width: 36, height: 36).contentShape(.circle)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Say where to")
        }
        .foregroundStyle(Palette.text.opacity(0.8))
        .padding(.leading, 16).padding(.trailing, 6)
        .frame(height: 48)
        .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 24))
    }
}
