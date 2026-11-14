import Foundation

/// Where a link into the app goes. Paths mirror `app.expadu.com`.
public enum DeepLink: Equatable, Sendable {
    case today
    case paperwork
    case travel
    case lab
    /// From the trip's Live Activity: I'm on my way, a new way from here, the trip's steps or its map,
    /// and I'm there.
    case tripStart
    case tripNewWay
    case tripSteps
    case tripMap
    case tripThere

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
            case "steps": self = .tripSteps
            case "map": self = .tripMap
            case "there": self = .tripThere
            default: return nil
            }
        default: return nil
        }
    }
}
