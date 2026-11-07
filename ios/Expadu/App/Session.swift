import API
import Core
import Observation
import UIKit

/// Who is signed in. The whole app reads this one place; the token lives in the Keychain.
@Observable
final class Session {
    private(set) var stored = TokenKeychain.load()
    let api: AppAPI

    init() {
        api = AppAPI(baseURL: AppConfig.api, device: UIDevice.current.name) { TokenKeychain.load()?.token.value }
    }

    var isSignedIn: Bool { stored != nil }
    /// The app's pages ask for a confirmed address, as the web's do.
    var isReady: Bool { stored?.account.emailVerified ?? false }
    var initial: String { stored?.account.initial ?? "?" }
    var email: String { stored?.account.email ?? "" }
    var name: String { stored?.account.name ?? "" }

    func adopt(_ session: StoredSession) {
        TokenKeychain.save(session)
        stored = session
    }

    /// On opening the app: a fresh token well before the old one ends, and the account as it is now.
    func check() async {
        guard let current = stored else { return }
        do throws(APIFailure) {
            var token = current.token
            if token.wantsRefresh() {
                token = try await api.refresh().token
            }
            adopt(StoredSession(token: token, account: try await api.me()))
        } catch .signedOut {
            forget()
        } catch {
            // Offline or the server is away: keep what we have and try again next time.
        }
    }

    func signOut() async {
        try? await api.signOut()
        forget()
    }

    /// Signed out on this phone only, e.g. to start again with another address.
    func forget() {
        TokenKeychain.clear()
        stored = nil
    }
}
