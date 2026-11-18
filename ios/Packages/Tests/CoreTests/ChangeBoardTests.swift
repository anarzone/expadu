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
        #expect(better?.gone == true)
    }

    @Test func aFasterTramIsOfferedOnlyWhenItSavesAMinute() {
        var late = board
        late.runs[1] = run("12", leaves: 29, there: 38, yours: true)
        let better = late.better(ready: base.addingTimeInterval(22 * 60))
        #expect(better?.run.departTime == "26")
        #expect(better?.gone == false)
        // A tram only seconds sooner is no reason to switch.
        let close = ChangeBoard(runs: [run("12", leaves: 24, there: 33, yours: true), run("15", leaves: 25, there: 33)], autoSwitch: true)
        #expect(close.better(ready: base.addingTimeInterval(22 * 60)) == nil)
    }

    @Test func aCancelledTramIsNeverPickedAndTheirsCancelledIsGone() {
        let board = ChangeBoard(runs: [run("12", leaves: 24, there: 33, yours: true, cancelled: true), run("15", leaves: 25, there: 35, cancelled: true),
                                       run("12", leaves: 29, there: 38)], autoSwitch: true)
        let better = board.better(ready: base.addingTimeInterval(22 * 60))
        #expect(better?.run.departTime == "29")
        #expect(better?.gone == true)
    }

    @Test func noSwitchWhenTheirTramIsntOnTheBoard() {
        let board = ChangeBoard(runs: [run("15", leaves: 26, there: 36)], autoSwitch: true)
        #expect(board.better(ready: base.addingTimeInterval(25 * 60)) == nil)
    }
}
