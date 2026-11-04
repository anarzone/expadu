import Foundation
import Testing
@testable import API

@Test func theServersDatesAreRead() {
    #expect(AppAPI.date(from: "2026-11-04T21:00:00+00:00") != nil)
    #expect(AppAPI.date(from: "not a date") == nil)
}
