import Core
import DesignSystem
import SwiftUI

enum AppTab: String, CaseIterable, Identifiable {
    case today, paperwork, travel

    var id: String { rawValue }

    var title: String {
        switch self {
        case .today: "Today"
        case .paperwork: "Paperwork"
        case .travel: "Travel"
        }
    }

    var symbol: String {
        switch self {
        case .today: "house.fill"
        case .paperwork: "doc.text.fill"
        case .travel: "tram.fill"
        }
    }
}

/// What a page can ask of the shell: open Composer, show another section, or say how far it has scrolled.
struct ShellActions {
    var openComposer: () -> Void = {}
    var show: (AppTab) -> Void = { _ in }
    var scrolled: (CGFloat) -> Void = { _ in }
}

extension EnvironmentValues {
    @Entry var shell = ShellActions()
}

/// The phone shell: the brand, the bell and the account over each page; at the bottom a Liquid Glass
/// bar of sections that shrinks while you scroll down and grows back going up (as Instagram and
/// Apple's own apps), and the Composer button: a dark disc in a turning ring of the brand's
/// colours. Tap it to open Composer; hold it to say what you need.
struct ShellView: View {
    @State private var tab = AppTab.today
    @State private var today = TodayModel()
    @State private var compact = false
    @State private var lastOffset: CGFloat = 0
    @State private var composer: ComposerOpening?
    @State private var dictation = Dictation()
    @State private var talk = Talk.idle
    @State private var safeBottom: CGFloat = 0
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    /// The hold on the Composer button: listening, or about to cancel when the finger slides away.
    enum Talk: Equatable { case idle, listening, away }

    var body: some View {
        TabView(selection: $tab) {
            ForEach(AppTab.allCases) { section in
                Tab(value: section) {
                    Group {
                        switch section {
                        case .today: TodayView()
                        case .travel: TravelView()
                        case .paperwork: SectionPage(title: section.title)
                        }
                    }
                    // Each page keeps room for the header and the bar; it scrolls under both.
                    // Travel is its line's colour to the top, with its own header (the canvas, 6 Nov).
                    .safeAreaInset(edge: .top, spacing: 0) {
                        if section != .travel { ShellHeader(today: today) }
                    }
                    .safeAreaInset(edge: .bottom, spacing: 0) { Color.clear.frame(height: 52) }
                    .toolbarVisibility(.hidden, for: .tabBar)
                }
            }
        }
        .environment(today)
        .environment(\.shell, ShellActions(
            openComposer: { composer = ComposerOpening(words: nil) },
            show: { section in withAnimation(.snappy) { tab = section; compact = false } },
            scrolled: scrolled
        ))
        .background(Palette.canvas)
        .onGeometryChange(for: CGFloat.self) { $0.safeAreaInsets.bottom } action: { safeBottom = $0 }
        // On the page itself, so the glass bends what scrolls under it; set from the screen's own edge,
        // as the canvas: the bar 21pt up, the talk bubble 104pt.
        .overlay(alignment: .bottom) {
            GlassEffectContainer(spacing: 10) {
                HStack(alignment: .center, spacing: 10) {
                    SectionBar(selection: $tab, compact: $compact)
                    Spacer(minLength: 0)
                    ComposerButton(talk: $talk, dictation: dictation, open: { composer = ComposerOpening(words: $0) })
                }
            }
            .padding(.horizontal, 16)
            .padding(.bottom, max(0, 21 - safeBottom))
            .offset(y: max(0, safeBottom - 21))
        }
        .overlay(alignment: .bottomTrailing) {
            if talk != .idle {
                TalkBubble(talk: talk, dictation: dictation)
                    .padding(.trailing, 16)
                    .padding(.bottom, max(0, 104 - safeBottom))
                    .transition(.scale(scale: 0.7, anchor: .bottomTrailing).combined(with: .opacity))
            }
        }
        .animation(reduceMotion ? nil : .spring(duration: 0.35, bounce: 0.3), value: talk)
        .sheet(item: $composer) { opening in
            ComposerPage(words: opening.words).presentationDetents([.large])
        }
    }

    /// Down hides the bar's words to a circle; up, or the top of the page, brings them back.
    private func scrolled(_ offset: CGFloat) {
        let delta = offset - lastOffset
        lastOffset = offset
        let next: Bool? = offset < 40 ? false : delta > 6 ? true : delta < -6 ? false : nil
        guard let next, next != compact else { return }
        withAnimation(reduceMotion ? nil : .spring(duration: 0.4, bounce: 0.2)) { compact = next }
    }
}

/// Composer opened by a tap, or by letting go after saying something.
struct ComposerOpening: Identifiable {
    let id = UUID()
    let words: String?
}

/// The sections in a Liquid Glass capsule, as iOS 26's own tab bar: the current one sits in a lens;
/// press and slide along the bar and the lens follows the finger, a little larger, then settles on
/// the section it's let go over. Shrunk while scrolling down, only the current section shows, as a
/// glass circle that grows back when tapped.
private struct SectionBar: View {
    @Binding var selection: AppTab
    @Binding var compact: Bool
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    /// Where the finger is along the bar while it's pressed.
    @State private var finger: CGFloat?
    @State private var width: CGFloat = 290

    private let tabs = AppTab.allCases
    private var slot: CGFloat { width / CGFloat(tabs.count) }

    /// The section under the finger, else the current one.
    private var hovered: AppTab {
        guard let finger else { return selection }
        return tabs[min(tabs.count - 1, max(0, Int(finger / slot)))]
    }

    var body: some View {
        Group {
            if compact {
                Button { withAnimation(.spring(duration: 0.4, bounce: 0.2)) { compact = false } } label: {
                    Image(systemName: selection.symbol).font(.system(size: 19, weight: .medium))
                        .foregroundStyle(Palette.navTeal)
                        .frame(width: 48, height: 48).contentShape(.circle)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("\(selection.title), show all sections")
            } else {
                ZStack(alignment: .leading) {
                    lens
                    HStack(spacing: 0) {
                        ForEach(tabs) { section in item(section) }
                    }
                }
                .onGeometryChange(for: CGFloat.self) { $0.size.width } action: { width = $0 }
                .contentShape(.capsule)
                .gesture(
                    DragGesture(minimumDistance: 0)
                        .onChanged { drag in
                            withAnimation(.interactiveSpring(response: 0.25, dampingFraction: 0.8)) { finger = drag.location.x }
                        }
                        .onEnded { drag in
                            let chosen = tabs[min(tabs.count - 1, max(0, Int(drag.location.x / slot)))]
                            withAnimation(reduceMotion ? nil : .spring(duration: 0.4, bounce: 0.3)) {
                                selection = chosen
                                finger = nil
                            }
                        }
                )
                .accessibilityElement(children: .contain)
            }
        }
        .padding(4)
        .frame(height: compact ? 56 : 62)
        .frame(maxWidth: compact ? 56 : 298)
        .glassEffect(.regular.interactive(), in: .capsule)
        .sensoryFeedback(.selection, trigger: hovered)
    }

    /// The lens: under the current section at rest; under the finger, larger, while pressed.
    private var lens: some View {
        let pressed = finger != nil
        let x = pressed ? min(max((finger ?? 0) - slot / 2, -4), width - slot + 4) : CGFloat(tabs.firstIndex(of: selection) ?? 0) * slot
        return Capsule()
            .fill(Color(hex: 0x787062, opacity: pressed ? 0.1 : 0.16))
            .glassEffect(pressed ? .regular.interactive() : .identity, in: .capsule)
            .frame(width: slot, height: 54)
            .scaleEffect(pressed ? 1.12 : 1)
            .offset(x: x)
            .allowsHitTesting(false)
    }

    private func item(_ section: AppTab) -> some View {
        let on = hovered == section
        return VStack(spacing: 2) {
            Image(systemName: section.symbol).font(.system(size: 19, weight: .medium)).frame(height: 24)
            Text(section.title).font(.system(size: 10, weight: on ? .semibold : .medium)).fixedSize()
        }
        .foregroundStyle(on ? Palette.navTeal : Palette.text)
        .scaleEffect(on && finger != nil ? 1.08 : 1)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(section.title)
        .accessibilityAddTraits(selection == section ? [.isButton, .isSelected] : .isButton)
        .accessibilityAction { selection = section }
    }
}

/// The canvas's Composer button (travel.css .round.fab): a dark disc in a 3pt ring of the brand's
/// colours with a soft glow. The ring turns slowly at rest; held, it grows to 112%, turns fast and
/// glows, while Dictation listens. Sliding 36pt away cancels; letting go sends what was said.
private struct ComposerButton: View {
    @Binding var talk: ShellView.Talk
    let dictation: Dictation
    let open: (String?) -> Void
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var pressed = false
    @State private var holding: Task<Void, Never>?

    private static let colours = AngularGradient(stops: [
        .init(color: Color(hex: 0xA6F2FB), location: 0), .init(color: Color(hex: 0x05BADD), location: 0.2),
        .init(color: Color(hex: 0x0B7F95), location: 0.38), .init(color: Color(hex: 0xFF3902), location: 0.6),
        .init(color: Color(hex: 0xFFB067), location: 0.78), .init(color: Color(hex: 0xA6F2FB), location: 1),
    ], center: .center)

    var body: some View {
        let listening = talk == .listening
        TimelineView(.animation(paused: reduceMotion)) { timeline in
            // A turn every 12 s at rest, every 2.4 s while listening.
            let turn = Angle.degrees((timeline.date.timeIntervalSinceReferenceDate / (listening ? 2.4 : 12)).truncatingRemainder(dividingBy: 1) * 360)
            ZStack {
                Circle().fill(Self.colours).rotationEffect(turn)
                    .padding(listening ? -9 : -3).blur(radius: listening ? 13 : 9).opacity(listening ? 1 : talk == .away ? 0.3 : 0.45)
                Circle().fill(Self.colours).rotationEffect(turn)
                Circle().fill(Color(hex: 0x1D1A14)).padding(3)
                    .overlay { Circle().strokeBorder(.white.opacity(0.22), lineWidth: 0.5).padding(3) }
                Image(.composerSpark).resizable().frame(width: 24, height: 24).foregroundStyle(.white)
            }
        }
        .frame(width: 62, height: 62)
        .scaleEffect(listening ? 1.12 : pressed ? 0.94 : 1)
        .animation(.spring(duration: 0.35, bounce: 0.35), value: talk)
        .animation(.spring(duration: 0.25), value: pressed)
        .contentShape(.circle)
        .gesture(
            DragGesture(minimumDistance: 0)
                .onChanged { drag in
                    if !pressed {
                        pressed = true
                        holding = Task {
                            try? await Task.sleep(for: .milliseconds(280))
                            guard !Task.isCancelled else { return }
                            talk = .listening
                            await dictation.start()
                            if dictation.state == .unavailable { talk = .idle; pressed = false; open(nil); return }
                            // The first time, iOS asks for permission over the button; a finger lifted meanwhile ends the hold.
                            if !pressed { dictation.cancel(); talk = .idle }
                        }
                    }
                    guard talk != .idle else { return }
                    let away = hypot(drag.translation.width, drag.translation.height) > 36
                    talk = away ? .away : .listening
                }
                .onEnded { _ in
                    holding?.cancel()
                    pressed = false
                    switch talk {
                    case .idle: open(nil)
                    case .away: dictation.cancel(); talk = .idle
                    case .listening:
                        let said = dictation.finish()
                        talk = .idle
                        if !said.isEmpty { open(said) }
                    }
                }
        )
        .sensoryFeedback(.impact(weight: .medium), trigger: talk == .listening)
        .accessibilityElement()
        .accessibilityLabel("Composer")
        .accessibilityHint("Double-tap to open. Hold to say what you need.")
        .accessibilityAddTraits(.isButton)
        .accessibilityAction { open(nil) }
    }
}

/// What you're saying, in a glass bubble over the button, with the voice bars (the canvas's .fab-say).
private struct TalkBubble: View {
    let talk: ShellView.Talk
    let dictation: Dictation

    var body: some View {
        VStack(alignment: .trailing, spacing: 8) {
            if talk == .listening {
                Text("Let go to ask · slide away to cancel")
                    .font(.geist(12.5, .medium)).foregroundStyle(.white)
                    .padding(.horizontal, 10).padding(.vertical, 3)
                    .background(Color(hex: 0x14120E, opacity: 0.55), in: .rect(cornerRadius: 10))
            }
            HStack(spacing: 10) {
                VoiceBars(level: talk == .listening ? dictation.level : 0)
                Text(talk == .away ? "Let go to cancel" : dictation.words.isEmpty ? "Listening…" : dictation.words)
                    .font(.geist(16, .medium)).foregroundStyle(Palette.text)
                    .lineLimit(3)
                    .contentTransition(.opacity)
            }
            .padding(.leading, 14).padding(.trailing, 18).padding(.vertical, 12)
            .frame(minHeight: 48)
            .frame(maxWidth: 320, alignment: .leading)
            .glassEffect(.regular, in: .rect(cornerRadius: 24))
        }
        .animation(.snappy, value: dictation.words)
        .accessibilityElement(children: .combine)
    }
}

/// Four bars, cyan to orange, rising and falling (.fab-bars), taller the louder the voice.
private struct VoiceBars: View {
    let level: Double
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        TimelineView(.animation(paused: reduceMotion)) { timeline in
            let t = timeline.date.timeIntervalSinceReferenceDate
            HStack(spacing: 3) {
                ForEach(Array([0, 0.14, 0.28, 0.09].enumerated()), id: \.offset) { _, delay in
                    let wave = (1 - cos(2 * .pi * (t - delay) / 0.8)) / 2
                    Capsule()
                        .fill(LinearGradient(colors: [Palette.brandCyan, Palette.brandOrange], startPoint: .top, endPoint: .bottom))
                        .frame(width: 3.5, height: 20 * (0.3 + 0.7 * wave * (0.45 + 0.55 * level)))
                }
            }
            .frame(height: 22)
        }
        .accessibilityHidden(true)
    }
}

/// Composer's page until it's built (next after Paperwork): what was said waits here as the question.
private struct ComposerPage: View {
    let words: String?
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            VStack(alignment: .leading, spacing: 12) {
                if let words {
                    Text("You asked").font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                    Text(words).font(.posterGrotesque(24)).foregroundStyle(Palette.text)
                }
                Spacer()
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(20)
            .background(Palette.canvas)
            .navigationTitle("Composer")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done", systemImage: "checkmark") { dismiss() } } }
        }
    }
}

private struct ShellHeader: View {
    let today: TodayModel
    @State private var alertsOpen = false

    var body: some View {
        HStack(spacing: 8) {
            // The brand waves when Today opens; a tap takes Today to its top and waves again.
            Button { today.brandTapped() } label: {
                HStack(spacing: 5) {
                    WavingMark(since: today.waveSince).frame(width: 31, height: 31)
                    Text("Expadu").font(.geist(25, .bold)).tracking(-1.1)
                }
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Expadu, back to the top")
            Spacer(minLength: 0)
            Button { alertsOpen = true } label: {
                Image(systemName: "bell.fill").font(.system(size: 18, weight: .medium))
                    .frame(width: 44, height: 44)
                    .glassEffect(.regular.interactive(), in: .circle)
                    .overlay(alignment: .topTrailing) {
                        if today.alerts > 0 {
                            Text("\(today.alerts)")
                                .font(.geist(11, .bold)).foregroundStyle(.white)
                                .padding(.horizontal, 5).frame(minWidth: 18, minHeight: 18)
                                .background(Color(hex: 0xD64528), in: .capsule)
                                .overlay { Capsule().strokeBorder(Palette.canvas, lineWidth: 2).padding(-2) }
                                .offset(x: -3, y: 4)
                                .transition(.scale.combined(with: .opacity))
                        }
                    }
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel(today.alerts > 0 ? "Alerts, \(today.alerts)" : "Alerts")
            AccountButton()
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 16)
        .padding(.top, 4).padding(.bottom, 10)
        .background {
            // The canvas's .t-topfade: the page slides away under the header.
            LinearGradient(stops: [.init(color: Palette.canvas, location: 0.8), .init(color: Palette.canvas.opacity(0), location: 1)], startPoint: .top, endPoint: .bottom)
                .ignoresSafeArea(edges: .top)
                .allowsHitTesting(false)
        }
        .animation(.snappy, value: today.alerts)
        .sheet(isPresented: $alertsOpen) {
            AlertsSheet(needs: today.page?.needs ?? [])
        }
    }
}

/// The account: for now, signing out.
struct AccountButton: View {
    @Environment(Session.self) private var session

    var body: some View {
        Menu {
            Text(session.email)
            Button("Sign out", role: .destructive) { Task { await session.signOut() } }
        } label: {
            Text(session.initial)
                .font(.geist(14, .semibold))
                .foregroundStyle(Palette.avatarText)
                .frame(width: 36, height: 36)
                .background(Palette.avatarBackground, in: .circle)
                .frame(width: 44, height: 44)
                .contentShape(.circle)
        }
        .accessibilityLabel("Your account")
    }
}

/// The notes that need the person, from the bell: each says what and why, and goes away when it's done.
private struct AlertsSheet: View {
    let needs: [TodayPage.Need]
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                if needs.isEmpty {
                    Text("Nothing needs you right now.").font(.geist(15)).foregroundStyle(Palette.textSoft)
                        .listRowBackground(Palette.surface)
                }
                ForEach(needs) { need in
                    HStack(alignment: .top, spacing: 12) {
                        Circle().fill(NeedTone.color(need.severity)).frame(width: 9, height: 9).padding(.top, 6)
                        VStack(alignment: .leading, spacing: 3) {
                            Text(need.headline).font(.geist(15, .semibold))
                            if let detail = need.detail { Text(detail).font(.geist(13.5)).foregroundStyle(Palette.textSoft) }
                        }
                    }
                    .foregroundStyle(Palette.text)
                    .listRowBackground(Palette.surface)
                }
            }
            .scrollContentBackground(.hidden)
            .background(Palette.canvas)
            .navigationTitle("Alerts")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done", systemImage: "checkmark") { dismiss() }
                }
            }
        }
        .presentationDetents([.medium, .large])
    }
}

/// Empty until the section's own milestone: nothing here is sample data.
private struct SectionPage: View {
    let title: String

    var body: some View {
        ZStack {
            Palette.canvas.ignoresSafeArea()
            Text(title)
                .font(.geist(17, .semibold))
                .foregroundStyle(Palette.textSoft)
        }
    }
}
