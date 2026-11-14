import Foundation
import Testing
@testable import Core

@Test func webPathsOpenTheirTab() throws {
    #expect(DeepLink(url: try #require(URL(string: "https://app.expadu.com/travel"))) == .travel)
    #expect(DeepLink(url: try #require(URL(string: "expadu://paperwork"))) == .paperwork)
}

@Test func otherHostsAndUnknownPathsAreRefused() throws {
    #expect(DeepLink(url: try #require(URL(string: "https://evil.example/travel"))) == nil)
    #expect(DeepLink(url: try #require(URL(string: "https://app.expadu.com/lab"))) == nil)
}

@Test func theTripsIslandButtonsOpenTheirPlaces() throws {
    #expect(DeepLink(url: try #require(URL(string: "expadu://trip/steps"))) == .tripSteps)
    #expect(DeepLink(url: try #require(URL(string: "expadu://trip/map"))) == .tripMap)
    #expect(DeepLink(url: try #require(URL(string: "expadu://trip/new-way"))) == .tripNewWay)
    #expect(DeepLink(url: try #require(URL(string: "expadu://trip/there"))) == .tripThere)
    // Only from the app's own scheme: a web link can't end a trip.
    #expect(DeepLink(url: try #require(URL(string: "https://app.expadu.com/trip/there"))) == nil)
}
