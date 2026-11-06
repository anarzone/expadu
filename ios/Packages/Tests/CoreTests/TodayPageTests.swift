import Core
import Foundation
import Testing

struct TodayPageTests {
    private func at(_ text: String) -> Date {
        try! Date.ISO8601FormatStyle().parse(text)
    }

    @Test func theGreetingFollowsColognesClock() {
        // 08:30 in Cologne is 07:30 UTC in winter.
        #expect(TodayPage.greeting(at: at("2026-11-06T07:30:00Z"), name: "Ada Lovelace") == "Guten Morgen, Ada")
        #expect(TodayPage.greeting(at: at("2026-11-06T12:00:00Z"), name: "Ada") == "Hallo, Ada")
        #expect(TodayPage.greeting(at: at("2026-11-06T20:00:00Z"), name: "") == "Guten Abend")
    }

    @Test func theDateReadsAsTheCanvasHasIt() {
        let page = TodayPage(date: "2026-09-30", now: .now, weather: nil, plans: [], leave: nil, needs: [], ideas: [], ideasPart: "evening", tomorrow: [])
        #expect(page.dateLine == "Wednesday, 30 September")
        #expect(page.ideasTitle == "Ideas for this evening")
    }

    @Test func onlyAWorkedOutWayGetsTheLeaveCard() {
        let way = TodayPage.Leave(to: "Dinner", start: "23:30", status: "fits", leaveTime: "23:07", leaveInMinutes: 5, arriveTime: "23:24", spareMinutes: 6, line: "3", color: "#C8027E", departs: "23:12", from: "Neumarkt", live: true)
        let none = TodayPage.Leave(to: "Dinner", start: "23:30", status: "unavailable", leaveTime: nil, leaveInMinutes: nil, arriveTime: nil, spareMinutes: nil, line: nil, color: nil, departs: nil, from: nil, live: false)
        #expect(way.isKnown)
        #expect(!none.isKnown)
    }
}
