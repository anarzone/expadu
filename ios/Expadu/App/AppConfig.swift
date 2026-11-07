import Foundation

enum AppConfig {
    /// The app's server. A debug build can point at another one with the `server` default,
    /// e.g. `xcrun simctl spawn booted defaults write com.expadu.app server http://localhost:8095`.
    static let server: URL = {
        #if DEBUG
        if let custom = UserDefaults.standard.string(forKey: "server"), let url = URL(string: custom) {
            return url
        }
        return URL(string: "http://localhost:8080")!
        #else
        return URL(string: "https://app.expadu.com")!
        #endif
    }()

    static var api: URL { server.appending(path: "api/v1") }
}
