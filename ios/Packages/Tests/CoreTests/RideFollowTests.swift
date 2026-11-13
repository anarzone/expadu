import Foundation
import Testing
@testable import Core

/// On a ride the phone says where they are, not the clock: the owner's 12 home on 13 Nov ran 3
/// minutes late, and the island said "Get off at Merkenich Mitte · Now" while it was still riding.
struct RideFollowTests {
    /// 2026-11-13 in Berlin, at a clock time.
    static func at(_ clock: String) -> Date {
        TodayPage.today(clock, on: Date(timeIntervalSince1970: 1_794_600_000))!
    }

    /// Scheibenstr. to Merkenich Mitte on the 12, north in a line, four stops on the way, then a walk home.
    static let trip: TodayPage.Trip = {
        let point = { (n: Double) in (51.0 + n * 0.004, 6.95) }
        let ride = (0...5).map { point(Double($0)) }
        let names = ["Scheibenstr.", "Fordwerke Süd", "Fordwerke Mitte", "Fordwerke Nord", "Merkenich Ford", "Merkenich Mitte"]
        let times = ["20:12", "20:14", "20:16", "20:19", "20:22", "20:25"]
        let stops = (1...4).map { TodayPage.Trip.Leg.Stop(name: names[$0], time: times[$0], latitude: ride[$0].0, longitude: ride[$0].1) }
        let walkTo = TodayPage.Trip.Leg(mode: "walk", line: nil, color: nil, headsign: nil, from: "Your location", to: "Scheibenstr.", departAt: at("20:08"), arriveAt: at("20:12"),
                                        departTime: "20:08", arriveTime: "20:12", minutes: 4, stops: nil, polyline: Polyline.encode([(50.997, 6.95), ride[0]]))
        let tram = TodayPage.Trip.Leg(mode: "tram", line: "12", color: "#8CB437", headsign: "Merkenich", from: "Scheibenstr.", to: "Merkenich Mitte", departAt: at("20:12"), arriveAt: at("20:25"),
                                      departTime: "20:12", arriveTime: "20:25", minutes: 13, stops: 5, polyline: Polyline.encode(ride), stopsOnTheWay: stops)
        let home = TodayPage.Trip.Leg(mode: "walk", line: nil, color: nil, headsign: nil, from: "Merkenich Mitte", to: "Home", departAt: at("20:25"), arriveAt: at("20:29"),
                                      departTime: "20:25", arriveTime: "20:29", minutes: 4, stops: nil, polyline: Polyline.encode([ride[5], (51.0215, 6.952)]))
        return TodayPage.Trip(to: "Home", arriveTime: "20:29", startTime: nil, legs: [walkTo, tram, home])
    }()

    /// Where the phone is on the tram's line: `n` stops on from Scheibenstr. (fractions between them).
    static func onTheTram(_ n: Double) -> TodayPage.Point { TodayPage.Point(latitude: 51.0 + n * 0.004, longitude: 6.95) }

    @Test func aLateTramIsNotTimeToGetOff() throws {
        let guide = Guide(trip: Self.trip)
        // 20:24, a minute before the timetable has them at Merkenich Mitte, the phone is only just past
        // Fordwerke Nord (due 20:19): the tram runs about 5 minutes late.
        let here = try #require(guide.position(of: Self.onTheTram(3.1), at: Self.at("20:24"), after: 1))
        let ride = try #require(here.ride)
        #expect(ride.onBoard)
        #expect(ride.late > 4 * 60 && ride.late < 6 * 60)
        #expect(Self.trip.late(here: here, at: Self.at("20:24")) == ride.late)

        let phase = TripPhase.of(Self.trip, here: here, offFor: 0, at: Self.at("20:24"))
        #expect(phase.kind == .ride)
        #expect(phase.title == "Get off at Merkenich Mitte")
        #expect(phase.detail.hasPrefix("5 min late · 2 stops"))
        // There as late as it runs.
        #expect(phase.arrive == "20:34")
        // The step is still the ride, not "get off".
        #expect(Self.trip.steps[Self.trip.live(at: Self.at("20:24"), guided: here.step, late: ride.late).current].kind == .ride)
    }

    @Test func theStopBeforeTheirsSaysGetOffNext() throws {
        let guide = Guide(trip: Self.trip)
        // Just past Merkenich Ford, on time: theirs is the next stop.
        let here = try #require(guide.position(of: Self.onTheTram(4.1), at: Self.at("20:22"), after: 1))
        let phase = TripPhase.of(Self.trip, here: here, offFor: 0, at: Self.at("20:22"))
        #expect(phase.kind == .getOff)
        #expect(phase.short == "Next")
        #expect(phase.title == "Get off at Merkenich Mitte")
    }

    @Test func underGroundTheRideGoesOnAtItsPaceFromTheLastFix() throws {
        let guide = Guide(trip: Self.trip)
        // Last fix at 20:16:30 just past Fordwerke Süd (due 20:14): about 2 minutes late. No fixes since.
        let here = try #require(guide.position(of: Self.onTheTram(1.05), at: Self.at("20:16").addingTimeInterval(30), after: 1))
        // At 20:21 the timetable (2 min back) has it past Fordwerke Mitte (20:16) but not Fordwerke Nord
        // (20:19): Fordwerke Nord, Merkenich Ford and theirs to come.
        let phase = TripPhase.of(Self.trip, here: here, offFor: 0, at: Self.at("20:21"))
        #expect(phase.kind == .ride)
        #expect(phase.detail.hasPrefix("2 min late · 3 stops"))
        // Due 20:25, about 2.4 minutes late: 20:27:24, from 20:21.
        #expect(phase.short == "7′")
    }

    @Test func waitingAtTheStopForALateTramIsWaitingNotGone() throws {
        var trip = Self.trip
        var legs = trip.legs
        legs[1].delayMin = 3
        trip = TodayPage.Trip(to: trip.to, arriveTime: trip.arriveTime, startTime: trip.startTime, legs: legs)
        let guide = Guide(trip: trip)
        // 20:13 at Scheibenstr.: the 12 is due at 20:15 by the board.
        let here = guide.position(of: Self.onTheTram(0), at: Self.at("20:13"), after: 0)
        let phase = TripPhase.of(trip, here: here, offFor: 0, at: Self.at("20:13"))
        #expect(phase.kind == .wait)
        #expect(phase.short == "2′")
    }
}
