import API
import AuthenticationServices
import Core
import DesignSystem
import SwiftUI

/// "On the street" (prototype/dev/design/account): Cologne's street behind, the form a cream
/// poster taped to the wall. Each state is drawn from the mock's own wording.
struct SignInView: View {
    enum Step: Equatable {
        case signIn, mismatch, twoFactor, create, checkEmail, forgot, sent
    }

    @Environment(Session.self) private var session
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var step = Step.signIn
    @State private var email = ""
    @State private var name = ""
    @State private var password = ""
    @State private var keepSignedIn = true
    @State private var pasted = true
    @State private var busy = false
    @State private var problem: String?
    @State private var challenge: String?
    @State private var code = ""
    @State private var appleNonce = ""
    @FocusState private var focus: Field?
    @Environment(\.webAuthenticationSession) private var webAuth

    enum Field { case name, email, password, code }

    var body: some View {
        GeometryReader { outer in
            let top = outer.safeAreaInsets.top
            ScrollView {
                ZStack(alignment: .top) {
                    StreetStrip(safeTop: top)
                    topBar.padding(.top, top + 2).padding(.horizontal, 16)
                    poster
                        .padding(.top, top + 141)
                        .padding(.horizontal, 16)
                        .padding(.bottom, 32)
                }
                .frame(maxWidth: 520 + 32)
                .frame(maxWidth: .infinity)
            }
            .ignoresSafeArea(edges: .top)
            .scrollDismissesKeyboard(.interactively)
            .background(Palette.street)
        }
        .environment(\.colorScheme, .light)
        .onChange(of: step) {
            problem = nil
            paste()
        }
        .onAppear {
            // Signed in with an address still to confirm: the poster says so.
            if session.isSignedIn {
                email = session.email
                step = .checkEmail
            }
        }
        .onChange(of: MotionStore.shared.replays[.posterPaste]) { paste() }
    }

    private var topBar: some View {
        HStack(spacing: 12) {
            HStack(spacing: 8) {
                ExpaduMark().frame(width: 24, height: 20)
                Text("Expadu").font(.geist(18, .bold)).tracking(-0.18)
            }
            .padding(.leading, 10).padding(.trailing, 14)
            .streetPill()
            .accessibilityElement(children: .combine)
            Spacer(minLength: 0)
            Button(switchAction) { step = switchTarget }
                .buttonStyle(PressStyle())
                .font(.geist(15, .bold))
                .padding(.horizontal, 14)
                .streetPill()
        }
        .foregroundStyle(Palette.text)
    }

    private var switchTarget: Step {
        switch step {
        case .create, .checkEmail: .signIn
        default: .create
        }
    }

    private var switchAction: String {
        switchTarget == .signIn ? "Sign in" : "Create an account"
    }

    private var poster: some View {
        VStack(alignment: .leading, spacing: 12) {
            content
        }
        .padding(.top, 26).padding(.horizontal, 22).padding(.bottom, 22)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Palette.posterPaper)
        .cssShadow(Rectangle(), y: 26, blur: 50, spread: -22, color: Color(hex: 0x211D15, opacity: 0.65))
        .cssShadow(Rectangle(), y: 2, blur: 0, color: Color(hex: 0x211D15, opacity: 0.08))
        .overlay(alignment: .topLeading) { tape(degrees: -5).offset(x: 30, y: -11) }
        .overlay(alignment: .topTrailing) { tape(degrees: 6).offset(x: -30, y: -11) }
        .rotationEffect(.degrees(pasted ? -1 : -3))
        .offset(y: pasted ? 0 : -10)
        .opacity(pasted ? 1 : 0)
    }

    private func tape(degrees: Double) -> some View {
        Rectangle()
            .fill(Palette.posterYellow.opacity(0.77))
            .frame(width: 72, height: 22)
            .rotationEffect(.degrees(degrees))
    }

    @ViewBuilder
    private var content: some View {
        switch step {
        case .signIn, .mismatch:
            label("Hallo again")
            heading("Welcome back")
            if step == .mismatch {
                MismatchMessage { step = .forgot }
            } else if let problem {
                ProblemMessage(text: problem)
            } else {
                lead("Sign in to see your day.")
            }
            social
            emailField
            passwordField(label: "Password", forgot: true, content: .password)
            KeepSignedIn(isOn: $keepSignedIn)
            primary(busy ? "Signing in…" : "Sign in", action: submitSignIn)
        case .twoFactor:
            back
            label("Noch ein Schritt")
            heading("Enter your code")
            if let problem { ProblemMessage(text: problem) } else { lead("Open your authenticator app and type the six-digit code.") }
            PosterField(label: "Code", text: $code, focus: $focus, field: .code, content: .oneTimeCode, keyboard: .numberPad)
            primary(busy ? "Checking…" : "Continue") {
                let challenge = challenge ?? ""
                let code = code
                run { [api = session.api] () async throws(APIFailure) -> SignInOutcome in
                    try await api.answerTwoFactor(challenge: challenge, code: code, recoveryCode: nil)
                }
            }
        case .create:
            label("Willkommen in Köln")
            heading("New in Cologne? Start here.")
            if let problem { ProblemMessage(text: problem) } else { lead("Free to start. Cologne, for now.") }
            social
            PosterField(label: "Name", text: $name, focus: $focus, field: .name, content: .name)
            emailField
            passwordField(label: "Password", forgot: false, content: .newPassword)
            Text("At least 8 characters.").font(.geist(14)).foregroundStyle(Palette.textSoft).padding(.top, -6)
            primary(busy ? "Creating…" : "Create account") {
                focus = nil
                let (name, email, password) = (name, email, password)
                run { [api = session.api] () async throws(APIFailure) -> SignInOutcome in
                    try await api.register(name: name, email: email, password: password)
                }
            }
            Text("We handle your details as our [privacy policy](https://app.expadu.com/datenschutz) describes.")
                .font(.geist(14)).foregroundStyle(Palette.textSoft).tint(Palette.text)
        case .checkEmail:
            label("Fast geschafft")
            heading("Check your email")
            Text("We sent a link to \(Text(email).font(.geist(16, .bold))). Open it to finish creating your account.")
                .font(.geist(16)).lineSpacing(4)
            Text("It works for 60 minutes. Not there? Look in spam.").font(.geist(14)).foregroundStyle(Palette.textSoft)
            if let problem { ProblemMessage(text: problem) }
            secondary("Send the link again") {
                attempt { [api = session.api] () async throws(APIFailure) in try await api.resendVerification() }
            }
            quiet("Wrong email? Start again") {
                session.forget()
                step = .create
            }
        case .forgot:
            back
            label("Kein Problem")
            heading("Reset your password")
            if let problem { ProblemMessage(text: problem) } else { lead("We’ll email you a link to choose a new one.") }
            emailField
            primary(busy ? "Sending…" : "Send the link", action: sendReset)
        case .sent:
            back
            label("Kein Problem")
            heading("Reset your password")
            Text("The link is on its way to \(Text(email).font(.geist(15, .bold))). It works for 60 minutes.")
                .font(.geist(15)).padding(.vertical, 12).padding(.horizontal, 14)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Palette.quiet, in: .rect(cornerRadius: 12))
            secondary("Send it again", action: sendReset)
        }
    }

    private func label(_ text: String) -> some View {
        Text(text.uppercased())
            .font(.posterMarker(16))
            .foregroundStyle(Palette.text)
            .padding(.vertical, 4).padding(.horizontal, 12)
            .background(Palette.posterYellow)
            .rotationEffect(.degrees(-2))
    }

    private func heading(_ text: String) -> some View {
        Text(text.uppercased())
            .font(.posterGrotesque(34))
            .tracking(-0.34)
            .lineSpacing(-3)
            .foregroundStyle(Palette.posterPurple)
            .padding(.top, 2)
            .accessibilityAddTraits(.isHeader)
            .accessibilityLabel(text)
    }

    private func lead(_ text: String) -> some View {
        Text(text).font(.geist(15)).foregroundStyle(Palette.posterLead).padding(.bottom, 4)
    }

    private var social: some View {
        Group {
            SignInWithAppleButton(.continue) { request in
                appleNonce = SignInProof.nonce()
                request.requestedScopes = [.fullName, .email]
                request.nonce = SignInProof.hashed(appleNonce)
            } onCompletion: { result in
                apple(result)
            }
            .signInWithAppleButtonStyle(.black)
            .frame(height: 50)
            .clipShape(.rect(cornerRadius: 12))
            Button(action: google) {
                HStack(spacing: 8) {
                    Image(.signinGoogle).resizable().frame(width: 18, height: 18)
                    Text("Continue with Google").font(.geist(16, .semibold))
                }
                .frame(maxWidth: .infinity).frame(height: 50)
                .background(Palette.surface, in: .rect(cornerRadius: 12))
                .overlay { RoundedRectangle(cornerRadius: 12).strokeBorder(Palette.text, lineWidth: 1.5) }
            }
            .buttonStyle(PressStyle())
            .foregroundStyle(Palette.text)
            HStack(spacing: 12) {
                Rectangle().fill(Palette.posterRule).frame(height: 1)
                Text("or with email").font(.geist(14)).foregroundStyle(Palette.textSoft).fixedSize()
                Rectangle().fill(Palette.posterRule).frame(height: 1)
            }
            .accessibilityElement(children: .combine)
        }
    }

    private var emailField: some View {
        PosterField(label: "Email", text: $email, focus: $focus, field: .email, content: .username, keyboard: .emailAddress)
    }

    private func passwordField(label: String, forgot: Bool, content: UITextContentType) -> some View {
        PosterPasswordField(label: label, text: $password, focus: $focus, content: content, forgot: forgot ? { step = .forgot } : nil)
    }

    private func primary(_ title: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(title).font(.geist(17, .semibold)).foregroundStyle(Palette.onAction)
                .frame(maxWidth: .infinity).frame(height: 52)
                .background(Palette.action, in: .rect(cornerRadius: 12))
        }
        .buttonStyle(PressStyle())
        .padding(.top, 4)
    }

    private func secondary(_ title: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(title).font(.geist(17, .semibold)).foregroundStyle(Palette.text)
                .frame(maxWidth: .infinity).frame(height: 52)
                .background(Palette.surface, in: .rect(cornerRadius: 12))
                .overlay { RoundedRectangle(cornerRadius: 12).strokeBorder(Palette.text, lineWidth: 1.5) }
        }
        .buttonStyle(PressStyle())
        .padding(.top, 4)
    }

    private func quiet(_ title: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(title).font(.geist(16, .medium)).foregroundStyle(Palette.text).frame(minHeight: 44)
        }
        .frame(maxWidth: .infinity)
    }

    private var back: some View {
        Button { step = .signIn } label: {
            Text("← Back to sign in").font(.geist(15, .semibold)).foregroundStyle(Palette.text).frame(minHeight: 44)
        }
        .padding(.top, -10).padding(.bottom, -4)
    }

    private func submitSignIn() {
        focus = nil
        let (email, password, remember) = (email, password, keepSignedIn)
        run(wrongPasswordIsMismatch: true) { [api = session.api] () async throws(APIFailure) -> SignInOutcome in
            try await api.signIn(email: email, password: password, remember: remember)
        }
    }

    private func sendReset() {
        focus = nil
        let email = email
        attempt({ [api = session.api] () async throws(APIFailure) in try await api.forgotPassword(email: email) }) { step = .sent }
    }

    /// Google's own page in a sheet; its answer comes back as a one-time code only this app can use.
    private func google() {
        let verifier = SignInProof.verifier()
        var start = URLComponents(url: AppConfig.server.appending(path: "auth/google/native"), resolvingAgainstBaseURL: false)!
        start.queryItems = [URLQueryItem(name: "challenge", value: SignInProof.challenge(for: verifier))]
        Task {
            guard let back = try? await webAuth.authenticate(using: start.url!, callback: .customScheme("expadu"), preferredBrowserSession: .ephemeral, additionalHeaderFields: [:]) else {
                return // Closed by the person: nothing to say.
            }
            let query = URLComponents(url: back, resolvingAgainstBaseURL: false)?.queryItems ?? []
            guard let code = query.first(where: { $0.name == "code" })?.value else {
                problem = "Signing in with Google didn’t finish. Try again, or use your email."
                return
            }
            run { [api = session.api] () async throws(APIFailure) -> SignInOutcome in
                try await api.exchange(code: code, verifier: verifier)
            }
        }
    }

    private func apple(_ result: Result<ASAuthorization, any Error>) {
        switch result {
        case let .success(authorization):
            guard let credential = authorization.credential as? ASAuthorizationAppleIDCredential,
                  let data = credential.identityToken, let token = String(data: data, encoding: .utf8)
            else {
                problem = "Signing in with Apple didn’t finish. Try again, or use your email."
                return
            }
            // Apple gives the name once, on the first sign-in only.
            let name = credential.fullName.map { PersonNameComponentsFormatter().string(from: $0) }.flatMap { $0.isEmpty ? nil : $0 }
            let nonce = appleNonce
            run { [api = session.api] () async throws(APIFailure) -> SignInOutcome in
                try await api.signInWithApple(identityToken: token, nonce: nonce, name: name)
            }
        case let .failure(error):
            if (error as? ASAuthorizationError)?.code != .canceled {
                problem = "Signing in with Apple didn’t finish. Try again, or use your email."
            }
        }
    }

    /// A sign-in call: signed in, asked for the two-step code, or told why not.
    private func run(wrongPasswordIsMismatch: Bool = false, _ call: @escaping () async throws(APIFailure) -> SignInOutcome) {
        guard !busy else { return }
        busy = true
        problem = nil
        Task {
            defer { busy = false }
            do throws(APIFailure) {
                switch try await call() {
                case let .signedIn(stored):
                    session.adopt(stored)
                    if !stored.account.emailVerified {
                        email = stored.account.email
                        step = .checkEmail
                    }
                case let .twoFactor(next):
                    challenge = next
                    code = ""
                    step = .twoFactor
                }
            } catch .refused where wrongPasswordIsMismatch {
                step = .mismatch
            } catch {
                problem = Self.say(error)
            }
        }
    }

    /// Any other call: done, or told why not.
    private func attempt(_ call: @escaping () async throws(APIFailure) -> Void, then done: @escaping () -> Void = {}) {
        guard !busy else { return }
        busy = true
        problem = nil
        Task {
            defer { busy = false }
            do throws(APIFailure) {
                try await call()
                done()
            } catch {
                problem = Self.say(error)
            }
        }
    }

    private static func say(_ failure: APIFailure) -> String {
        switch failure {
        case let .refused(reason): reason
        case .tooManyTries: "Too many tries. Wait a minute, then try again."
        case .signedOut: "You’ve been signed out. Sign in again."
        case .unreachable: "Couldn’t reach Expadu. Check your connection and try again."
        }
    }

    private func paste() {
        pasted = false
        withAnimation(MotionStore.shared.animation(.posterPaste, reduceMotion: reduceMotion)) {
            pasted = true
        }
    }
}

/// A strip of skyline above the poster, reaching up under the status bar.
private struct StreetStrip: View {
    let safeTop: CGFloat

    var body: some View {
        GeometryReader { proxy in
            // The mock's `auto 520px` at 68%, grown so the Dom clears the status bar.
            let height = 520 * ((220 + safeTop) / 220) * 0.95
            let width = height * 2400 / 1252
            if let image = UIImage(named: "street.webp") {
                Image(uiImage: image)
                    .resizable()
                    .frame(width: width, height: height)
                    .offset(x: (proxy.size.width - width) * 0.68)
                    .frame(width: proxy.size.width, height: proxy.size.height, alignment: .topLeading)
                    .clipped()
            }
        }
        .frame(height: 220 + safeTop)
        .accessibilityHidden(true)
    }
}

private struct StreetPill: ViewModifier {
    func body(content: Content) -> some View {
        content
            .frame(height: 40)
            .background(Palette.surface, in: .capsule)
            .cssShadow(Capsule(), y: 6, blur: 18, spread: -10, color: Color(hex: 0x211D15, opacity: 0.6))
    }
}

extension View {
    fileprivate func streetPill() -> some View { modifier(StreetPill()) }
}

private struct MismatchMessage: View {
    let reset: () -> Void

    var body: some View {
        Button(action: reset) {
            Text("That email and password don’t match. Check both, or \(Text("reset your password").font(.geist(15, .semibold))).")
        }
        .font(.geist(15))
        .multilineTextAlignment(.leading)
        .foregroundStyle(Palette.conflictText)
        .padding(.vertical, 12).padding(.horizontal, 14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Palette.conflictBackground, in: .rect(cornerRadius: 12))
        .overlay { RoundedRectangle(cornerRadius: 12).strokeBorder(Palette.conflictBorder) }
    }
}

private struct ProblemMessage: View {
    let text: String

    var body: some View {
        Text(text)
            .font(.geist(15))
            .foregroundStyle(Palette.conflictText)
            .padding(.vertical, 12).padding(.horizontal, 14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Palette.conflictBackground, in: .rect(cornerRadius: 12))
            .overlay { RoundedRectangle(cornerRadius: 12).strokeBorder(Palette.conflictBorder) }
            .transition(.opacity.combined(with: .move(edge: .top)))
    }
}

private struct KeepSignedIn: View {
    @Binding var isOn: Bool

    var body: some View {
        Button { isOn.toggle() } label: {
            HStack(spacing: 10) {
                ZStack {
                    RoundedRectangle(cornerRadius: 3)
                        .fill(isOn ? Palette.action : Color.white)
                        .strokeBorder(isOn ? Palette.action : Palette.borderStrong)
                    if isOn {
                        Image(systemName: "checkmark").font(.system(size: 13, weight: .bold)).foregroundStyle(.white)
                    }
                }
                .frame(width: 20, height: 20)
                Text("Keep me signed in").font(.geist(16)).foregroundStyle(Palette.text)
            }
            .frame(minHeight: 44)
        }
        .accessibilityValue(isOn ? "On" : "Off")
    }
}

private struct PosterField: View {
    let label: String
    @Binding var text: String
    var focus: FocusState<SignInView.Field?>.Binding
    let field: SignInView.Field
    let content: UITextContentType
    var keyboard: UIKeyboardType = .default

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(label).font(.geist(15, .semibold)).foregroundStyle(Palette.text)
            TextField("", text: $text)
                .textContentType(content)
                .keyboardType(keyboard)
                .textInputAutocapitalization(keyboard == .emailAddress ? .never : .words)
                .autocorrectionDisabled()
                .focused(focus, equals: field)
                .accessibilityLabel(label)
                .posterInput(focused: focus.wrappedValue == field)
        }
    }
}

private struct PosterPasswordField: View {
    let label: String
    @Binding var text: String
    var focus: FocusState<SignInView.Field?>.Binding
    let content: UITextContentType
    let forgot: (() -> Void)?
    @State private var shown = false

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .firstTextBaseline) {
                Text(label).font(.geist(15, .semibold)).foregroundStyle(Palette.text)
                Spacer()
                if let forgot {
                    Button("Forgot password?", action: forgot)
                        .font(.geist(15, .medium)).underline().foregroundStyle(Palette.text)
                }
            }
            HStack(spacing: 0) {
                Group {
                    if shown {
                        TextField("", text: $text).autocorrectionDisabled().textInputAutocapitalization(.never)
                    } else {
                        SecureField("", text: $text)
                    }
                }
                .textContentType(content)
                .focused(focus, equals: .password)
                .accessibilityLabel(label)
                Button(shown ? "Hide" : "Show") { shown.toggle() }
                    .font(.geist(15, .medium)).foregroundStyle(Palette.text)
                    .frame(height: 40).padding(.horizontal, 12)
            }
            .posterInput(focused: focus.wrappedValue == .password)
        }
    }
}

extension View {
    fileprivate func posterInput(focused: Bool) -> some View {
        font(.geist(17))
            .foregroundStyle(Palette.text)
            .padding(.leading, 14).padding(.trailing, 6)
            .frame(height: 48)
            .background(Color.white, in: .rect(cornerRadius: 12))
            .overlay {
                RoundedRectangle(cornerRadius: 12).strokeBorder(focused ? Palette.focus : Palette.borderStrong, lineWidth: focused ? 2 : 1)
            }
    }
}
