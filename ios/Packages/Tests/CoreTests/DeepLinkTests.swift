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
