import Core
import Testing

struct BoardTests {
    private func departure(_ line: String, _ next: Int) -> Board.Departure {
        Board.Departure(line: line, direction: "Somewhere", stop: "Neumarkt", color: "#000000", minutes: [next], delay: 0, cancelled: false, disrupted: false)
    }

    @Test func linesAreListedOnceTramsFirstInNumberOrder() {
        let board = Board(stops: [], departures: [departure("16", 1), departure("136", 2), departure("4", 3), departure("S11", 4), departure("4", 5)])
        #expect(board.lines == ["4", "16", "136", "S11"])
    }

    @Test func aLineFiltersTheSameList() {
        let board = Board(stops: [], departures: [departure("4", 1), departure("3", 2), departure("4", 9)])
        #expect(board.showing("4").map(\.minutes) == [[1], [9]])
        #expect(board.showing(nil).count == 3)
    }

    @Test func aBadgeDropsTheFeedsBrackets() {
        #expect(departure("RE1 (RRX)", 0).badge == "RE1")
        #expect(departure("4", 0).badge == "4")
    }

    @Test func theFlapsShowTwoDigits() {
        #expect(departure("4", 3).flaps == "03")
        #expect(departure("4", 0).flaps == "00")
        #expect(departure("4", 140).flaps == "99")
    }

    @Test func theBoardIsNamedAfterTheClosestStop() {
        #expect(Board(stops: [.init(name: "Neumarkt", walkMinutes: 1), .init(name: "Rudolfplatz", walkMinutes: 6)], departures: []).name == "Neumarkt")
        #expect(Board(stops: [], departures: []).name == "Nearby")
    }
}
