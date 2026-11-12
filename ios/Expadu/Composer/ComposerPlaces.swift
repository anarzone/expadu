import API
import Core
import CoreLocation
import DesignSystem
import Maps
import SwiftUI

/// The answer's places, as Around here draws them: each on its own bit of map with its hours, Save
/// over the map, what it is and why; Not this for people who turned learning on. Tap the map for
/// all of them on one map; tap the place for its sheet.
struct ComposerPlacesCard: View {
    let places: [ComposerBlock.Place]
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var saved: [Int: Bool] = [:]
    @State private var left: [String: Task<Void, Never>] = [:]
    /// Not this already kept (its Undo time passed): Undo takes it back on the server too.
    @State private var recorded: Set<String> = []
    @State private var sheet: ComposerBlock.Place?
    @State private var mapAt: String?
    @Namespace private var mapSpace

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            ScrollView(.horizontal) {
                HStack(alignment: .top, spacing: 10) {
                    ForEach(places, id: \.key) { place in
                        if left[place.key] != nil {
                            stub(place).transition(.opacity.combined(with: .scale(scale: 0.95)))
                        } else {
                            card(place).transition(.opacity.combined(with: .scale(scale: 0.95)))
                        }
                    }
                }
                .padding(.vertical, 2)
                .animation(reduceMotion ? nil : Motion.ios(0.35), value: Array(left.keys))
            }
            .scrollIndicators(.hidden)
            .scrollClipDisabled()
            Text("Maps © OpenStreetMap contributors, OpenFreeMap.").font(.geist(12.5)).foregroundStyle(Palette.textSoft)
        }
        .sheet(item: $sheet) { place in
            PlaceSheet(place: place, saved: saved[place.id] ?? place.row?.saved ?? false) { on in saved[place.id] = on }
                .environment(model).environment(session).environment(today)
        }
        .fullScreenCover(item: Binding(get: { mapAt.map { MapFocus(key: $0) } }, set: { mapAt = $0?.key })) { focus in
            PlacesMap(places: places.filter { left[$0.key] == nil && $0.row?.lat != nil }, selected: focus.key,
                      saved: { saved[$0.id] ?? $0.row?.saved ?? false }, save: { place in Task { await save(place) } },
                      open: { place in mapAt = nil; Task { try? await Task.sleep(for: .milliseconds(350)); sheet = place } })
                .navigationTransition(.zoom(sourceID: focus.key, in: mapSpace))
        }
    }

    private struct MapFocus: Identifiable { let key: String; var id: String { key } }

    private func card(_ place: ComposerBlock.Place) -> some View {
        let on = saved[place.id] ?? place.row?.saved ?? false
        return VStack(alignment: .leading, spacing: 0) {
            ZStack(alignment: .topTrailing) {
                Button { if place.row?.lat != nil { mapAt = place.key } else { sheet = place } } label: {
                    PlacePreview(place: place)
                        .frame(height: 112)
                        .overlay(alignment: .bottomLeading) {
                            if let hours = place.hours {
                                Text(hours).font(.geist(12.5, .bold))
                                    .foregroundStyle(hours.hasPrefix("Open") ? Color(hex: 0x17603A) : Palette.text)
                                    .padding(.horizontal, 8).padding(.vertical, 4)
                                    .background(.regularMaterial, in: .rect(cornerRadius: 8, style: .continuous))
                                    .padding(8)
                            }
                        }
                        .clipShape(.rect(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(PressStyle())
                .matchedTransitionSource(id: place.key, in: mapSpace)
                .accessibilityLabel("Show \(place.name) on the map")
                Button { Task { await save(place) } } label: {
                    Image(systemName: on ? "bookmark.fill" : "bookmark").font(.system(size: 15, weight: .semibold))
                        .foregroundStyle(on ? Palette.action : Palette.text)
                        .contentTransition(.symbolEffect(.replace))
                        .frame(width: 38, height: 38)
                        .glassEffect(.regular.interactive(), in: .rect(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(PressStyle())
                .padding(8)
                .accessibilityLabel(on ? "Unsave \(place.name)" : "Save \(place.name)")
                .sensoryFeedback(.success, trigger: on) { _, new in new }
            }
            Button { sheet = place } label: {
                VStack(alignment: .leading, spacing: 4) {
                    Text(place.name).font(.geist(16.5, .bold)).foregroundStyle(Palette.text).lineLimit(2)
                    Text([place.row?.label ?? place.kind.replacingOccurrences(of: "_", with: " ").capitalized, place.part ?? place.area].compactMap { $0 }.joined(separator: " · "))
                        .font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
                    if let laptop = place.laptopWords {
                        Label(laptop, systemImage: "laptopcomputer").labelStyle(Tight()).font(.geist(12.5, .semibold))
                            .foregroundStyle(place.row?.laptop?.people?.value == "yes" ? Color(hex: 0x17603A) : Palette.textSoft).lineLimit(1)
                    }
                    let small = ([place.distance] + place.features).compactMap { $0 }.filter { !$0.isEmpty }
                    if !small.isEmpty { Text(small.joined(separator: " · ")).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(1) }
                    if let because = place.because {
                        Text(because).font(.geist(13, .medium)).italic().foregroundStyle(Palette.text).lineLimit(3)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.top, 10)
                .contentShape(.rect)
            }
            .buttonStyle(PressStyle())
            if today.page?.personalised == true {
                Button { notThis(place) } label: {
                    Text("Not this").font(.geist(14, .semibold)).foregroundStyle(Palette.text)
                        .padding(.horizontal, 12).frame(height: 36)
                        .buttonFace(Palette.quiet, radius: 10)
                }
                .buttonStyle(PressStyle())
                .padding(.top, 10)
            }
        }
        .padding(10)
        .frame(width: 244, alignment: .topLeading)
        .card(radius: 24)
    }

    private func stub(_ place: ComposerBlock.Place) -> some View {
        HStack(spacing: 10) {
            Text("Not this: \(place.name)").font(.geist(14.5)).foregroundStyle(Palette.textSoft).lineLimit(2)
            Spacer(minLength: 0)
            Button("Undo") { undo(place) }.font(.geist(14.5, .bold)).foregroundStyle(TodayLink.ink)
        }
        .padding(14)
        .frame(width: 244, alignment: .leading)
        .background(Palette.quiet, in: .rect(cornerRadius: 24, style: .continuous))
    }

    /// As Around here saves it: at once, put back when it fails.
    private func save(_ place: ComposerBlock.Place) async {
        let next = !(saved[place.id] ?? place.row?.saved ?? false)
        withAnimation(Motion.ios(0.25)) { saved[place.id] = next }
        do { try await session.api.placeFeedback(place.id, action: next ? "saved" : "clear") } catch {
            withAnimation(Motion.ios(0.25)) { saved[place.id] = !next }
            model.say("That place wasn’t saved. Please try again.", problem: true)
        }
    }

    /// The card folds to its stub at once; once Undo has had its time, Composer leaves the place out.
    private func notThis(_ place: ComposerBlock.Place) {
        let spot = place.id
        let key = place.key
        let api = session.api
        left[key] = Task {
            try? await Task.sleep(for: .seconds(5))
            guard !Task.isCancelled else { return }
            if (try? await api.placeFeedback(spot, action: "not_interested")) != nil { recorded.insert(key) }
        }
    }

    private func undo(_ place: ComposerBlock.Place) {
        guard let task = left.removeValue(forKey: place.key) else { return }
        task.cancel()
        // Already kept: Undo takes it back.
        guard recorded.remove(place.key) != nil else { return }
        let spot = place.id
        let api = session.api
        Task { try? await api.placeFeedback(spot, action: "clear") }
    }
}

/// A place's picture: its credited photo when it has one, else its spot on the map.
private struct PlacePreview: View {
    let place: ComposerBlock.Place

    var body: some View {
        if let url = place.row?.photo.flatMap({ URL(string: $0.url) }) {
            AsyncImage(url: url) { image in image.resizable().scaledToFill() } placeholder: { Palette.quiet }
        } else if let lat = place.row?.lat, let lng = place.row?.lng {
            let point = CLLocationCoordinate2D(latitude: lat, longitude: lng)
            CityMap(lines: [], pins: [.init(id: place.key, coordinate: point, title: place.name, kind: .place(symbol: PlaceSymbol.name(place.row?.icon ?? place.kind)))],
                    fit: [point], interactive: false)
                .allowsHitTesting(false)
        } else {
            ZStack {
                Palette.selectBackground
                Image(systemName: PlaceSymbol.name(place.kind)).font(.system(size: 30, weight: .semibold)).foregroundStyle(TodayLink.ink)
            }
        }
    }
}

// MARK: - The place's sheet

/// One place: where it is, what it's like, "Good for laptop work?", getting there from the
/// starting point, and Save, Been there, Share, Plan a visit.
struct PlaceSheet: View {
    let place: ComposerBlock.Place
    let saved: Bool
    let onSaved: (Bool) -> Void
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.dismiss) private var dismiss
    @State private var isSaved = false
    @State private var been = false
    @State private var laptop: String?
    @State private var canAnswer = false
    @State private var thanks = false
    @State private var plan: WayPlan?
    @State private var way = 0
    @State private var finding = false
    @State private var problem: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                ZStack(alignment: .topTrailing) {
                    PlacePreview(place: place).frame(height: 190).clipShape(.rect(cornerRadius: 22, style: .continuous))
                    CloseButton { dismiss() }.padding(10)
                }
                VStack(alignment: .leading, spacing: 4) {
                    Text(place.name).font(.posterGrotesque(28)).tracking(-0.3).foregroundStyle(Palette.text).accessibilityAddTraits(.isHeader)
                    Text([place.row?.label ?? place.kind.replacingOccurrences(of: "_", with: " ").capitalized, place.part ?? place.area, place.hours].compactMap { $0 }.joined(separator: " · "))
                        .font(.geist(15)).foregroundStyle(Palette.textSoft)
                    let small = ([place.street] + place.features).compactMap { $0 }
                    if !small.isEmpty { Text(small.joined(separator: " · ")).font(.geist(14)).foregroundStyle(Palette.textSoft) }
                    if let because = place.because { Text(because).font(.geist(14.5, .medium)).italic().foregroundStyle(Palette.text).padding(.top, 4) }
                }
                if place.row?.icon == "cafe" || place.laptopWords != nil || canAnswer {
                    VStack(alignment: .leading, spacing: 10) {
                        HStack {
                            Text("Good for laptop work?").font(.geist(16, .bold)).foregroundStyle(Palette.text)
                            Spacer()
                            if let words = place.laptopWords { Text(words.replacingOccurrences(of: "Good for laptop work · ", with: "Yes · ")).font(.geist(13)).foregroundStyle(Palette.textSoft) }
                        }
                        if canAnswer || isSaved || been {
                            HStack(spacing: 8) {
                                answer("Yes", value: "yes")
                                answer("Not really", value: "no")
                            }
                            if thanks { Text("Thanks. It helps the next person.").font(.geist(13.5)).foregroundStyle(Palette.textSoft).transition(.opacity) }
                        } else {
                            Text("Save it or mark Been there to answer.").font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                        }
                    }
                    .padding(14).frame(maxWidth: .infinity, alignment: .leading).card(radius: 20)
                }
                if let lat = place.row?.lat, let lng = place.row?.lng {
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Getting there").font(.geist(16, .bold)).foregroundStyle(Palette.text)
                        Button { Task { await showWay(lat: lat, lng: lng) } } label: {
                            HStack(spacing: 12) {
                                Image(systemName: "figure.walk").font(.system(size: 15, weight: .semibold)).foregroundStyle(TodayLink.ink)
                                    .frame(width: 36, height: 36).background(Palette.selectBackground, in: .rect(cornerRadius: 11, style: .continuous))
                                VStack(alignment: .leading, spacing: 1) {
                                    Text(place.distance.map { "\($0) away" } ?? "The way there").font(.geist(15.5, .semibold)).foregroundStyle(Palette.text)
                                    Text("From \(model.start?.label ?? "where you are")").font(.geist(13)).foregroundStyle(Palette.textSoft)
                                }
                                Spacer(minLength: 0)
                                if finding { ProgressView().controlSize(.small) } else {
                                    Label("See routes", systemImage: "arrow.right").labelStyle(.titleAndIcon).font(.geist(14, .semibold)).foregroundStyle(TodayLink.ink)
                                }
                            }
                            .padding(12).card(radius: 18)
                        }
                        .buttonStyle(PressStyle())
                        if let problem { Text(problem).font(.geist(13.5, .semibold)).foregroundStyle(Palette.conflictText) }
                    }
                }
                FlowButtons {
                    PaperworkButton(title: isSaved ? "Saved" : "Save", symbol: isSaved ? "bookmark.fill" : "bookmark", quiet: true, small: true) { Task { await save() } }
                    PaperworkButton(title: "Been there", symbol: been ? "checkmark.circle.fill" : "checkmark", quiet: true, small: true) { Task { await markBeen() } }
                    ShareLink(item: shareText) {
                        Label("Share", systemImage: "square.and.arrow.up").labelStyle(Tight()).font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                            .padding(.horizontal, 14).frame(height: 42).buttonFace(Palette.quiet)
                    }
                    PaperworkButton(title: "Plan a visit", symbol: "calendar", quiet: true, small: true) {
                        dismiss()
                        Task { await model.send("Plan a visit to \(place.name)", api: session.api, place: today.place, label: nil) }
                    }
                }
                Text("Maps © OpenStreetMap contributors, OpenFreeMap.").font(.geist(12)).foregroundStyle(Palette.textSoft)
            }
            .padding(16)
            .animation(Motion.ios(0.3), value: thanks)
        }
        .scrollIndicators(.hidden)
        .background(Palette.canvas)
        .presentationDetents([.large])
        .task {
            isSaved = saved
            if let standing = try? await session.api.placeStanding(place.id) { isSaved = standing.saved; been = standing.been }
            if let state = try? await session.api.laptopAnswer(place.id) { canAnswer = state.canAnswer; laptop = state.answer }
        }
        .fullScreenCover(isPresented: Binding(get: { plan != nil }, set: { if !$0 { plan = nil } })) {
            if let plan, plan.ways.indices.contains(way) {
                WayMap(to: place.name, legs: plan.ways[way].legs, ways: plan.ways, picked: $way,
                       start: { Task { await go(plan) } })
            }
        }
    }

    private var shareText: String {
        [place.name, place.street, place.area, "Cologne"].compactMap { $0 }.joined(separator: ", ")
    }

    private func answer(_ title: String, value: String) -> some View {
        let on = laptop == value
        return Button {
            Task {
                let before = laptop
                withAnimation(Motion.ios(0.25)) { laptop = value }
                do { try await session.api.answerLaptop(place.id, yes: value == "yes"); thanks = true } catch {
                    withAnimation(Motion.ios(0.25)) { laptop = before }
                    model.say(ComposerModel.words(error), problem: true)
                }
            }
        } label: {
            Text(title).font(.geist(15, .semibold)).foregroundStyle(on ? Palette.onInk : Palette.text)
                .frame(maxWidth: .infinity).frame(height: 42)
                .buttonFace(on ? Palette.text : Palette.quiet)
        }
        .buttonStyle(PressStyle())
        .accessibilityAddTraits(on ? .isSelected : [])
    }

    private func save() async {
        let next = !isSaved
        withAnimation(Motion.ios(0.25)) { isSaved = next }
        do {
            try await session.api.placeFeedback(place.id, action: next ? "saved" : "clear")
            onSaved(next)
            if next { canAnswer = true }
        } catch {
            withAnimation(Motion.ios(0.25)) { isSaved = !next }
            model.say("That place wasn’t saved. Please try again.", problem: true)
        }
    }

    private func markBeen() async {
        let next = !been
        withAnimation(Motion.ios(0.25)) { been = next }
        do {
            let standing = try await session.api.placeBeen(place.id, next)
            been = standing.been
            if next { canAnswer = true }
        } catch {
            withAnimation(Motion.ios(0.25)) { been = !next }
            model.say("That didn’t go through. Try again.", problem: true)
        }
    }

    /// The ways there from the starting point (or where the phone is), on the map.
    private func showWay(lat: Double, lng: Double) async {
        finding = true
        problem = nil
        defer { finding = false }
        let from = model.start?.lat.flatMap { lat in model.start?.lng.map { (lat, $0) } } ?? today.place.map { ($0.latitude, $0.longitude) }
        do throws(APIFailure) {
            let found = try await session.api.planWay(to: place.name, latitude: lat, longitude: lng, fromLatitude: from?.0, fromLongitude: from?.1, startsAt: nil)
            if found.ways.isEmpty { problem = "No way there right now. Try again in a moment." } else { way = 0; plan = found }
        } catch {
            problem = ComposerModel.words(error)
        }
    }

    private func go(_ plan: WayPlan) async {
        let (running, why) = await today.startWay(session.api, plan: plan, way: way, replace: false)
        self.plan = nil
        if let why { problem = why } else if running { problem = "You’re already on your way somewhere else. End that trip on Today first." } else {
            model.say("You’re on your way. Today shows it live.")
        }
    }
}

// MARK: - All the places on one map

/// The answer's places on one map: the tapped one picked, a card for it with Save and ‹ n of N ›.
private struct PlacesMap: View {
    let places: [ComposerBlock.Place]
    @State var selected: String
    let saved: (ComposerBlock.Place) -> Bool
    let save: (ComposerBlock.Place) -> Void
    let open: (ComposerBlock.Place) -> Void
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        let index = places.firstIndex { $0.key == selected } ?? 0
        let current = places.indices.contains(index) ? places[index] : nil
        ZStack(alignment: .bottom) {
            CityMap(lines: [], pins: places.compactMap { place in
                guard let lat = place.row?.lat, let lng = place.row?.lng else { return nil }
                return .init(id: place.key, coordinate: .init(latitude: lat, longitude: lng), title: place.name,
                             kind: place.key == selected ? .destination : .place(symbol: PlaceSymbol.name(place.row?.icon ?? place.kind)))
            }, fit: places.compactMap { place in place.row?.lat.flatMap { lat in place.row?.lng.map { CLLocationCoordinate2D(latitude: lat, longitude: $0) } } },
            padding: UIEdgeInsets(top: 120, left: 50, bottom: 260, right: 50))
            .ignoresSafeArea()
            if let current {
                VStack(alignment: .leading, spacing: 10) {
                    HStack(spacing: 10) {
                        step("chevron.left", "Previous place") { selected = places[(index - 1 + places.count) % places.count].key }
                        VStack(alignment: .leading, spacing: 2) {
                            Text(current.name).font(.geist(17, .bold)).foregroundStyle(Palette.text).lineLimit(1)
                            Text("\(index + 1) of \(places.count) · \([current.row?.label ?? current.kind, current.distance, current.hours].compactMap { $0 }.joined(separator: " · "))")
                                .font(.geist(13)).foregroundStyle(Palette.textSoft).lineLimit(1)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        step("chevron.right", "Next place") { selected = places[(index + 1) % places.count].key }
                    }
                    HStack(spacing: 8) {
                        PaperworkButton(title: "Open", grow: true) { open(current) }
                        Button { save(current) } label: {
                            Image(systemName: saved(current) ? "bookmark.fill" : "bookmark").font(.system(size: 16, weight: .semibold))
                                .foregroundStyle(saved(current) ? Palette.action : Palette.text)
                                .frame(width: 52, height: 52).buttonFace(Palette.quiet, radius: 16)
                        }
                        .buttonStyle(PressStyle())
                        .accessibilityLabel(saved(current) ? "Unsave \(current.name)" : "Save \(current.name)")
                    }
                    Text("Maps © OpenStreetMap contributors").font(.geist(12)).foregroundStyle(Palette.textSoft)
                }
                .padding(16)
                .glassEffect(.regular, in: .rect(cornerRadius: 28, style: .continuous))
                .padding(.horizontal, 12).padding(.bottom, 8)
                .animation(Motion.ios(0.3), value: selected)
            }
        }
        .overlay(alignment: .topTrailing) {
            HStack {
                Text(places.count == 1 ? "1 place" : "\(places.count) places").font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                    .padding(.horizontal, 14).frame(height: 44)
                    .glassEffect(.regular, in: .rect(cornerRadius: 14, style: .continuous))
                Spacer()
                Button { dismiss() } label: {
                    Image(systemName: "xmark").font(.system(size: 17, weight: .semibold)).frame(width: 44, height: 44)
                }
                .buttonStyle(.glass).buttonBorderShape(.circle)
                .accessibilityLabel("Close map")
            }
            .padding(.horizontal, 16).padding(.top, 6)
        }
        .sensoryFeedback(.selection, trigger: selected)
    }

    private func step(_ symbol: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: symbol).font(.system(size: 15, weight: .bold)).foregroundStyle(Palette.text)
                .frame(width: 40, height: 40).buttonFace(Palette.quiet, radius: 12)
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel(label)
        .disabled(places.count < 2)
    }
}
