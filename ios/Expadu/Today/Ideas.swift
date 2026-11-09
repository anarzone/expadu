import API
import Core
import CoreLocation
import Maps
import DesignSystem
import SafariServices
import SwiftUI

// MARK: - Ideas

/// Events for this part of the day, each a small tilted poster with its time.
struct Ideas: View {
    let page: TodayPage
    @Binding var sheet: TodaySheet?
    @Environment(TodayModel.self) private var model

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            SectionHead(title: page.ideasTitle) {
                Button { sheet = .whatsOn() } label: {
                    Label("All events", systemImage: "arrow.right").labelStyle(TrailingIcon())
                        .font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                        .frame(minHeight: 44)
                }
                .buttonStyle(PressStyle())
            }
            if let sub {
                Text(sub).font(.geist(13.5)).foregroundStyle(Palette.textSoft).padding(.horizontal, 4).padding(.top, -6)
            }
            if let pause = page.rankPaused, !model.pauseSetAside {
                PausedLine(pause: pause, sheet: $sheet).padding(.top, 10)
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
            VStack(spacing: 0) {
                ForEach(Array(model.shownIdeas.prefix(4).enumerated()), id: \.element.id) { index, idea in
                    if index > 0 { Divider().overlay(Palette.border) }
                    IdeaRow(idea: idea, now: page.now, tilt: [-3, 2, -2, 3][index % 4], date: page.date, tomorrow: page.tomorrowDate,
                            onOpen: { sheet = .whatsOn(open: idea.candidate, date: idea.tomorrow ? page.tomorrowDate : nil) },
                            onWhy: { sheet = .why(idea, idea.whenLine(at: page.now)) })
                        .transition(.asymmetric(insertion: .opacity, removal: .opacity.combined(with: .move(edge: .trailing))))
                }
            }
            .card(radius: 22)
            .padding(.top, 12)
            // UWG § 5b: every ranked list says how it's put in order.
            Button { sheet = .howWeRank(page.personalised) } label: {
                Label("How we rank", systemImage: "list.number").labelStyle(Tight())
                    .font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
                    .frame(minHeight: 36)
            }
            .buttonStyle(PressStyle())
            .padding(.horizontal, 4).padding(.top, 4)
        }
    }

    /// As the canvas words it: how the ideas sit with their plan, then the weather ("After Language
    /// class · dry, 5°"). The heading already says when, so this doesn't.
    private var sub: String? {
        let weather = page.weather.map { "\(($0.summary ?? $0.condition).lowercased()), \($0.temperature)°" }
        let words = [page.ideasLead, weather].compactMap { $0 }
        return words.isEmpty ? nil : words.joined(separator: " · ")
    }
}

struct IdeaRow: View {
    let idea: TodayPage.Idea
    let now: Date
    let tilt: Double
    var date: String = ""
    var tomorrow: String?
    var onOpen: (() -> Void)?
    var onWhy: (() -> Void)?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        HStack(spacing: 12) {
            // The poster and the title open its details (e.open), as on the canvas.
            Button { onOpen?() } label: {
                Poster(label: idea.posterLabel(at: now), night: night, tilt: tilt)
            }
            .buttonStyle(PressStyle())
            .disabled(onOpen == nil)
            .accessibilityLabel("Open \(idea.title)")
            VStack(alignment: .leading, spacing: 2) {
                Button { onOpen?() } label: {
                    Text(idea.title).font(.geist(15, .semibold)).foregroundStyle(Palette.text).lineLimit(2).multilineTextAlignment(.leading)
                }
                .buttonStyle(.plain)
                .disabled(onOpen == nil)
                Text(idea.whenLine(at: now)).font(.geist(12.5)).foregroundStyle(Palette.textSoft).lineLimit(2)
                if let why = idea.why, let onWhy {
                    Button(action: onWhy) {
                        Label(why, systemImage: "info.circle").labelStyle(Tight())
                            .font(.geist(12.5, .medium)).foregroundStyle(TodayLink.ink)
                            .multilineTextAlignment(.leading).lineLimit(2)
                    }
                    .buttonStyle(PressStyle())
                    .padding(.top, 2)
                    .accessibilityLabel("Why am I seeing this? \(why)")
                }
            }
            Spacer(minLength: 0)
            HStack(spacing: 4) {
                Button { Task { await model.toggleSave(session.api, idea: idea) } } label: {
                    // Saved: the bookmark fills, in a quiet circle (.id-btn.on).
                    FillingBookmark(filled: idea.saved, size: 16, weight: .semibold)
                        .foregroundStyle(idea.saved ? Palette.text : Palette.textSoft)
                }
                .buttonStyle(RaisedStyle(fill: idea.saved ? Palette.quiet : Palette.surface, size: 38))
                .animation(.easeInOut(duration: 0.45), value: idea.saved)
                .accessibilityLabel(idea.saved ? "Saved: \(idea.title)" : "Save \(idea.title)")
                .sensoryFeedback(.selection, trigger: idea.saved)
                AddButton(candidate: idea.candidate, date: idea.tomorrow ? (tomorrow ?? date) : date, title: idea.title)
            }
        }
        .padding(.horizontal, 14).padding(.vertical, 10)
    }

    /// Evening listings are the night poster; daytime, all-day and on-now ones the day poster.
    private var night: Bool {
        guard idea.timed, let start = idea.startsAt, start > now else { return false }
        return TodayPage.clock(start) >= "17:00"
    }
}

/// The canvas's poster thumb: a coloured card, a little tilted, its time in the corner.
struct Poster: View {
    let label: String
    let night: Bool
    let tilt: Double

    var body: some View {
        let ink = Color(hex: night ? 0xFFE363 : 0x3D2F9E)
        Text(label)
            .font(.posterGrotesque(label.count > 5 ? 13 : 15))
            .foregroundStyle(ink)
            .lineLimit(2).minimumScaleFactor(0.8)
            .padding(6)
            .frame(width: 54, height: 68, alignment: .bottomLeading)
            .background(Color(hex: night ? 0x3D2F9E : 0xFFE363), in: .rect(cornerRadius: 7))
            .shadow(color: Color(hex: 0x211D15, opacity: 0.18), radius: 3, y: 2)
            .rotationEffect(.degrees(tilt))
            .accessibilityHidden(true)
    }
}

/// Their order waits for a yes to a changed notice (the web's AiOrderPausedLine; no AI words outside Settings).
struct PausedLine: View {
    let pause: TodayPage.RankPause
    @Binding var sheet: TodaySheet?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: "clock").font(.system(size: 13, weight: .semibold))
            Text("In the usual order until you agree to a change.").font(.geist(13)).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 4)
            Button("Review") { sheet = .agree(pause) }.font(.geist(13, .semibold)).foregroundStyle(TodayLink.ink)
            Button("Not now") { Task { await model.orderLater(session.api) } }.font(.geist(13, .semibold)).foregroundStyle(Palette.textSoft)
        }
        .foregroundStyle(Palette.textSoft)
        .padding(.horizontal, 12).padding(.vertical, 8)
        .background(Palette.quiet, in: .rect(cornerRadius: 14))
    }
}

/// Why am I seeing this? (the web's WhySheet): which of their leanings it matches, and what they can do,
/// each with Undo: fewer like this, or not for me (off Today).
struct WhySheet: View {
    let idea: TodayPage.Idea
    let when: String
    @Binding var sheet: TodaySheet?
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Section {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(idea.title).font(.geist(16, .semibold))
                        Text(when).font(.geist(13)).foregroundStyle(Palette.textSoft)
                    }
                }
                if !idea.reasons.isEmpty {
                    Section {
                        ForEach(idea.reasons, id: \.self) { reason in
                            Label(reason, systemImage: "checkmark").font(.geist(15))
                        }
                    }
                }
                Section {
                    Button("Less like this", systemImage: "minus.circle") {
                        model.lessLikeThis(session.api, idea: idea)
                        dismiss()
                    }
                    Button("Not for me", systemImage: "xmark.circle") {
                        withAnimation(.snappy) { model.notForMe(session.api, idea: idea) }
                        dismiss()
                    }
                    Button("How we rank", systemImage: "list.number") {
                        let on = model.page?.personalised ?? false
                        dismiss()
                        Task { try? await Task.sleep(for: .milliseconds(450)); sheet = .howWeRank(on) }
                    }
                }
                .font(.geist(15, .medium))
                .foregroundStyle(Palette.text)
            }
            .navigationTitle("Why am I seeing this?")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done", systemImage: "checkmark") { dismiss() } } }
        }
        .flexibleSheet()
    }
}

/// How we rank (UWG § 5b, the web's HowWeRank): what puts Today's ideas in order, that nobody pays,
/// and where listings come from.
struct HowWeRankSheet: View {
    let personalised: Bool
    @Environment(\.dismiss) private var dismiss
    private static let today: [(String, String)] = [
        ("Fits your plan", "Nothing that clashes with a plan whose times are known."),
        ("Near you first", "Within 3 km of your neighbourhood or Home, then the rest, each soonest first. Tomorrow’s only once nothing is left today."),
        ("What you lean towards, if you turned it on", "Put in order from what you open, save and turn down. It never hides one. Off: everyone sees the same order."),
        ("What you turned down", "An event you said isn’t for you stays off."),
    ]

    var body: some View {
        NavigationStack {
            List {
                Section("Today’s ideas") {
                    ForEach(Array(Self.today.enumerated()), id: \.offset) { index, rule in
                        HStack(alignment: .firstTextBaseline, spacing: 10) {
                            Text("\(index + 1)").font(.posterGrotesque(15)).foregroundStyle(Palette.textSoft)
                            VStack(alignment: .leading, spacing: 2) {
                                Text(rule.0).font(.geist(15, .semibold))
                                Text(rule.1).font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                            }
                        }
                    }
                }
                Section("What’s on") { Text("Soonest first, unless you pick evening first or closest first.").font(.geist(14)) }
                Section("Around here") { Text("Nearest first.").font(.geist(14)) }
                Section {
                    Label("Nobody pays to be listed or placed higher.", systemImage: "xmark.circle").font(.geist(14))
                    Text("Events come from koeln.de, the City of Cologne’s open data, KölnTourismus (DatenHub NRW) and a few we add ourselves; exhibitions and all-day attractions are left out. Places come from OpenStreetMap and Overture Maps.")
                        .font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                    LabeledContent("Personalised suggestions", value: personalised ? "On" : "Off").font(.geist(14))
                }
            }
            .navigationTitle("How we rank")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Done", systemImage: "checkmark") { dismiss() } } }
        }
        .flexibleSheet()
    }
}

/// Agree to the change? (the web's AiOrderChange): what changed, what waits until they agree, what's kept.
struct AgreeSheet: View {
    let pause: TodayPage.RankPause
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var busy = false

    var body: some View {
        NavigationStack {
            List {
                Section { Text("We changed how Claude Haiku, the AI that puts Today in order, works with Expadu.").font(.geist(14)).foregroundStyle(Palette.textSoft) }
                Section(pause.changed.isEmpty ? "How it works now" : "What changed") {
                    ForEach(pause.changed.isEmpty ? pause.notice : pause.changed, id: \.self) { Label($0, systemImage: "pencil").font(.geist(14)) }
                }
                Section("Paused until you agree") { Label("Today put in order by AI, and its “why” lines", systemImage: "clock").font(.geist(14)) }
                Section("Kept either way") { Label("What Expadu learned, and your settings", systemImage: "checkmark").font(.geist(14)) }
                if !pause.changed.isEmpty {
                    Section("The whole notice") { ForEach(pause.notice, id: \.self) { Text($0).font(.geist(13.5)) } }
                }
                Section { Text("Your yes is saved with the date and this notice.").font(.geist(12.5)).foregroundStyle(Palette.textSoft) }
            }
            .navigationTitle("Agree to the change?")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Not now") { Task { await model.orderLater(session.api) }; dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Agree") {
                        busy = true
                        Task {
                            if await model.agree(session.api, to: pause) { dismiss() }
                            busy = false
                        }
                    }
                    .disabled(busy)
                }
            }
        }
        .flexibleSheet()
    }
}
