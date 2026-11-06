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

    var icon: ImageResource {
        switch self {
        case .today: .navToday
        case .paperwork: .navPaperwork
        case .travel: .navTravel
        }
    }

    var tint: Color {
        self == .paperwork ? Palette.navRust : Palette.navTeal
    }
}

/// The mock's phone shell: one header, a floating pill of sections and the round Composer button.
struct ShellView: View {
    @State private var tab = AppTab.today
    @State private var composerOpen = false

    var body: some View {
        VStack(spacing: 0) {
            // Travel is its line's colour to the top, with its own header (the canvas, 6 Nov).
            if tab != .travel {
                ShellHeader()
            }
            TabView(selection: $tab) {
                ForEach(AppTab.allCases) { section in
                    Tab(value: section) {
                        Group {
                            switch section {
                            case .today: TodayView()
                            case .travel: TravelView()
                            default: SectionPage(title: section.title)
                            }
                        }
                        .toolbarVisibility(.hidden, for: .tabBar)
                    }
                }
            }
        }
        .background(Palette.canvas)
        .safeAreaInset(edge: .bottom, spacing: 0) {
            // As the app's --menu-gap: 16, or the home indicator's inset when that is more.
            GeometryReader { proxy in
                HStack(alignment: .center, spacing: 10) {
                    SectionPill(selection: $tab)
                    ComposerButton { composerOpen = true }
                }
                .padding(.horizontal, 14)
                .padding(.bottom, max(16 - proxy.safeAreaInsets.bottom, 0))
            }
            .frame(height: 62)
        }
        .ignoresSafeArea(.keyboard)
        .sheet(isPresented: $composerOpen) {
            SectionPage(title: "Composer").presentationDetents([.medium, .large])
        }
    }
}

private struct ShellHeader: View {
    @Environment(Session.self) private var session

    var body: some View {
        HStack(spacing: 0) {
            HStack(spacing: 4) {
                ExpaduMark().frame(width: 28, height: 30)
                Text("Expadu").font(.geist(24, .bold)).tracking(-1.1)
            }
            .accessibilityElement(children: .combine)
            .accessibilityLabel("Expadu home")
            Spacer(minLength: 0)
            HStack(spacing: 2) {
                Text("Cologne").font(.geist(12))
                Image(.headerCaret).resizable().frame(width: 14, height: 14)
            }
            .foregroundStyle(Palette.textSoft)
            .padding(.horizontal, 5)
            .frame(height: 44)
            Image(.headerSaved).resizable().frame(width: 22, height: 22)
                .frame(width: 44, height: 44)
                .accessibilityLabel("Saved")
            AccountButton()
        }
        .foregroundStyle(Palette.text)
        .padding(.horizontal, 16)
        .frame(height: 54)
        .background {
            Palette.header
                .ignoresSafeArea(edges: .top)
                .shadow(color: Color(hex: 0x514538, opacity: 0.08), radius: 0, y: 1)
        }
        .zIndex(1)
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
                .font(.geist(13, .semibold))
                .foregroundStyle(Palette.avatarText)
                .frame(width: 32, height: 32)
                .background(Palette.avatarBackground, in: .circle)
                .frame(width: 44, height: 44)
        }
        .accessibilityLabel("Your account")
    }
}

private struct SectionPill: View {
    @Binding var selection: AppTab
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Namespace private var pill

    var body: some View {
        HStack(spacing: 2) {
            ForEach(AppTab.allCases) { section in
                Button {
                    withAnimation(MotionStore.shared.animation(.navSelect, reduceMotion: reduceMotion)) {
                        selection = section
                    }
                } label: {
                    VStack(spacing: 2) {
                        Image(section.icon).resizable().frame(width: 20, height: 20)
                            .foregroundStyle(selection == section ? section.tint : Palette.textSoft)
                        Text(section.title)
                            .font(.geist(11.5, selection == section ? .bold : .medium))
                            .foregroundStyle(Palette.text)
                    }
                    .frame(maxWidth: .infinity).frame(height: 52)
                    .background {
                        if selection == section {
                            Capsule().fill(Palette.selectBackground).matchedGeometryEffect(id: "current", in: pill)
                        }
                    }
                    .contentShape(.capsule)
                }
                .buttonStyle(PressStyle())
                .accessibilityAddTraits(selection == section ? .isSelected : [])
            }
        }
        .padding(4)
        .frame(height: 62)
        .background(Palette.header, in: .capsule)
        .overlay { Capsule().strokeBorder(Palette.border.opacity(0.85)) }
        .cssShadow(Capsule(), y: 14, blur: 34, spread: -16, color: Color(hex: 0x211D15, opacity: 0.42))
        .onChange(of: MotionStore.shared.replays[.navSelect]) {
            let all = AppTab.allCases
            let next = all[((all.firstIndex(of: selection) ?? 0) + 1) % all.count]
            withAnimation(MotionStore.shared.animation(.navSelect, reduceMotion: reduceMotion)) {
                selection = next
            }
        }
    }
}

private struct ComposerButton: View {
    let open: () -> Void

    var body: some View {
        Button(action: open) {
            Image(.composerSpark).resizable().frame(width: 20, height: 20)
                .foregroundStyle(.white)
                .frame(width: 62, height: 62)
                .background(Palette.launcher, in: .circle)
                .cssShadow(Circle(), y: 4, blur: 14, color: Color(hex: 0x183F44, opacity: 0.15))
        }
        .buttonStyle(PressStyle())
        .accessibilityLabel("Open Composer")
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
