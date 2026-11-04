import Observation

/// Who is signed in. The whole app reads this one place.
@Observable
final class Session {
    private(set) var isSignedIn = false
    private(set) var initial = "?"

    func signIn(initial: String) {
        self.initial = initial
        isSignedIn = true
    }

    func signOut() {
        isSignedIn = false
    }
}
