import API
import Core
import DesignSystem
import SwiftUI

/// The canvas's curves: `glide` settles like a thrown sheet, `ios` is the system's push.
enum ComposerMotion {
    static func glide(_ duration: Double) -> Animation { .timingCurve(0.16, 0.84, 0.3, 1, duration: duration) }
    static func ios(_ duration: Double) -> Animation { .timingCurve(0.32, 0.72, 0, 1, duration: duration) }
    static let drawerOpen = Animation.spring(response: 0.44, dampingFraction: 0.86)
    static let drawerClose = Animation.spring(response: 0.36, dampingFraction: 1)
}

/// A circle that grows from a control until it covers the screen (the canvas's bloom and search-in).
/// Negative x or y count from the right or the bottom edge.
nonisolated struct BloomShape: Shape {
    var radius: CGFloat
    let x: CGFloat
    let y: CGFloat

    var animatableData: CGFloat {
        get { radius }
        set { radius = newValue }
    }

    func path(in rect: CGRect) -> Path {
        let centre = CGPoint(x: x < 0 ? rect.maxX + x : rect.minX + x, y: y < 0 ? rect.maxY + y : rect.minY + y)
        return Path(ellipseIn: CGRect(x: centre.x - radius, y: centre.y - radius, width: radius * 2, height: radius * 2))
    }
}

private struct Bloom: ViewModifier {
    let radius: CGFloat
    let x: CGFloat
    let y: CGFloat

    func body(content: Content) -> some View {
        content.mask { BloomShape(radius: radius, x: x, y: y).ignoresSafeArea() }
    }
}

extension AnyTransition {
    /// Opens out of the control at (x, y) and closes back into it.
    static func bloom(from radius: CGFloat, x: CGFloat, y: CGFloat) -> AnyTransition {
        .modifier(active: Bloom(radius: radius, x: x, y: y), identity: Bloom(radius: 1100, x: x, y: y))
    }
}

/// Composer, as the canvas draws it: the street wall, "What's on your mind?" and an advertising
/// column of today's posters to spin and tap; the box at the bottom to ask, discover or plan. Once
/// asked, the answers come on a sheet of paper over the wall. The drawer (drag from the left or
/// the menu) holds the conversations. Everything shown is the server's: its workspace, its answers.
struct ComposerScreen: View {
    let opening: String?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var today
    @Environment(\.shell) private var shell
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.openURL) private var openURL
    @State private var model = ComposerModel()
    @State private var text = ""
    @State private var drawer = false
    /// The drawer under the finger (0 closed, 1 open); nil when it rests.
    @State private var dragging: CGFloat?
    @State private var dragFrom: CGFloat?
    @State private var settings = false
    @State private var searching = false
    @State private var asked = false
    @State private var sheet: ComposerSheet?
    @State private var dictation = Dictation()
    @State private var safeTop: CGFloat = 62
    @State private var safeBottom: CGFloat = 34
    @State private var screenWidth: CGFloat = 402
    @FocusState private var typing: Bool

    private static let drawerWidth: CGFloat = 322
    private var progress: CGFloat { dragging ?? (drawer ? 1 : 0) }

    /// A height the canvas measures from the screen's top edge, from the safe area's.
    private func top(_ y: CGFloat) -> CGFloat { y - safeTop }

    var body: some View {
        let p = progress, c = max(0, min(1, p))
        ZStack(alignment: .topLeading) {
            ComposerDrawer(open: $drawer, settings: $settings, searching: $searching, progress: c, top: top(58))
                .environment(model)
            page
                .contentShape(.rect)
                .overlay {
                    // The page dims as the drawer comes; a tap on it closes the drawer.
                    Color(light: 0x281E0F, dark: 0x090A12).opacity((colorScheme == .dark ? 0.46 : 0.16) * c)
                        .ignoresSafeArea()
                        .allowsHitTesting(drawer)
                        .onTapGesture { drawer = false }
                }
                // Edge to edge, the wall under the clock and the home bar too; the screen's own corners.
                .mask { RoundedRectangle(cornerRadius: p > 0 ? 44 : 0, style: .continuous).ignoresSafeArea() }
                .shadow(color: Color(light: 0x463418, dark: 0x000000).opacity((colorScheme == .dark ? 0.55 : 0.3) * c), radius: 30, x: -20)
                .offset(x: p * Self.drawerWidth)
        }
        .animation(reduceMotion ? nil : drawer ? ComposerMotion.drawerOpen : ComposerMotion.drawerClose, value: drawer)
        .simultaneousGesture(drawerDrag)
        .overlay {
            if searching {
                ComposerSearch(top: top(58),
                               close: { searching = false },
                               opened: { searching = false; drawer = false },
                               ask: { words in
                                   searching = false
                                   drawer = false
                                   ask(words)
                               })
                    .environment(model)
                    .transition(reduceMotion ? .opacity : .asymmetric(insertion: .bloom(from: 22, x: 286, y: 80), removal: .bloom(from: 22, x: 286, y: 80).combined(with: .opacity)))
                    .zIndex(5)
            }
        }
        .animation(reduceMotion ? nil : searching ? ComposerMotion.ios(0.46) : ComposerMotion.ios(0.3), value: searching)
        .background(Color(light: 0xEFE8DA, dark: 0x12141F).ignoresSafeArea())
        .onGeometryChange(for: EdgeInsets.self) { $0.safeAreaInsets } action: { safeTop = $0.top; safeBottom = $0.bottom }
        .onGeometryChange(for: CGFloat.self) { $0.size.width } action: { screenWidth = $0 }
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

    @Environment(\.colorScheme) private var colorScheme

    /// The drawer follows the finger, then a spring carries it home. From the left edge on the wall,
    /// from anywhere on the paper; a drag to the left closes it.
    private var drawerDrag: some Gesture {
        DragGesture(minimumDistance: 9)
            .onChanged { drag in
                if dragFrom == nil {
                    let dx = drag.translation.width, dy = drag.translation.height
                    let sideways = abs(dx) >= abs(dy) * 1.3
                    let opens = !drawer && dx > 0 && (drag.startLocation.x < 24 || model.talking || model.collection != nil)
                    let closes = drawer && dx < 0
                    guard sideways, opens || closes else { dragFrom = -1; return }
                    dragFrom = drawer ? 1 : 0
                    typing = false
                }
                guard let from = dragFrom, from >= 0 else { return }
                let x = from * Self.drawerWidth + drag.translation.width
                // Past fully open it gives a little, like a held sheet.
                dragging = (x > Self.drawerWidth ? Self.drawerWidth + (x - Self.drawerWidth) * 0.16 : max(0, x)) / Self.drawerWidth
            }
            .onEnded { drag in
                defer { dragFrom = nil }
                guard let from = dragFrom, from >= 0 else { return }
                let open = from * Self.drawerWidth + drag.translation.width + (drag.predictedEndTranslation.width - drag.translation.width) * 0.5 > Self.drawerWidth / 2
                withAnimation(reduceMotion ? nil : open ? ComposerMotion.drawerOpen : ComposerMotion.drawerClose) {
                    dragging = nil
                    drawer = open
                }
            }
    }

    // MARK: The page

    private var onWall: Bool { !model.talking && model.collection == nil }

    private var page: some View {
        ZStack(alignment: .top) {
            wall
            greeting
                .opacity(onWall && !typing ? 1 : 0)
                .offset(y: onWall && !typing ? 0 : -14)
                .animation(reduceMotion ? nil : ComposerMotion.glide(0.5), value: onWall && !typing)
            // The column stays on the wall behind the paper, so coming back doesn't build it again.
            // It hangs from the top and takes no room, so the keyboard never moves or squeezes it.
            Color.clear.overlay(alignment: .top) {
                ComposerColumn(posters: posters) { poster in ask(poster.question) }
                    // Its own size from the screen's width: the keyboard taking height must not shrink it
                    // again on top of the canvas's 60%.
                    .frame(width: screenWidth, height: screenWidth * 560 / 402)
                    .scaleEffect(typing ? 0.6 : onWall ? 1 : 0.9, anchor: .top)
                    .offset(y: typing ? -62 : onWall ? 0 : -30)
                    .brightness(onWall ? 0 : -0.18)
                    .padding(.top, top(196))
                    // While typing, a tap on it only puts the keyboard away; it doesn't ask the poster.
                    .allowsHitTesting(onWall && !typing)
                    .accessibilityHidden(!onWall)
            }
            if model.talking, model.collection == nil {
                ComposerPaper(ask: ask)
                    .padding(.top, top(112))
                    .transition(.move(edge: .bottom))
                    .zIndex(2)
            }
            if let collection = model.collection {
                ComposerCollectionPaper(collection: collection, open: { sheet = $0 }, ask: ask)
                    .id(collection)
                    .padding(.top, top(112))
                    // From the drawer it fades in; from another view it pushes in from the right.
                    .transition(model.collectionBack != nil || model.leaving ? .move(edge: .trailing) : .opacity)
                    .zIndex(3)
            }
            head.zIndex(4)
            VStack(spacing: 10) {
                Spacer()
                if let note = model.note {
                    ComposerNoteBar(note: note).transition(.offset(y: 20).combined(with: .opacity))
                }
                box
                    .opacity(model.collection == nil ? 1 : 0)
                    .allowsHitTesting(model.collection == nil)
                if showsNotice, let disclosure = model.disclosure {
                    notice(disclosure).transition(.opacity)
                }
            }
            .padding(.horizontal, 12)
            // The canvas sets the box 34pt from the screen's edge, and the notice under it 22pt.
            .padding(.bottom, typing ? 8 : showsNotice ? 22 - safeBottom : 34 - safeBottom)
            .zIndex(4)
        }
        .animation(reduceMotion ? nil : ComposerMotion.glide(0.6), value: model.talking)
        .animation(reduceMotion ? nil : model.collectionBack != nil || model.leaving ? ComposerMotion.ios(0.42) : .easeOut(duration: 0.24), value: model.collection)
        .animation(reduceMotion ? nil : ComposerMotion.ios(0.45), value: typing)
        .animation(.spring(duration: 0.45, bounce: 0.25), value: model.note)
    }

    private var showsNotice: Bool { onWall && !typing && model.disclosure?.available == true }

    /// The street wall from Sign in, then its own colour below; the sky dims a little behind the title.
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
                LinearGradient(stops: [.init(color: Color(hex: 0x28325A, opacity: 0.34), location: 0), .init(color: Color(hex: 0x28325A, opacity: 0.1), location: 0.75),
                                       .init(color: Color(hex: 0x28325A, opacity: 0), location: 1)], startPoint: .top, endPoint: .bottom)
                    .frame(height: 200)
            }
            .frame(width: proxy.size.width, height: proxy.size.height, alignment: .topLeading)
            .clipped()
        }
        .ignoresSafeArea()
        // The picture is wider than the screen; clipping it doesn't stop it catching taps.
        .allowsHitTesting(false)
        .accessibilityHidden(true)
    }

    /// Clear glass over the wall, as the canvas's .gl: the sky shows through (regular glass turns milky there).
    private var head: some View {
        HStack {
            Button {
                typing = false
                // A pushed view's way back is the head's ‹; otherwise the menu.
                if model.collectionBack != nil, !drawer { model.back() } else { drawer.toggle() }
            } label: {
                Group {
                    if model.collectionBack != nil, !drawer {
                        Image(systemName: "chevron.left").font(.system(size: 18, weight: .semibold))
                    } else {
                        MenuMark()
                    }
                }
                .frame(width: 44, height: 44)
                .glassEffect(.clear.interactive(), in: .circle)
                .contentShape(.circle)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel(model.collectionBack != nil && !drawer ? "Back" : "Your conversations, plans and saved")
            Spacer()
            Button { shell.closeComposer() } label: {
                Image(systemName: "xmark").font(.system(size: 17, weight: .semibold))
                    .frame(width: 44, height: 44)
                    .glassEffect(.clear.interactive(), in: .circle)
                    .contentShape(.circle)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Close Composer")
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 16).padding(.top, top(58))
    }

    private var greeting: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(session.name.isEmpty ? "Hallo" : "Hallo \(session.name.split(separator: " ").first.map(String.init) ?? session.name)")
                .font(.posterMarker(15)).foregroundStyle(Palette.text)
                .padding(.horizontal, 11).padding(.top, 3).padding(.bottom, 2)
                .background(Palette.posterYellow)
                .rotationEffect(.degrees(-2))
                .shadow(color: Color(hex: 0x211D15, opacity: 0.22), radius: 3, y: 2)
            Text("What’s on your mind?").font(.custom("PosterGrotesque-ExtraBold", fixedSize: 37)).tracking(-0.4)
                .lineLimit(1).minimumScaleFactor(0.7)
                .foregroundStyle(Color(hex: 0xFFF8E6))
                .shadow(color: Color(hex: 0x1E2442, opacity: 0.35), radius: 10, y: 1)
                .accessibilityAddTraits(.isHeader)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 18).padding(.top, top(104))
        .allowsHitTesting(false)
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
                    Button { sheet = .add(model.day) } label: { Label("Add a place or event", systemImage: "mappin.circle.fill") }
                    Button { sheet = .start } label: { Label("Starting point · \(model.start?.label ?? "Where you are")", systemImage: "location.fill") }
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
                    TextField("", text: $text, prompt: Text(model.talking ? "Ask or add a detail…" : "Ask, discover, or plan…").foregroundStyle(Color(light: 0x5B5447, dark: 0xA9A59C)), axis: .vertical)
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

    /// What Composer sends and to whom, under the box on the wall, with the notice and the way out.
    private func notice(_ disclosure: ComposerDisclosure) -> some View {
        var words = AttributedString("Composer sends what you type, with your nearest stop and neighbourhood, to \(disclosure.processorName ?? "its processor") to answer you · ")
        if let link = disclosure.processorPrivacyUrl.flatMap(URL.init(string:)) {
            var notice = AttributedString("Privacy notice")
            notice.link = link
            notice.underlineStyle = .single
            notice.font = .geist(11.5, .semibold)
            words += notice + AttributedString(" · ")
        }
        var withdraw = AttributedString("Withdraw")
        withdraw.link = URL(string: "expadu-composer://withdraw")
        withdraw.underlineStyle = .single
        withdraw.font = .geist(11.5, .semibold)
        words += withdraw
        return Text(words).font(.geist(11.5)).lineSpacing(1)
            .foregroundStyle(Color(hex: 0xF5EEDD)).tint(Color(hex: 0xF5EEDD))
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, 6)
            // Withdraw opens Composer's settings, where stopping it asks first.
            .environment(\.openURL, OpenURLAction { url in
                if url.scheme == "expadu-composer" { settings = true; return .handled }
                return .systemAction
            })
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

    private var posters: [ComposerPoster] { Self.posters(today) }

    /// Today's posters: the person's own when there's something real to say (line trouble,
    /// Paperwork, what's on), then the city's own prompts. Always five, as the column is built.
    static func posters(_ today: TodayModel) -> [ComposerPoster] {
        var list: [ComposerPoster] = []
        if let page = today.page {
            if let trouble = page.needs.first(where: { $0.line != nil }), let line = trouble.line {
                list.append(ComposerPoster(label: "Travel", colour: trouble.color.map { LineLook(hex: $0).deep } ?? Color(hex: 0x95C11F), ink: .white, number: line,
                                           tape: "On one of your lines", head: trouble.headline,
                                           sub: trouble.detail.flatMap { $0.lowercased().hasPrefix("on one of your lines") ? nil : $0 } ?? "", cta: "Find another way",
                                           question: trouble.headline.contains(line) ? "\(trouble.headline). Find me another way" : "Line \(line): \(trouble.headline). Find me another way"))
            }
            if let paperwork = page.paperwork {
                let count = paperwork.ready.flatMap { ready in paperwork.total.map { (ready, $0) } }
                list.append(ComposerPoster(label: "Paperwork", colour: Color(hex: 0xC23B0E), ink: .white, number: count.map { "\($0.0)/\($0.1)" }, numberSize: 112,
                                           tape: paperwork.processTitle ?? "Your Paperwork", head: "Next: \(paperwork.title)",
                                           sub: count.map { "\($0.0) of \($0.1) documents ready" } ?? "", cta: "What does it need?", question: "What does my Paperwork need?"))
            }
            let ideas = today.shownIdeas
            if let first = ideas.first {
                let when = page.ideasWhen.lowercased()
                list.append(ComposerPoster(label: "Events", colour: Color(hex: 0x4A2BD0), ink: .white, mark: .calendar,
                                           tape: "\(page.ideasWhen) · \(ideas.count == 1 ? "1 event" : "\(ideas.count) events")", headSize: 27,
                                           head: first.title, sub: first.place ?? "", cta: "See what’s on", question: "What’s on \(when)?"))
            }
        }
        let own: [ComposerPoster] = [
            ComposerPoster(label: "Plan", colour: Color(hex: 0xFFCC33), ink: Color(hex: 0x211D15), mark: .spark,
                           tape: "One day at a time", head: "Plan my Saturday", sub: "Tell Composer what you feel like", cta: "Plan it", question: "Plan my Saturday"),
            ComposerPoster(label: "Nearby", colour: Color(hex: 0x00768B), ink: .white, mark: .pin,
                           tape: "Open late", head: "Somewhere quiet to work", sub: "Cafés near you", cta: "Find me a place", question: "Somewhere quiet to work"),
            ComposerPoster(label: "Outdoors", colour: Color(hex: 0x008540), ink: .white, mark: .pin,
                           tape: "Close by", head: "A little time outdoors", sub: "Parks and walks near you", cta: "Show me", question: "A little time outdoors"),
            ComposerPoster(label: "Events", colour: Color(hex: 0x4A2BD0), ink: .white, mark: .calendar,
                           tape: "In Cologne", head: "What’s on this weekend", sub: "Events near you", cta: "See what’s on", question: "What’s on this weekend"),
            ComposerPoster(label: "Step-free", colour: Color(hex: 0x0074AD), ink: .white, mark: .pin,
                           tape: "Ask anything", head: "Is the cinema step-free?", sub: "Composer checks the details", cta: "Ask it", question: "Is the cinema step-free?"),
        ]
        for poster in own where list.count < 5 && !list.contains(where: { $0.label == poster.label }) { list.append(poster) }
        for poster in own where list.count < 5 && !list.contains(poster) { list.append(poster) }
        return list
    }
}

/// My Plans' mark: an outline calendar with its bar and two pegs (the canvas's i-cal).
struct CalendarMark: View {
    var body: some View {
        Canvas { g, size in
            let k = size.width / 21
            let ink = GraphicsContext.Shading.foreground
            g.stroke(Path(roundedRect: CGRect(x: 2.5 * k, y: 4.5 * k, width: 16 * k, height: 14 * k), cornerRadius: 3 * k), with: ink, lineWidth: 1.8 * k)
            g.fill(Path(CGRect(x: 2.5 * k, y: 4.5 * k, width: 16 * k, height: 4.2 * k)), with: ink)
            for x in [6.5, 14.5] {
                g.stroke(Path { $0.move(to: CGPoint(x: x * k, y: 2 * k)); $0.addLine(to: CGPoint(x: x * k, y: 5 * k)) }, with: ink, style: StrokeStyle(lineWidth: 1.8 * k, lineCap: .round))
            }
        }
        .frame(width: 21, height: 21)
        .accessibilityHidden(true)
    }
}

/// The menu's mark: two lines, the lower one shorter (the canvas's .hb).
struct MenuMark: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Capsule().frame(width: 19, height: 2.2)
            Capsule().frame(width: 12, height: 2.2)
        }
        .frame(width: 19, alignment: .leading)
        .accessibilityHidden(true)
    }
}

/// One poster on the column.
nonisolated struct ComposerPoster: Identifiable, Equatable, Sendable {
    enum Mark: Equatable { case spark, pin, calendar }

    let label: String
    let colour: Color
    let ink: Color
    /// A big faint number behind the words ("12", "1/5"), or a mark when there's no number.
    var number: String?
    var numberSize: CGFloat = 150
    var mark: Mark?
    let tape: String
    var headSize: CGFloat = 33
    let head: String
    let sub: String
    let cta: String
    let question: String

    var id: String { question }
    /// What decides how it's printed.
    var printKey: String { [label, number ?? "", tape, head, sub, cta].joined(separator: "|") }
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
