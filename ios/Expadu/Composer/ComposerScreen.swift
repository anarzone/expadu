import API
import Core
import DesignSystem
import SwiftUI

/// Composer, as the canvas draws it: the street wall, "What's on your mind?" and an advertising
/// column of today's posters to spin and tap; the box at the bottom to ask, discover or plan. Once
/// asked, the answers come on a sheet of paper over the wall. The drawer (swipe from the left or
/// the menu) holds the conversations. Everything shown is the server's: its workspace, its answers.
struct ComposerScreen: View {
    let opening: String?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.dismiss) private var dismiss
    @Environment(\.shell) private var shell
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var model = ComposerModel()
    @State private var text = ""
    @State private var drawer = false
    @State private var settings = false
    @State private var searching = false
    @State private var asked = false
    @State private var sheet: ComposerSheet?
    @State private var dictation = Dictation()
    @FocusState private var typing: Bool

    var body: some View {
        ZStack(alignment: .topLeading) {
            ComposerDrawer(open: $drawer, settings: $settings, searching: $searching)
                .environment(model)
            page
                .contentShape(.rect)
                // Edge to edge, the wall under the clock and the home bar too; rounded when the drawer shows.
                .mask { RoundedRectangle(cornerRadius: drawer ? 44 : 0, style: .continuous).ignoresSafeArea() }
                .shadow(color: .black.opacity(drawer ? 0.35 : 0), radius: 30, x: -10)
                .offset(x: drawer ? 322 : 0)
                .overlay {
                    if drawer {
                        Color.black.opacity(0.001).offset(x: 322)
                            .onTapGesture { withAnimation(Motion.ios(0.4)) { drawer = false } }
                    }
                }
                .gesture(
                    DragGesture(minimumDistance: 20)
                        .onEnded { drag in
                            let sideways = abs(drag.translation.width) > abs(drag.translation.height) * 1.5
                            guard sideways else { return }
                            if !drawer, drag.startLocation.x < 40, drag.translation.width > 80 { withAnimation(Motion.ios(0.4)) { drawer = true } }
                            if drawer, drag.translation.width < -80 { withAnimation(Motion.ios(0.4)) { drawer = false } }
                        }
                )
        }
        .fullScreenCover(isPresented: $searching) {
            ComposerSearch(close: { searching = false },
                           opened: { searching = false; withAnimation(Motion.ios(0.4)) { drawer = false } },
                           ask: { words in
                               searching = false
                               withAnimation(Motion.ios(0.4)) { drawer = false }
                               ask(words)
                           })
                .environment(model).environment(session)
        }
        .background(Color(light: 0xEFE8DA, dark: 0x12141F).ignoresSafeArea())
        .environment(model)
        .sheet(item: $sheet) { sheet in
            Group {
                switch sheet {
                case let .appointment(day): AppointmentSheet(day: day)
                case let .add(day): AddPlaceSheet(day: day)
                case .start: StartSheet()
                case let .place(place): PlaceSheet(place: place, saved: place.row?.saved ?? false) { _ in }
                }
            }
            .environment(model).environment(session).environment(today)
        }
        .task {
            await model.start(session.api)
            if let opening, !asked {
                asked = true
                await model.send(opening, api: session.api, place: today.place, label: nil, fresh: true)
            }
        }
        .sheet(isPresented: $settings) { ComposerSettings().environment(model).environment(session) }
    }

    // MARK: The page

    private var page: some View {
        ZStack(alignment: .top) {
            wall
            if let collection = model.collection {
                ComposerCollectionPaper(collection: collection, open: { sheet = $0 }, ask: ask)
                    .id(collection)
                    .padding(.top, 112)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
            } else if !model.talking {
                greeting
                    .opacity(typing ? 0 : 1)
                    .offset(y: typing ? -20 : 0)
                ComposerColumn(posters: posters) { poster in ask(poster.question) }
                    .frame(height: 520)
                    .padding(.top, 196)
                    .scaleEffect(typing ? 0.82 : 1, anchor: .top)
                    .offset(y: typing ? -110 : 0)
                    .opacity(typing ? 0.55 : 1)
                    // While typing, a tap on it only puts the keyboard away; it doesn't ask the poster.
                    .allowsHitTesting(!typing)
                    .transition(.opacity.combined(with: .scale(scale: 0.9, anchor: .top)))
            } else {
                ComposerPaper(ask: ask)
                    .padding(.top, 112)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
            }
            head
            VStack(spacing: 8) {
                Spacer()
                if let note = model.note {
                    ComposerNoteBar(note: note).transition(.offset(y: 20).combined(with: .opacity))
                }
                box
                if !model.talking, model.collection == nil, !typing, let disclosure = model.disclosure, disclosure.available == true {
                    notice(disclosure).transition(.opacity)
                }
            }
            .padding(.horizontal, 12).padding(.bottom, 8)
        }
        .animation(reduceMotion ? nil : Motion.ios(0.45), value: model.talking)
        .animation(reduceMotion ? nil : Motion.ios(0.45), value: model.collection)
        .animation(reduceMotion ? nil : Motion.ios(0.38), value: typing)
        .animation(.spring(duration: 0.45, bounce: 0.25), value: model.note)
    }

    /// The street wall from Sign in, then its own colour below.
    private var wall: some View {
        GeometryReader { proxy in
            ZStack(alignment: .topLeading) {
                LinearGradient(colors: [Color(hex: 0x7C7162), Color(hex: 0x5F5548)], startPoint: .top, endPoint: .bottom)
                if let image = UIImage(named: "street.webp") {
                    let height: CGFloat = 790
                    Image(uiImage: image).resizable()
                        .frame(width: height * 2400 / 1252, height: height)
                        .offset(x: -651 * proxy.size.width / 402)
                }
            }
            .frame(width: proxy.size.width, height: proxy.size.height, alignment: .topLeading)
            .clipped()
        }
        .ignoresSafeArea()
        // The picture is wider than the screen; clipping it doesn't stop it catching taps.
        .allowsHitTesting(false)
        .accessibilityHidden(true)
    }

    private var head: some View {
        HStack {
            Button {
                typing = false
                withAnimation(Motion.ios(0.4)) {
                    // A view's way back is the head's ‹; otherwise the menu.
                    if model.collection != nil, !drawer { model.collection = nil } else { drawer.toggle() }
                }
            } label: {
                Image(systemName: model.collection != nil && !drawer ? "chevron.left" : "line.3.horizontal").font(.system(size: 18, weight: .semibold))
                    .contentTransition(.symbolEffect(.replace))
                    .frame(width: 44, height: 44)
            }
            .buttonStyle(.glass)
            .buttonBorderShape(.circle)
            .accessibilityLabel(model.collection != nil && !drawer ? "Back" : "Your conversations and settings")
            Spacer()
            Button { dismiss() } label: {
                Image(systemName: "xmark").font(.system(size: 17, weight: .semibold))
                    .frame(width: 44, height: 44)
            }
            .buttonStyle(.glass)
            .buttonBorderShape(.circle)
            .accessibilityLabel("Close Composer")
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 16).padding(.top, 6)
    }

    private var greeting: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(session.name.isEmpty ? "Hallo" : "Hallo \(session.name.split(separator: " ").first.map(String.init) ?? session.name)")
                .font(.posterMarker(15)).foregroundStyle(Palette.text)
                .padding(.horizontal, 11).padding(.top, 3).padding(.bottom, 2)
                .background(Palette.posterYellow)
                .rotationEffect(.degrees(-2))
                .shadow(color: .black.opacity(0.22), radius: 3, y: 2)
            Text("What’s on your mind?").font(.posterGrotesque(37)).tracking(-0.4)
                .foregroundStyle(Color(hex: 0xFFF8E6))
                .shadow(color: Color(hex: 0x1E2442, opacity: 0.35), radius: 10, y: 1)
                .accessibilityAddTraits(.isHeader)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 18).padding(.top, 70)
    }

    // MARK: The box

    private var box: some View {
        VStack(alignment: .trailing, spacing: 4) {
            if text.count > 440 {
                Text(text.count >= 500 ? "Your message is full." : "\(500 - text.count) left")
                    .font(.geist(12.5, .semibold)).foregroundStyle(text.count >= 500 ? Color(hex: 0xFF8D72) : .white)
                    .padding(.horizontal, 10)
            }
            HStack(alignment: .bottom, spacing: 4) {
                Menu {
                    Button { sheet = .appointment(model.day) } label: { Label("Add appointment", systemImage: "plus") }
                    Button { sheet = .add(model.day) } label: { Label("Add a place or event", systemImage: "mappin") }
                    Button { sheet = .start } label: { Label("Starting point · \(model.start?.label ?? "Where you are")", systemImage: "location") }
                } label: {
                    Image(systemName: "plus").font(.system(size: 18, weight: .semibold)).foregroundStyle(Palette.text)
                        .frame(width: 44, height: 44)
                        .contentShape(.rect)
                }
                .menuOrder(.fixed)
                .accessibilityLabel("More")
                if dictation.state == .listening {
                    HStack(spacing: 10) {
                        VoiceBars(level: dictation.level)
                        Text(dictation.words.isEmpty ? "Listening…" : dictation.words.split(separator: " ").suffix(3).joined(separator: " "))
                            .font(.geist(17, .medium)).foregroundStyle(Palette.text).lineLimit(1)
                            .contentTransition(.opacity)
                        Spacer(minLength: 0)
                    }
                    .frame(minHeight: 44)
                    .animation(.snappy, value: dictation.words)
                    .transition(.opacity)
                } else {
                    TextField(model.talking ? "Ask or add a detail…" : "Ask, discover, or plan…", text: $text, axis: .vertical)
                        .font(.geist(17)).lineLimit(1...5)
                        .focused($typing)
                        .submitLabel(.send)
                        .onSubmit { ask(text) }
                        .onChange(of: text) { if text.count > 500 { text = String(text.prefix(500)) } }
                        .padding(.vertical, 11)
                        .frame(minHeight: 44)
                }
                if dictation.state == .listening {
                    Button { text = String(dictation.finish().prefix(500)) } label: {
                        Image(systemName: "checkmark").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 44, height: 44)
                            .background(Palette.text, in: .rect(cornerRadius: 14, style: .continuous))
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("Done speaking")
                    .transition(.scale.combined(with: .opacity))
                } else if text.trimmingCharacters(in: .whitespaces).isEmpty {
                    Button {
                        typing = false
                        Task {
                            await dictation.start()
                            if dictation.state == .unavailable { model.say("Speaking isn’t available. Allow the microphone and speech recognition in Settings.", problem: true) }
                        }
                    } label: {
                        Image(systemName: "mic").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.text)
                            .frame(width: 44, height: 44)
                            .contentShape(.rect)
                    }
                    .buttonStyle(PressStyle())
                    .accessibilityLabel("Speak")
                } else {
                    Button { ask(text) } label: {
                        Image(systemName: "arrow.up").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                            .frame(width: 44, height: 44)
                            .background(Palette.text, in: .rect(cornerRadius: 14, style: .continuous))
                    }
                    .buttonStyle(PressStyle())
                    .disabled(model.pending != nil)
                    .accessibilityLabel("Send")
                    .transition(.scale.combined(with: .opacity))
                }
            }
            .padding(6)
            .foregroundStyle(Palette.text)
            .glassEffect(.regular, in: .rect(cornerRadius: 28, style: .continuous))
            .animation(.spring(duration: 0.3, bounce: 0.3), value: text.isEmpty)
            .animation(.spring(duration: 0.3, bounce: 0.2), value: dictation.state)
            .onDisappear { dictation.cancel() }
        }
    }

    /// What Composer sends and to whom, under the box until they've asked once.
    private func notice(_ disclosure: ComposerDisclosure) -> some View {
        Button { settings = true } label: {
            Text("Composer sends what you type, with your nearest stop and neighbourhood, to \(disclosure.processorName ?? "its processor") to answer you. \(Text("Settings").underline().fontWeight(.semibold))")
                .foregroundStyle(Color(hex: 0x3A3122))
                .multilineTextAlignment(.leading)
        }
        .buttonStyle(.plain)
    }

    private func ask(_ words: String) {
        let question = words.trimmingCharacters(in: .whitespacesAndNewlines)
        // Send with nothing written: the keyboard goes away.
        guard !question.isEmpty else { typing = false; return }
        text = ""
        typing = false
        Task { await model.send(question, api: session.api, place: today.place, label: nil) }
    }

    // MARK: Posters

    /// Today's posters: the city's own prompts, and the person's own when there's something real
    /// to say (line trouble, Paperwork, what's on).
    private var posters: [ComposerPoster] {
        var list: [ComposerPoster] = []
        if let page = today.page {
            if let trouble = page.needs.first(where: { $0.line != nil }), let line = trouble.line {
                list.append(ComposerPoster(label: "Travel", symbol: "tram.fill", colour: trouble.color.map { LineLook(hex: $0).deep } ?? Color(hex: 0x95C11F), ink: .white,
                                           tape: "On one of your lines", head: trouble.headline, sub: "Line \(line)",
                                           cta: "Find another way",
                                           question: trouble.headline.contains(line) ? "\(trouble.headline). Find me another way" : "Line \(line): \(trouble.headline). Find me another way"))
            }
            if let paperwork = page.paperwork {
                list.append(ComposerPoster(label: "Paperwork", symbol: "doc.text.fill", colour: Color(hex: 0xC23B0E), ink: .white,
                                           tape: paperwork.processTitle ?? "Your Paperwork", head: "Next: \(paperwork.title)", sub: "",
                                           cta: "What does it need?", question: "What does my Paperwork need?"))
            }
            let ideas = today.shownIdeas
            if !ideas.isEmpty {
                let when = page.ideasWhen.lowercased()
                list.append(ComposerPoster(label: "Events", symbol: "calendar", colour: Color(hex: 0x4A2BD0), ink: .white,
                                           tape: "\(page.ideasWhen) · \(ideas.count == 1 ? "1 event" : "\(ideas.count) events")",
                                           head: "What’s on \(when)", sub: ideas.first?.title ?? "", cta: "See what’s on", question: "What’s on \(when)?"))
            }
        }
        list.append(ComposerPoster(label: "Plan", symbol: "sparkle", colour: Palette.posterYellow, ink: Color(hex: 0x211D15),
                                   tape: "One day at a time", head: "Plan my Saturday", sub: "Tell Composer what you feel like", cta: "Plan it", question: "Plan my Saturday"))
        list.append(ComposerPoster(label: "Nearby", symbol: "mappin", colour: Color(hex: 0x00768B), ink: .white,
                                   tape: "Open late", head: "Somewhere quiet to work", sub: "Cafés near you", cta: "Find me a place", question: "Somewhere quiet to work"))
        if list.count < 5 {
            list.append(ComposerPoster(label: "Outdoors", symbol: "leaf.fill", colour: Color(hex: 0x008540), ink: .white,
                                       tape: "Close by", head: "A little time outdoors", sub: "Parks and walks near you", cta: "Show me", question: "A little time outdoors"))
        }
        if list.count < 5 {
            list.append(ComposerPoster(label: "Events", symbol: "calendar", colour: Color(hex: 0x4A2BD0), ink: .white,
                                       tape: "In Cologne", head: "What’s on this weekend", sub: "Events near you", cta: "See what’s on", question: "What’s on this weekend"))
        }
        return Array(list.prefix(5))
    }
}

/// One poster on the column.
struct ComposerPoster: Identifiable, Equatable {
    let label: String
    let symbol: String
    let colour: Color
    let ink: Color
    let tape: String
    let head: String
    let sub: String
    let cta: String
    let question: String

    var id: String { question }
}

struct ComposerNoteBar: View {
    let note: ComposerModel.Note

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: note.problem ? "exclamationmark.circle.fill" : "checkmark.circle.fill")
                .font(.system(size: 15, weight: .semibold))
                .foregroundStyle(note.problem ? Color(hex: 0xFF9E80) : Color(hex: 0x86E2AA))
            Text(note.text).font(.geist(15)).foregroundStyle(.white).lineLimit(3)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 18).padding(.vertical, 12)
        .background(Color(hex: 0x2B2720), in: .rect(cornerRadius: 22, style: .continuous))
        .accessibilityElement(children: .combine)
    }
}
