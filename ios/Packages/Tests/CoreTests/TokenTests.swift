import Foundation
import Testing
@testable import Core

@Test func aTokenAsksForRefreshInItsLastThirtyDays() {
    let now = Date(timeIntervalSince1970: 1_000_000)
    let day: TimeInterval = 24 * 60 * 60
    #expect(AppToken(value: "t", expires: now + 59 * day).wantsRefresh(now: now) == false)
    #expect(AppToken(value: "t", expires: now + 29 * day).wantsRefresh(now: now) == true)
}

@Test func theInitialComesFromTheName() {
    #expect(Account(name: " anar", email: "a@b.c", emailVerified: true, hasPassword: true).initial == "A")
}
