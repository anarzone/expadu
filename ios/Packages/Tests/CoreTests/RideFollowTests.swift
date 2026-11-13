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

    // MARK: Without knowing where they are, nothing is said from it

    @Test func anOldFixFromTheStreetNeverSaysWalkToTheStation() throws {
        let guide = Guide(trip: Self.trip)
        // 20:09 on the street, 200 m short of Scheibenstr.; then nothing (down in the station).
        let street = try #require(guide.position(of: TodayPage.Point(latitude: 50.9982, longitude: 6.95), at: Self.at("20:09"), after: 0))
        #expect(street.step == 0)
        // At 20:14, five minutes on, the 12 has come: the clock says ride, not "walk to Scheibenstr.".
        let live = Self.trip.live(at: Self.at("20:14"), here: street)
        #expect(Self.trip.steps[live.current].kind == .ride)
        let phase = TripPhase.of(Self.trip, here: street, offFor: 0, at: Self.at("20:14"))
        #expect(phase.kind == .ride)
    }

    @Test func aRoughFixAtTheStopIsAtTheStop() throws {
        let guide = Guide(trip: Self.trip)
        // 20:10, a fix 55 m short of the stop but only good to 60 m (a station's walls): at the stop,
        // waiting, not walking.
        let here = try #require(guide.position(of: TodayPage.Point(latitude: 50.9995, longitude: 6.95), at: Self.at("20:10"), after: 0, accuracy: 60))
        #expect(here.atEnd)
        let phase = TripPhase.of(Self.trip, here: here, offFor: 0, at: Self.at("20:10"))
        #expect(phase.kind == .wait)
        // Nor any metres to a turn from a fix that rough.
        #expect(here.turn == nil)
    }

    @Test func aFixTooRoughToGoByIsNotGoneBy() throws {
        let guide = Guide(trip: Self.trip)
        let here = try #require(guide.position(of: TodayPage.Point(latitude: 50.9982, longitude: 6.95), at: Self.at("20:13"), after: 0, accuracy: 90))
        #expect(!here.trusted(at: Self.at("20:13")))
        // Off the way only beyond what the fix may be off by.
        let far = try #require(guide.position(of: TodayPage.Point(latitude: 50.9982, longitude: 6.9513), at: Self.at("20:09"), after: 0, accuracy: 50))
        #expect(!far.offRoute)
    }

    @Test func aChangeWithinOneStopSaysWhichTramNotAWalk() {
        let at = Self.at
        let twelve = TodayPage.Trip.Leg(mode: "tram", line: "12", color: "#8CB437", headsign: "Zollstock", from: "Merkenich Mitte", to: "Ebertplatz", departAt: at("21:51"), arriveAt: at("22:12"),
                                        departTime: "21:51", arriveTime: "22:12", minutes: 21, stops: 12, polyline: Polyline.encode([(51.02, 6.95), (50.95, 6.96)]))
        let change = TodayPage.Trip.Leg(mode: "walk", line: nil, color: nil, headsign: nil, from: "Ebertplatz", to: "Ebertplatz", departAt: at("22:12"), arriveAt: at("22:18"),
                                        departTime: "22:12", arriveTime: "22:18", minutes: 6, stops: nil, polyline: Polyline.encode([(50.95, 6.96), (50.95, 6.96)]))
        let eighteen = TodayPage.Trip.Leg(mode: "tram", line: "18", color: "#1D92D1", headsign: "Klettenberg", from: "Ebertplatz", to: "Appellhofplatz", departAt: at("22:19"), arriveAt: at("22:25"),
                                          departTime: "22:19", arriveTime: "22:25", minutes: 6, stops: 3, polyline: Polyline.encode([(50.95, 6.96), (50.94, 6.95)]))
        let trip = TodayPage.Trip(to: "Cafe Nova", arriveTime: "22:25", startTime: nil, legs: [twelve, change, eighteen])
        let step = trip.steps[1]
        #expect(step.title == "Change at Ebertplatz")
        #expect(step.sub == "In the station · Tram 18 at 22:19")
        // No path to follow there: the guide has no line for it.
        #expect(!Guide(trip: trip).lines.contains { $0.leg == 1 })
    }

    @Test func anEarlierTramIsMeasuredAsEarly() throws {
        let guide = Guide(trip: Self.trip)
        // The plan's 12 leaves Scheibenstr. at 20:12; at 20:07 the phone is already past Fordwerke Süd
        // (due 20:14): an earlier 12, seven minutes ahead, for trip sync to name.
        let here = try #require(guide.position(of: Self.onTheTram(1.0), at: Self.at("20:07"), after: 1))
        let ride = try #require(here.ride)
        #expect(ride.onBoard)
        #expect(ride.late == -7 * 60)
        // When it left their stop: the plan's time that far ahead.
        #expect(Self.trip.legs[1].departAt.addingTimeInterval(ride.late) == Self.at("20:05"))
    }
}
