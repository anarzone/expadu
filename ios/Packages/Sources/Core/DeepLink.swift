import Foundation

/// Where a link into the app goes. Paths mirror `app.expadu.com`.
public enum DeepLink: Equatable, Sendable {
    case today
    case paperwork
    case travel
    case lab
    /// From the trip's Live Activity: I'm on my way, and a new way from here.
    case tripStart
    case tripNewWay

    public init?(url: URL) {
        let first: String
        switch url.scheme {
        case "expadu":
            first = url.host() ?? ""
        case "https" where url.host() == "app.expadu.com":
            first = url.pathComponents.dropFirst().first ?? ""
        default:
            return nil
        }
        switch first {
        case "today": self = .today
        case "paperwork": self = .paperwork
        case "travel": self = .travel
        case "lab" where url.scheme == "expadu": self = .lab
        case "trip" where url.scheme == "expadu":
            switch url.pathComponents.dropFirst().first {
            case "start": self = .tripStart
            case "new-way": self = .tripNewWay
            default: return nil
            }
        default: return nil
        }
    }
}
