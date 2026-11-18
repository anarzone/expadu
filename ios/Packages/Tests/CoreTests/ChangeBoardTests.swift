import Foundation
import Testing
@testable import Core

/// When your tram is late: which tram on a change's board is better than theirs, and why.
struct ChangeBoardTests {
    private let base = Date(timeIntervalSince1970: 1_800_000_000)

    private func run(_ line: String, leaves: Int, there: Int, yours: Bool = false, cancelled: Bool = false) -> ChangeBoard.Run {
        ChangeBoard.Run(line: line, color: nil, headsign: nil, departAt: base.addingTimeInterval(Double(leaves) * 60), departAtText: "\(leaves)",
                        departTime: "\(leaves)", arriveAt: base.addingTimeInterval(Double(there) * 60), arriveTime: "\(there)",
                        delayMin: nil, cancelled: cancelled, yours: yours, trip: "\(line)-\(leaves)")
    }

    private var board: ChangeBoard {
        ChangeBoard(runs: [run("15", leaves: 21, there: 31), run("12", leaves: 24, there: 33, yours: true),
                           run("15", leaves: 26, there: 36), run("12", leaves: 29, there: 38)], autoSwitch: true)
    }

    @Test func onTimeKeepsTheirTram() {
        #expect(board.better(ready: base.addingTimeInterval(22 * 60)) == nil)
    }

    @Test func aLateFirstTramMovesThemToTheNextTheyCanCatch() {
        let better = board.better(ready: base.addingTimeInterval(25 * 60))
        #expect(better?.run.line == "15")
        #expect(better?.run.departTime == "26")
        #expect(better?.reason == .missed)
    }

    @Test func aFasterTramIsOfferedOnlyWhenItSavesAMinute() {
        var late = board
        late.runs[1] = run("12", leaves: 29, there: 38, yours: true)
        let better = late.better(ready: base.addingTimeInterval(22 * 60))
        #expect(better?.run.departTime == "26")
        #expect(better?.reason == .faster)
        // A tram only seconds sooner is no reason to switch.
        let close = ChangeBoard(runs: [run("12", leaves: 24, there: 33, yours: true), run("15", leaves: 25, there: 33)], autoSwitch: true)
        #expect(close.better(ready: base.addingTimeInterval(22 * 60)) == nil)
    }

    @Test func aCancelledTramIsNeverPickedAndTheirsCancelledIsGone() {
        let board = ChangeBoard(runs: [run("12", leaves: 24, there: 33, yours: true, cancelled: true), run("15", leaves: 25, there: 35, cancelled: true),
                                       run("12", leaves: 29, there: 38)], autoSwitch: true)
        let better = board.better(ready: base.addingTimeInterval(22 * 60))
        #expect(better?.run.departTime == "29")
        #expect(better?.reason == .cancelled)
    }

    @Test func noSwitchWhenTheirTramIsntOnTheBoard() {
        let board = ChangeBoard(runs: [run("15", leaves: 26, there: 36)], autoSwitch: true)
        #expect(board.better(ready: base.addingTimeInterval(25 * 60)) == nil)
    }

    // The owner's evening, 18 Nov: from Appellhofplatz to Ebertplatz for the Merkenich 12 (latest in at
    // minute 62). Their 16 at 47; the late 16 at 41, the 18 at 50 and the 16 at 57 make it too.
    private var home: ChangeBoard {
        ChangeBoard(runs: [run("16", leaves: 41, there: 46), run("16", leaves: 47, there: 52, yours: true),
                           run("18", leaves: 50, there: 55), run("16", leaves: 57, there: 62), run("18", leaves: 65, there: 70)], autoSwitch: true)
    }
    private var twelve: ChangeBoard.Connection {
        ChangeBoard.Connection(line: "12", departTime: "20:04", stop: "Ebertplatz", latest: base.addingTimeInterval(62 * 60), thereTime: "20:28")
    }

    @Test func withAChangeAfterItAnEarlierTramIsNoReasonToMoveThem() {
        #expect(home.better(ready: base.addingTimeInterval(37 * 60), connection: twelve) == nil)
    }

    @Test func whenTheirsWontMakeTheChangeTheyMoveToTheEarliestTheyCanCatchThatDoes() {
        var late = home
        late.runs[1] = run("16", leaves: 59, there: 64, yours: true)
        let better = late.better(ready: base.addingTimeInterval(37 * 60), connection: twelve)
        #expect(better?.run.departTime == "41")
        #expect(better?.reason == .late)
    }

    @Test func leavingLaterTheyMoveToTheNextThatStillMakesTheChange() {
        let better = home.better(ready: base.addingTimeInterval(52 * 60), connection: twelve)
        #expect(better?.run.departTime == "57")
        #expect(better?.reason == .missed)
    }

    @Test func whenNoTramMakesTheChangeTheOneInSoonestIsTaken() {
        let better = home.better(ready: base.addingTimeInterval(58 * 60), connection: twelve)
        #expect(better?.run.departTime == "65")
        #expect(home.makes(better!.run, twelve) == false)
    }
}
