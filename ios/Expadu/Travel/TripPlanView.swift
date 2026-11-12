import API
import Core
import DesignSystem
import SwiftUI

/// Plan a trip (the canvas's page): From and To, said or typed; when; what matters; their ★ stops,
/// Home and Work, today's places and the lines straight from this stop; then the routes.
struct TripPlanView: View {
    @Bindable var planner: TripPlanner
    let station: Station?
    let stationFavourite: Bool
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.dismiss) private var dismiss
    @State private var dictation = Dictation()
    @State private var whenOpen = false
    @FocusState private var focused: Bool

    var body: some View {
        ZStack {
            Palette.surface.ignoresSafeArea()
            if planner.routesShown {
                TripRoutesView(planner: planner, back: { planner.closeRoutes(); focused = true }, close: { planner.closeRoutes(); dismiss() })
                    .transition(.move(edge: .trailing).combined(with: .opacity))
            } else {
                page.transition(.move(edge: .leading).combined(with: .opacity))
            }
        }
        .animation(.smooth(duration: 0.4), value: planner.routesShown)
        .foregroundStyle(Palette.text)
        .task { await planner.loadPlaces(session.api) }
        .task(id: planner.query + (planner.field == .from ? "f" : "t")) { await planner.suggest(session.api) }
        .task(id: planner.listening) { if planner.listening { await listen() } }
        .sheet(isPresented: $whenOpen) {
            WhenSheet(planner: planner)
                .presentationDetents([.height(planner.when == .now ? 210 : 420)])
                .presentationBackground(Palette.surface)
                .presentationCornerRadius(32)
        }
        .sheet(item: Binding(get: { planner.homeSheet.map { HomeKind(id: $0) } }, set: { planner.homeSheet = $0?.id })) { kind in
            HomeSheet(planner: planner, category: kind.id)
                .presentationDetents([.medium, .large])
                .presentationBackground(Palette.surface)
                .presentationCornerRadius(32)
        }
    }

    private struct HomeKind: Identifiable { let id: String }

    // MARK: The page

    private var page: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 12) {
                CircleButton(symbol: "chevron.left", label: "Back") { dismiss() }
                Text("Plan a trip").font(.posterGrotesque(26)).tracking(-0.3)
                Spacer()
            }
            fromTo
            chips
            if planner.listening {
                listeningView
            } else {
                list
            }
        }
        .padding(.horizontal, 16).padding(.top, 8)
        .onAppear { if !planner.listening { focused = true } }
    }

    private var fromTo: some View {
        VStack(spacing: 0) {
            Button {
                withAnimation(.snappy) { planner.field = .from; planner.query = "" }
                focused = true
            } label: {
                HStack(spacing: 12) {
                    Circle().strokeBorder(Palette.text, lineWidth: 3).frame(width: 14, height: 14)
                    VStack(alignment: .leading, spacing: 1) {
                        Text(planner.fromStation.map { "From · near \($0)" } ?? "From").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                        if planner.field == .from {
                            TextField("Your starting point", text: $planner.query).font(.geist(17)).focused($focused)
                                .textInputAutocapitalization(.words).autocorrectionDisabled()
                        } else {
                            Text(planner.from?.name ?? "Your location").font(.geist(17)).lineLimit(1)
                        }
                    }
                    Spacer(minLength: 0)
                }
                .padding(.horizontal, 14).frame(height: 58).contentShape(.rect)
            }
            .buttonStyle(.plain)
            Divider().padding(.leading, 40)
            HStack(spacing: 12) {
                Circle().fill(Palette.action).frame(width: 14, height: 14)
                VStack(alignment: .leading, spacing: 1) {
                    Text("To").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                    if planner.field == .to {
                        TextField("Where to?", text: $planner.query).font(.geist(17)).focused($focused)
                            .textInputAutocapitalization(.words).autocorrectionDisabled()
                            .submitLabel(.go)
                            .onSubmit { Task { await pickFirst() } }
                    } else {
                        Button("Where to?") { withAnimation(.snappy) { planner.field = .to; planner.query = "" }; focused = true }
                            .font(.geist(17)).foregroundStyle(Palette.textSoft)
                    }
                }
                Spacer(minLength: 0)
                Button { planner.listening = true } label: {
                    Image(systemName: "mic.fill").font(.system(size: 17)).frame(width: 40, height: 40).contentShape(.circle)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("Say where to")
            }
            .padding(.leading, 14).padding(.trailing, 6).frame(height: 58)
        }
        .background(Palette.canvas, in: .rect(cornerRadius: 18))
        .overlay { RoundedRectangle(cornerRadius: 18).strokeBorder(Palette.border) }
    }

    private var chips: some View {
        ScrollView(.horizontal) {
            HStack(spacing: 6) {
                Button { whenOpen = true } label: {
                    HStack(spacing: 5) {
                        Image(systemName: "clock").font(.system(size: 13))
                        Text(planner.whenLabel)
                        Image(systemName: "chevron.down").font(.system(size: 11, weight: .semibold))
                    }
                    .chipFace(on: planner.when != .now)
                }
                .buttonStyle(PressStyle())
                ForEach(TripPlanner.Pref.allCases) { pref in
                    let on = planner.prefs.contains(pref)
                    Button {
                        withAnimation(.snappy) { if on { planner.prefs.remove(pref) } else { planner.prefs.insert(pref) } }
                    } label: { Text(pref.label).chipFace(on: on) }
                    .buttonStyle(PressStyle())
                    .accessibilityAddTraits(on ? .isSelected : [])
                }
            }
        }
        .scrollIndicators(.hidden)
        .sensoryFeedback(.selection, trigger: planner.prefs)
    }

    private var listeningView: some View {
        VStack(spacing: 16) {
            Spacer()
            Text("Say where you’re going").font(.posterGrotesque(28)).multilineTextAlignment(.center)
            if dictation.state == .unavailable {
                Text("The microphone isn’t available. Type it instead.").font(.geist(15)).foregroundStyle(Palette.textSoft)
                Button("Type it") { planner.listening = false; focused = true }.font(.geist(16, .semibold))
            } else {
                VoiceBars(level: dictation.level).frame(height: 50).foregroundStyle(Palette.navTeal)
                Text(dictation.words.isEmpty ? "Listening…" : dictation.words).font(.geist(17)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.center)
                Button {
                    planner.query = dictation.finish()
                    planner.listening = false
                    focused = true
                } label: {
                    Text("Done").font(.geist(16, .semibold)).padding(.horizontal, 22).frame(height: 46)
                }
                .buttonStyle(PressStyle())
                .buttonFace(Palette.action, radius: 14)
                .foregroundStyle(Palette.onAction)
            }
            Spacer()
        }
        .frame(maxWidth: .infinity)
    }

    private func listen() async {
        focused = false
        await dictation.start()
        // Stops by itself once they've been quiet for a moment after speaking.
        var quiet = 0, last = ""
        while planner.listening, dictation.state == .listening {
            try? await Task.sleep(for: .milliseconds(400))
            if dictation.words == last, !last.isEmpty { quiet += 1 } else { quiet = 0; last = dictation.words }
            if quiet >= 4 {
                planner.query = dictation.finish()
                planner.listening = false
                await pickFirst()
                return
            }
        }
    }

    // MARK: Lists

    private var list: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 8) {
                let q = planner.query.trimmingCharacters(in: .whitespaces)
                if planner.field == .from {
                    group {
                        PlanRow(icon: "location", title: "Your location", sub: "From where this phone is now") { planner.setFrom(nil) }
                    }
                }
                if q.isEmpty, planner.field == .to {
                    heading("Favourites")
                    group {
                        // The board's stop, unless it's the one they're standing at: no one plans a trip to where they are.
                        if let station, !(planner.from == nil && (station.walkMin ?? 99) <= 5) {
                            PlanRow(icon: stationFavourite ? "star.fill" : "tram.fill", title: station.name, sub: "Stop · shown on Travel") {
                                Task { await go(TripPoint(name: station.name, lat: station.areas.first?.lat ?? 0, lng: station.areas.first?.lng ?? 0)) }
                            }
                            .disabled(station.areas.first?.lat == nil)
                        }
                        ForEach(planner.places.favouriteStops.filter { $0.name != station?.name }) { stop in
                            PlanRow(icon: "star.fill", title: stop.name, sub: "Stop") {
                                if let lat = stop.lat, let lng = stop.lng { Task { await go(TripPoint(name: stop.name, lat: lat, lng: lng)) } }
                            }
                        }
                        placeRow("home", title: "Home", icon: "house")
                        placeRow("work", title: "Work", icon: "briefcase")
                    }
                    if !planner.places.today.isEmpty {
                        heading("Today’s places · From your day")
                        group {
                            ForEach(planner.places.today) { plan in
                                HStack(spacing: 12) {
                                    Image(systemName: "calendar").font(.system(size: 17)).frame(width: 36, height: 36).background(Palette.quiet, in: .rect(cornerRadius: 10))
                                    VStack(alignment: .leading, spacing: 1) {
                                        Text(plan.title).font(.geist(16, .semibold)).lineLimit(1)
                                        Text([plan.startTime, plan.place].compactMap { $0 }.joined(separator: " · ")).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
                                    }
                                    Spacer(minLength: 0)
                                    Button {
                                        Task { await go(TripPoint(name: plan.place ?? plan.title, lat: plan.lat, lng: plan.lng), target: plan) }
                                    } label: {
                                        Text("Go here").font(.geist(14, .semibold)).padding(.horizontal, 12).frame(height: 34)
                                    }
                                    .buttonStyle(PressStyle())
                                    .background(Palette.quiet, in: .rect(cornerRadius: 10))
                                }
                                .padding(.horizontal, 12).frame(minHeight: 58)
                            }
                        }
                    }
                    if !recents.isEmpty {
                        heading("Recent")
                        group {
                            ForEach(Array(recents.enumerated()), id: \.offset) { _, recent in
                                PlanRow(icon: "clock.arrow.circlepath", title: StopName.short(recent.name), sub: recent.area) {
                                    if let lat = recent.lat, let lng = recent.lng { Task { await go(TripPoint(name: recent.name, lat: lat, lng: lng)) } }
                                }
                            }
                        }
                    }
                }
                let direct = directRows(q)
                if !direct.isEmpty {
                    heading("Direct from \(station?.name ?? "here")")
                    group {
                        ForEach(direct, id: \.dep.id) { row in
                            let look = LineLook(hex: row.dep.line.color ?? "#4A4A4A")
                            HStack(spacing: 12) {
                                Text(row.dep.line.line).font(.geist(14, .bold)).foregroundStyle(look.ink)
                                    .frame(minWidth: 36, minHeight: 28).background(look.base, in: .rect(cornerRadius: 7))
                                VStack(alignment: .leading, spacing: 1) {
                                    Text(row.dep.dir.dest).font(.geist(16, .semibold)).lineLimit(1)
                                    Text(row.sub).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
                                }
                                Spacer(minLength: 0)
                                let m = row.dep.run.minutes(from: .now)
                                Text(m == 0 ? "Now" : "\(m) min").font(.geist(14, .semibold)).monospacedDigit()
                            }
                            .padding(.horizontal, 12).frame(minHeight: 58)
                        }
                    }
                }
                if q.count >= 2 {
                    if !planner.suggestions.isEmpty {
                        group {
                            ForEach(planner.suggestions) { hit in
                                PlanRow(icon: icon(hit.kind), title: StopName.short(hit.name), sub: hit.area) {
                                    let end = TripPoint(name: StopName.short(hit.name), lat: hit.lat, lng: hit.lng)
                                    if planner.field == .from { planner.setFrom(end) } else { Task { await go(end) } }
                                }
                            }
                        }
                    } else if planner.searched, direct.isEmpty {
                        VStack(alignment: .leading, spacing: 4) {
                            Text("No place matches that").font(.geist(16, .semibold))
                            Text("Try the street name, or a stop close by.").font(.geist(15)).foregroundStyle(Palette.textSoft)
                        }
                        .padding(.top, 12)
                    }
                } else if q.isEmpty {
                    Text("Type a stop, a street or a place.").font(.geist(13.5)).foregroundStyle(Palette.textSoft).padding(.horizontal, 2).padding(.top, 2)
                }
            }
            .padding(.bottom, 30)
            .animation(.snappy(duration: 0.25), value: planner.suggestions)
        }
        .scrollIndicators(.hidden)
        .scrollDismissesKeyboard(.interactively)
    }

    private func placeRow(_ category: String, title: String, icon: String) -> some View {
        let place = category == "home" ? planner.places.home : planner.places.work
        return PlanRow(icon: icon, title: title, sub: place?.address ?? (place?.hasPoint == true ? place?.name : "Add an address")) {
            if let place, let lat = place.lat, let lng = place.lng {
                Task { await go(TripPoint(name: title, lat: lat, lng: lng)) }
            } else {
                planner.homeSheet = category
            }
        }
    }

    /// The lines leaving this stop that pass what's typed: no planning needed.
    private func directRows(_ q: String) -> [(dep: Departure, sub: String)] {
        guard planner.field == .to, q.count >= 2, let station else { return [] }
        let needle = q.lowercased()
        return station.departures(now: .now).compactMap { dep -> (Departure, String)? in
            if dep.dir.dest.lowercased().contains(needle) { return (dep, dep.dir.via.isEmpty ? "" : "via " + dep.dir.via.prefix(2).joined(separator: " · ")) }
            if let stop = dep.dir.via.first(where: { $0.lowercased().contains(needle) }) { return (dep, "To \(stop)") }
            return nil
        }
        .prefix(5).map { $0 }
    }

    private func icon(_ kind: String) -> String {
        switch kind {
        case "stop": "tram.fill"
        case "saved": "star.fill"
        case "recent": "clock.arrow.circlepath"
        default: "mappin"
        }
    }

    /// Recent destinations, without Home and Work (they're in Favourites just above).
    private var recents: [TravelPlaces.Recent] {
        planner.places.recentDestinations.filter { !["home", "work", "zuhause", "arbeit"].contains($0.name.lowercased()) }
    }

    private func go(_ end: TripPoint, target: TravelPlaces.Plan? = nil) async {
        focused = false
        await planner.go(to: end, target: target, session.api)
    }

    private func pickFirst() async {
        if let hit = planner.suggestions.first {
            let end = TripPoint(name: StopName.short(hit.name), lat: hit.lat, lng: hit.lng)
            if planner.field == .from { planner.setFrom(end) } else { await go(end) }
        }
    }

    private func heading(_ text: String) -> some View {
        Text(text).font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft).padding(.top, 8).padding(.horizontal, 4)
    }

    private func group<Content: View>(@ViewBuilder _ content: () -> Content) -> some View {
        VStack(spacing: 0) { content() }
            .background(Palette.canvas, in: .rect(cornerRadius: 16))
            .overlay { RoundedRectangle(cornerRadius: 16).strokeBorder(Palette.border) }
    }
}

/// A row in Plan a trip's lists: an icon, a name, a line under it.
struct PlanRow: View {
    let icon: String
    let title: String
    let sub: String?
    let tap: () -> Void

    var body: some View {
        Button(action: tap) {
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 17)).frame(width: 36, height: 36).background(Palette.quiet, in: .rect(cornerRadius: 10))
                VStack(alignment: .leading, spacing: 1) {
                    Text(title).font(.geist(16, .semibold)).lineLimit(1)
                    if let sub, !sub.isEmpty { Text(sub).font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1) }
                }
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 12).frame(minHeight: 58).contentShape(.rect)
        }
        .buttonStyle(PressStyle())
    }
}

extension View {
    /// A filter's face: a rounded rect, filled when it's on.
    func chipFace(on: Bool) -> some View {
        self.font(.geist(14, .semibold))
            .padding(.horizontal, 12).frame(height: 36)
            .background(on ? Palette.selectBackground : Palette.quiet, in: .rect(cornerRadius: 10))
            .foregroundStyle(on ? Palette.navTeal : Palette.text)
            .frame(minHeight: 44)
    }
}

// MARK: - When

/// When: leave now, leave at, or be there by, on the hour and minute wheels.
struct WhenSheet: View {
    @Bindable var planner: TripPlanner
    @Environment(\.dismiss) private var dismiss
    @State private var mode = 0
    @State private var time = Date()
    @State private var was = TripPlanner.When.now

    var body: some View {
        VStack(spacing: 14) {
            HStack {
                CircleButton(symbol: "xmark", label: "Cancel") { planner.when = was; dismiss() }
                Spacer()
                Text(mode == 2 ? "Be there by" : "When").font(.geist(17, .bold))
                Spacer()
                Button { apply(); dismiss() } label: {
                    Image(systemName: "checkmark").font(.system(size: 15, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 40, height: 40).background(Palette.navTeal, in: .circle)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("Done")
            }
            Picker("When", selection: $mode) {
                Text("Leave now").tag(0)
                Text("Leave at").tag(1)
                Text("Arrive by").tag(2)
            }
            .pickerStyle(.segmented)
            if mode != 0 {
                DatePicker("Time", selection: $time, in: Date()..., displayedComponents: [.hourAndMinute])
                    .datePickerStyle(.wheel).labelsHidden()
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
            Spacer(minLength: 0)
        }
        .animation(.snappy, value: mode)
        .foregroundStyle(Palette.text)
        .padding(16).padding(.top, 6)
        .onAppear {
            was = planner.when
            switch planner.when {
            case .now: mode = 0; time = Date().addingTimeInterval(30 * 60)
            case let .leave(date): mode = 1; time = date
            case let .arrive(date): mode = 2; time = date
            }
        }
        .onChange(of: mode) { apply() }
    }

    private func apply() {
        planner.when = switch mode {
        case 1: .leave(time)
        case 2: .arrive(time)
        default: .now
        }
    }
}

// MARK: - Home and Work

/// Add an address for Home or Work: the place picked from the search, saved with its point.
struct HomeSheet: View {
    @Bindable var planner: TripPlanner
    let category: String
    @Environment(Session.self) private var session
    @Environment(\.dismiss) private var dismiss
    @State private var query = ""
    @State private var hits: [TripSuggestion] = []
    @State private var saving: TripSuggestion?
    @FocusState private var focused: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Text(category == "home" ? "Home address" : "Work address").font(.posterGrotesque(24))
                Spacer()
                CircleButton(symbol: "xmark", label: "Close") { dismiss() }
            }
            HStack(spacing: 8) {
                Image(systemName: "mappin").foregroundStyle(Palette.textSoft)
                TextField("Street and number, or a place", text: $query).font(.geist(17)).focused($focused)
                    .textInputAutocapitalization(.words).autocorrectionDisabled()
            }
            .padding(.horizontal, 12).frame(height: 46)
            .background(Palette.quiet, in: .rect(cornerRadius: 14))
            ScrollView {
                VStack(spacing: 0) {
                    ForEach(hits) { hit in
                        PlanRow(icon: saving == hit ? "checkmark" : "mappin", title: hit.name, sub: hit.area) {
                            saving = hit
                            Task {
                                await planner.saveHome(category, hit: hit, session.api)
                                dismiss()
                            }
                        }
                        .disabled(saving != nil)
                    }
                }
            }
        }
        .foregroundStyle(Palette.text)
        .padding(16).padding(.top, 6)
        .onAppear { focused = true }
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 3 else { hits = []; return }
            try? await Task.sleep(for: .milliseconds(250))
            guard !Task.isCancelled else { return }
            do throws(APIFailure) { hits = try await session.api.tripSuggestions(q, role: "destination").filter { $0.kind != "saved" } } catch { hits = [] }
        }
    }
}
