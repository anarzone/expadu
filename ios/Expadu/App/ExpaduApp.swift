import Core
import DesignSystem
import SwiftUI

@main
struct ExpaduApp: App {
    @State private var session = Session()
    #if LAB
    @State private var labOpen = false
    #endif

    init() {
        Typeface.register()
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(session)
                #if LAB
                .overlay(alignment: .top) {
                    if labOpen {
                        MotionLab(isOpen: $labOpen)
                    }
                }
                .onReceive(NotificationCenter.default.publisher(for: .deviceDidShake)) { _ in
                    labOpen.toggle()
                }
                .onOpenURL { url in
                    if DeepLink(url: url) == .lab {
                        labOpen = true
                    }
                }
                #endif
        }
    }
}

struct RootView: View {
    @Environment(Session.self) private var session
    @Environment(\.scenePhase) private var phase

    var body: some View {
        Group {
            if session.isReady {
                ShellView()
            } else {
                SignInView()
            }
        }
        // A tap outside a text field puts the keyboard away, everywhere.
        .background { KeyboardDismissal() }
        // Each return to the app: the token renewed in good time, and a just-confirmed address let in.
        .onChange(of: phase, initial: true) {
            if phase == .active {
                Task { await session.check() }
            }
        }
    }
}
