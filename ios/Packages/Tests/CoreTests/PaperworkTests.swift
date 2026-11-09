import Foundation
import Testing
@testable import Core

/// A real plan entry from the server (the first-Blue-Card case on a local record), read as the app reads it.
struct PaperworkTests {
    private func plan() throws -> PaperworkPlan {
        let url = try #require(Bundle.module.url(forResource: "paperwork-plan", withExtension: "json", subdirectory: "Fixtures"))
        let entry = try PaperworkEntry.decode(Data(contentsOf: url))
        #expect(entry.state == "ready")
        return try #require(entry.plan)
    }

    @Test func theServersPlanReadsWhole() throws {
        let plan = try plan()
        #expect(plan.processes.map(\.title) == ["EU Blue Card", "Income tax return"])
        #expect(plan.stepsTotal == 4)
        #expect(plan.papersRequired.count == 7)
        #expect(plan.papersReady == 0)
        #expect(plan.questions?.question?.factKey == "registration_status")
        #expect(plan.questions?.question?.choices.map(\.label) == ["Yes, registered", "Not yet"])
    }

    @Test func aTasksPapersAreItsOwn() throws {
        let plan = try plan()
        let blue = try #require(plan.processes.first)
        let papers = plan.papers(for: blue)
        #expect(papers.count == 9)
        #expect(papers.first?.required == true)
        #expect(blue.stateWords == "Not started")
        #expect(blue.tone == "todo")
    }

    @Test func datesComeSoonestFirst() throws {
        let plan = try plan()
        let dates = plan.dates
        #expect(dates.first?.kind == "document_expiry" || dates.first?.kind == "preparation_target")
        #expect(PaperworkWords.paragraphs("One\nline.\n\n- A\n- B") == ["One line.", "• A", "• B"])
    }
}
