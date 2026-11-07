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

    @Test func theLiveCardSaysWhereTheyAreByTheClock() {
        let t = { (minutes: Double) in Date(timeIntervalSinceReferenceDate: 800_000_000 + minutes * 60) }
        let walk = TodayPage.Trip.Leg(mode: "walk", line: nil, color: nil, headsign: nil, from: "Your location", to: "Rudolfplatz", departAt: t(0), arriveAt: t(6),
                                      departTime: "23:07", arriveTime: "23:13", minutes: 6, stops: nil)
        let tram = TodayPage.Trip.Leg(mode: "tram", line: "1", color: "#E2001A", headsign: "Weiden West", from: "Rudolfplatz", to: "Stadtgarten", departAt: t(6), arriveAt: t(17),
                                      departTime: "23:13", arriveTime: "23:24", minutes: 11, stops: 4)
        let after = TodayPage.Trip.Leg(mode: "walk", line: nil, color: nil, headsign: nil, from: "Stadtgarten", to: "Dinner with Sara", departAt: t(17), arriveAt: t(20),
                                       departTime: "23:24", arriveTime: "23:27", minutes: 3, stops: nil)
        let trip = TodayPage.Trip(to: "Dinner with Sara", arriveTime: "23:27", startTime: "23:30", legs: [walk, tram, after])

        #expect(trip.steps.map(\.title) == ["Walk to Rudolfplatz", "Tram 1 to Stadtgarten", "Get off at Stadtgarten", "You’re there"])
        #expect(trip.steps[2].sub == "3 min walk to Dinner with Sara")
        let walking = trip.live(at: t(2))
        #expect(walking.label == "Now")
        #expect(walking.big == "4 min")
        #expect(walking.bigNote == "Tram 1 at 23:13")
        let riding = trip.live(at: t(8))
        #expect(riding.label == "Step 2 of 4")
        #expect(riding.big == "9 min")
        #expect(riding.bigNote == "off at 23:24")
        let there = trip.live(at: t(21))
        #expect(there.label == "Arrived")
        #expect(there.bigNote == "3 min early")
    }

    @Test func routeLinesDecodeAtMotisPrecision() {
        let point = Polyline.decode("wcmpj]kwhmcC").first
        #expect(abs((point?.latitude ?? 0) - 50.936942) < 0.000001)
        #expect(abs((point?.longitude ?? 0) - 6.944039) < 0.000001)
    }

    @Test func otherWaysSayTheirSpareAndTheirWalk() {
        let way = TodayPage.Way(id: 1, leaveTime: "23:17", arriveTime: "23:34", spareMinutes: 2, status: "tight", mode: "transit", lines: [.init(line: "1", color: "#E2001A")],
                                departs: "23:23", from: "Rudolfplatz", walkMinutes: 9, transfers: 0, minutes: 17, inPlan: false, live: true)
        #expect(way.spareWords == "Only 2 min spare")
        #expect(way.note == "9 min walking · no change")
    }
}

struct GuideTests {
    private let start = Date(timeIntervalSinceReferenceDate: 800_000_000)

    /// A walk along a straight street, 0.002° east (about 140 m), then a tram on to the north.
    private var trip: TodayPage.Trip {
        let walkLine = Polyline.encode([(50.9370, 6.9440), (50.9370, 6.9450), (50.9370, 6.9460)])
        let rideLine = Polyline.encode([(50.9370, 6.9460), (50.9400, 6.9460), (50.9430, 6.9460)])
        let turns = [
            TodayPage.Turn(direction: "depart", street: "Breite Straße", metres: 70, polyline: Polyline.encode([(50.9370, 6.9440), (50.9370, 6.9450)]), levelChange: false),
            TodayPage.Turn(direction: "left", street: "Mörsergasse", metres: 70, polyline: Polyline.encode([(50.9370, 6.9450), (50.9370, 6.9460)]), levelChange: false),
        ]
        let walk = TodayPage.Trip.Leg(mode: "walk", line: nil, color: nil, headsign: nil, from: "", to: "Neumarkt", departAt: start, arriveAt: start.addingTimeInterval(120),
                                      departTime: "15:00", arriveTime: "15:02", minutes: 2, stops: nil, polyline: walkLine, turns: turns)
        let tram = TodayPage.Trip.Leg(mode: "tram", line: "18", color: "#1D92D1", headsign: "Buchheim", from: "Neumarkt", to: "Dom/Hbf", departAt: start.addingTimeInterval(180),
                                      arriveAt: start.addingTimeInterval(480), departTime: "15:03", arriveTime: "15:08", minutes: 5, stops: 2, polyline: rideLine)
        return TodayPage.Trip(to: "Kreuzblume", arriveTime: "15:08", startTime: "15:30", legs: [walk, tram])
    }

    @Test func onTheWalkItGivesTheNextTurnAndHowFar() {
        let here = Guide(trip: trip).position(of: .init(latitude: 50.9370, longitude: 6.9445), at: start)
        #expect(here?.leg == 0)
        #expect(here?.offRoute == false)
        #expect(here?.turn?.words == "Turn left onto Mörsergasse")
        #expect((here?.metresToTurn ?? 0) > 25 && (here?.metresToTurn ?? 0) < 45)
    }

    @Test func onTheTramItIsTheRide() {
        let here = Guide(trip: trip).position(of: .init(latitude: 50.9400, longitude: 6.9460), at: start.addingTimeInterval(300), after: 0)
        #expect(here?.leg == 1)
        #expect(here?.turn == nil)
    }

    @Test func farFromTheLineIsOffTheWayAndAMissedTramIsSaid() {
        let guide = Guide(trip: trip)
        let off = guide.position(of: .init(latitude: 50.9355, longitude: 6.9452), at: start)
        #expect(off?.offRoute == true)
        let late = guide.position(of: .init(latitude: 50.9370, longitude: 6.9442), at: start.addingTimeInterval(300))
        #expect(late?.missed?.line == "18")
    }

    @Test func onARideTheTimetableWinsOverAPhoneThatStoodStill() {
        // The phone hasn't moved from the tram line, but the trip's time is long past.
        let late = trip.live(at: start.addingTimeInterval(3600), guided: 1)
        #expect(late.current == trip.steps.count - 1)
        // On foot, the phone wins: still walking after the clock says the tram has gone.
        let walking = trip.live(at: start.addingTimeInterval(240), guided: 0)
        #expect(walking.current == 0)
    }
}
