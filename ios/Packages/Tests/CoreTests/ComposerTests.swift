import Core
import Foundation
import Testing

/// Composer's workspace reads whole, as the server sends it; a card the app can't read is left out,
/// never the answer.
struct ComposerTests {
    @Test func theServersWorkspaceReadsWhole() throws {
        let url = try #require(Bundle.module.url(forResource: "composer-workspace", withExtension: "json", subdirectory: "Fixtures"))
        let workspace = try ComposerWorkspace.decode(Data(contentsOf: url))

        #expect(workspace.revision == 20)
        let current = try #require(workspace.current)
        #expect(current.messages.filter(\.mine).map(\.text) == ["Somewhere quiet to work near Neumarkt", "What's on tonight?", "When is the next tram from Neumarkt?"])
        #expect(current.messages.last?.withoutAi == "off")
    }

    @Test func eachCardReadsAndAnUnknownOneIsLeftOut() throws {
        let json = """
        {"revision":3,"schema":2,"active":"c1","focus":null,"plans":[],"changes":[],
         "conversations":[{"id":"c1","title":"Quiet","created_at":"2026-11-09T20:00:00+01:00","asked":1,"last_at":"2026-11-09T20:01:00+01:00","messages":[
          {"id":"m1","role":"user","text":"Somewhere quiet","created_at":"2026-11-09T20:00:00+01:00"},
          {"id":"m2","role":"assistant","text":"Two cafés","created_at":"2026-11-09T20:01:00+01:00","request":"ask","kind":"around","lead":"Two quiet cafés near you.","written":"ai",
           "blocks":[
            {"type":"where","area":"Altstadt-Süd","basis":"chosen","stop":{"name":"Neumarkt","walk_min":3,"lines":["1","3"]}},
            {"type":"places","places":[{"id":7,"name":"Café Mux","kind":"cafe","distance":"300 m","area":"Altstadt-Süd","hours":"Open until 22:00","href":"/around-here?place=7"}],"from":null},
            {"type":"events","events":[{"id":9,"candidate":"event:9:1","date":"2026-11-09","plannable":true,"occurrence_start":"2026-11-09T20:00:00+01:00","occurrence_end":null,"title":"Jazz","category":"music","price_text":"Free","venue":{"name":"Stadtgarten","veedel":"Neustadt-Nord"}}]},
            {"type":"done","id":"d1","area":"everyday","text":"Added to Saturday.","undo":{"token":"u1","until":"2026-11-10T20:00:00+01:00"},"day":[{"time":"14:00","what":"Café Mux","new":true}]},
            {"type":"something_new","text":"from a later server"},
            {"type":"facts","facts":[{"label":"Visa expires","value":"1 Dec 2026","known":true}]}
           ],
           "suggestions":[{"label":"Open late?","prompt":"Which are open late?"}]}
         ]}]}
        """
        let workspace = try ComposerWorkspace.decode(Data(json.utf8))
        let answer = try #require(workspace.current?.messages.last)

        #expect(answer.lead == "Two quiet cafés near you.")
        #expect(answer.blocks.count == 5)
        guard case let .place(place) = answer.blocks[0] else { Issue.record("where first"); return }
        #expect(place.stop?.walkMin == 3)
        guard case let .places(places) = answer.blocks[1] else { Issue.record("places second"); return }
        #expect(places.first?.hours == "Open until 22:00")
        guard case let .events(events) = answer.blocks[2] else { Issue.record("events third"); return }
        #expect(events.first?.venue.name == "Stadtgarten")
        guard case let .done(done) = answer.blocks[3] else { Issue.record("done fourth"); return }
        #expect(done.undo?.token == "u1")
        #expect(answer.suggestions.first?.prompt == "Which are open late?")
    }
}
