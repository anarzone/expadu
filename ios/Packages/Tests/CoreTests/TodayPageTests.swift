import Core
import Foundation
import Testing

struct TodayPageTests {
    private func at(_ text: String) -> Date {
        try! Date.ISO8601FormatStyle().parse(text)
    }

    private func page(plans: [TodayPage.Plan] = [], free: TodayPage.Free? = nil) -> TodayPage {
        TodayPage(date: "2026-09-30", now: .now, weather: nil, plans: plans, leave: nil, needs: [], ideas: [], ideasPart: "evening", free: free, tomorrow: [])
    }

    @Test func theGreetingFollowsColognesClock() {
        // 08:30 in Cologne is 07:30 UTC in winter.
        #expect(TodayPage.greeting(at: at("2026-11-06T07:30:00Z"), name: "Ada Lovelace") == "Guten Morgen, Ada")
        #expect(TodayPage.greeting(at: at("2026-11-06T12:00:00Z"), name: "Ada") == "Hallo, Ada")
        #expect(TodayPage.greeting(at: at("2026-11-06T20:00:00Z"), name: "") == "Guten Abend")
    }

    @Test func theDateReadsAsTheCanvasHasIt() {
        #expect(page().dateLine == "Wednesday, 30 September")
        #expect(page().ideasTitle == "Ideas for this evening")
    }

    @Test func onlyAWorkedOutWayGetsTheLeaveCard() {
        let way = TodayPage.Leave(to: "Dinner", start: "23:30", status: "fits", leaveTime: "23:07", leaveInMinutes: 5, arriveTime: "23:24", spareMinutes: 6, line: "3", color: "#C8027E", departs: "23:12", from: "Neumarkt", walkMinutes: 4, transfers: 0, live: true)
        let none = TodayPage.Leave(to: "Dinner", start: "23:30", status: "unavailable", leaveTime: nil, leaveInMinutes: nil, arriveTime: nil, spareMinutes: nil, line: nil, color: nil, departs: nil, from: nil, live: false)
        #expect(way.isKnown)
        #expect(way.note == "4 min walk to the platform · no change")
        #expect(!none.isKnown)
        #expect(none.note == nil)
    }

    @Test func theWayBetweenPlansReadsAsTheWebSaysIt() {
        let fits = TodayPage.Link(from: "a", to: "b", reason: "transit_fits", basis: "transit", travelMinutes: 25, gapMinutes: 40)
        let late = TodayPage.Link(from: "a", to: "b", reason: "no_connection_in_time", basis: nil, travelMinutes: 30, gapMinutes: 10)
        #expect(fits.words.line == "About 25 min · 15 min to spare")
        #expect(late.words.line == "Needs about 30 min, you have 10")
        #expect(late.words.tone == .conflict)
    }

    @Test func freeTimeRunsToTheNextPlan() {
        let free = TodayPage.Free(anchorTitle: "EU Blue Card appointment", anchorArea: "Wiener Platz", anchorEnd: "15:00", near: nil, at: "15:00", places: [])
        let later = TodayPage.Plan(id: "b", title: "Language class", subtitle: nil, start: "18:00", end: "20:00", place: "Ehrenfeld", state: "upcoming", completed: false)
        #expect(page(plans: [later], free: free).freeLine == "3 hours free · 15:00–18:00")
        #expect(page(free: free).freeLine == "Free from 15:00")
        #expect(free.heading == "Near Wiener Platz, after EU Blue Card appointment")
    }

    @Test func aPosterSaysNowOnceItHasBegun() {
        let now = at("2026-09-30T11:05:00Z")
        let on = TodayPage.Idea(id: 1, title: "Museum", category: "", emoji: nil, startsAt: at("2026-09-30T08:00:00Z"), endsAt: at("2026-09-30T16:00:00Z"), timed: true, untimed: nil, tomorrow: false, price: nil, place: "Museum Ludwig", area: nil)
        let tonight = TodayPage.Idea(id: 2, title: "Open mic", category: "", emoji: nil, startsAt: at("2026-09-30T18:30:00Z"), endsAt: nil, timed: true, untimed: nil, tomorrow: false, price: nil, place: "Venloer Str.", area: nil)
        let allDay = TodayPage.Idea(id: 3, title: "Film Festival", category: "", emoji: nil, startsAt: nil, endsAt: nil, timed: false, untimed: "Daily until Thu 5 Nov", tomorrow: false, price: nil, place: nil, area: "Stadtgebiet")
        #expect(on.posterLabel(at: now) == "Now")
        #expect(tonight.posterLabel(at: now) == "20:30")
        #expect(tonight.whenLine(at: now) == "Tonight 20:30 · Venloer Str.")
        #expect(allDay.posterLabel(at: now) == "All day")
        #expect(allDay.whenLine(at: now) == "Daily until Thu 5 Nov · Stadtgebiet")
    }

    @Test func theDeadlineSaysWhatExpiresAndWhen() {
        let visa = TodayPage.Deadline(kind: "document_expiry", document: "visa", title: "", date: "2026-10-01", daysRemaining: 1, next: nil, ready: 1, total: 5, process: "blue-card")
        #expect(visa.headline == "Your visa expires tomorrow")
    }
}
