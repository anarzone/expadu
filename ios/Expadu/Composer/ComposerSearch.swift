import API
import Core
import DesignSystem
import SwiftUI

/// Search: conversation titles and what was said in them, newest first, the match marked; the
/// last few searches to tap again. Nothing found offers to ask Composer instead.
struct ComposerSearch: View {
    /// Where the title sits, as the drawer's does.
    let top: CGFloat
    let close: () -> Void
    let opened: () -> Void
    let ask: (String) -> Void
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @AppStorage("composer.recentSearches") private var stored = ""
    @State private var query = ""
    @FocusState private var typing: Bool

    private let ink = Color(light: 0x211D15, dark: 0xF6F1E5)
    private let soft = Color(light: 0x6A6253, dark: 0xA9A59C)

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Text("Search").font(.posterGrotesque(30)).tracking(-0.4).foregroundStyle(ink).accessibilityAddTraits(.isHeader)
                Spacer()
                Button(action: close) {
                    Image(systemName: "xmark").font(.system(size: 16, weight: .semibold)).foregroundStyle(ink)
                        .frame(width: 44, height: 44)
                        .glassEffect(.regular.interactive(), in: .circle)
                        .contentShape(.circle)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("Close search")
            }
            .frame(height: 44)
            .padding(.leading, 20).padding(.trailing, 16).padding(.top, top).padding(.bottom, 10)
            ScrollView {
                VStack(alignment: .leading, spacing: 4) {
                    let words = query.trimmingCharacters(in: .whitespaces)
                    if words.isEmpty {
                        if !recent.isEmpty {
                            heading("Recent searches")
                            FlowLayout(spacing: 8) {
                                ForEach(recent, id: \.self) { term in
                                    Button { query = term } label: {
                                        Label(term, systemImage: "clock").labelStyle(Tight())
                                            .font(.geist(15, .medium)).foregroundStyle(ink)
                                            .padding(.horizontal, 12).frame(height: 38)
                                            .background(ink.opacity(0.07), in: .rect(cornerRadius: 11, style: .continuous))
                                    }
                                    .buttonStyle(PressStyle())
                                }
                            }
                            .padding(.horizontal, 12)
                        } else {
                            Text("Search your conversations by what you or Composer said.").font(.geist(15)).foregroundStyle(soft)
                                .padding(.horizontal, 12).padding(.top, 12)
                        }
                    } else {
                        let hits = Self.hits(model.workspace?.recents ?? [], words)
                        if hits.isEmpty {
                            VStack(alignment: .leading, spacing: 14) {
                                Text("Nothing in your conversations for “\(words)”.").font(.geist(16)).foregroundStyle(soft)
                                Button { remember(words); ask(words) } label: {
                                    Label("Ask Composer", systemImage: "sparkle").labelStyle(Tight())
                                        .font(.geist(16.5, .semibold)).foregroundStyle(Palette.onAction)
                                        .padding(.horizontal, 18).frame(height: 48)
                                        .buttonFace(Palette.action, radius: 16)
                                }
                                .buttonStyle(PressStyle())
                            }
                            .padding(.horizontal, 12).padding(.top, 12)
                        }
                        ForEach(["Today", "Earlier"], id: \.self) { group in
                            let rows = hits.filter { (TodayPage.parse($0.conversation.lastAt).map(ComposerModel.day) == ComposerModel.today) == (group == "Today") }
                            if !rows.isEmpty {
                                heading(group)
                                ForEach(rows, id: \.conversation.id) { hit in row(hit, words) }
                            }
                        }
                    }
                }
                .padding(.horizontal, 8).padding(.bottom, 20)
            }
            .scrollIndicators(.hidden)
            .scrollDismissesKeyboard(.immediately)
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass").font(.system(size: 16, weight: .semibold)).foregroundStyle(soft)
                TextField("Search conversations", text: $query).font(.geist(17)).foregroundStyle(ink)
                    .focused($typing).submitLabel(.search)
                    .onSubmit { remember(query) }
                if !query.isEmpty {
                    Button { query = ""; typing = true } label: { Image(systemName: "xmark.circle.fill").foregroundStyle(soft) }
                        .buttonStyle(.plain).accessibilityLabel("Clear search")
                }
            }
            .padding(.horizontal, 16).frame(height: 52)
            .glassEffect(.regular, in: .rect(cornerRadius: 18, style: .continuous))
            .padding(.horizontal, 14).padding(.bottom, 8)
        }
        .background(Color(light: 0xEFE8DA, dark: 0x12141F).ignoresSafeArea())
        // The keyboard once the cover has slid in: focus asked for mid-slide is dropped.
        .task { try? await Task.sleep(for: .milliseconds(120)); typing = true }
    }

    private var recent: [String] { stored.split(separator: "\n").map(String.init) }

    /// The last four searches, newest first.
    private func remember(_ term: String) {
        let term = term.trimmingCharacters(in: .whitespaces)
        guard !term.isEmpty else { return }
        stored = ([term] + recent.filter { $0.caseInsensitiveCompare(term) != .orderedSame }).prefix(4).joined(separator: "\n")
    }

    private func heading(_ text: String) -> some View {
        Text(text).font(.geist(14, .medium)).foregroundStyle(soft).padding(.horizontal, 12).padding(.top, 18).padding(.bottom, 6)
    }

    private func row(_ hit: Hit, _ words: String) -> some View {
        Button {
            remember(words)
            Task {
                await model.open(hit.conversation.id, api: session.api)
                opened()
            }
        } label: {
            HStack(alignment: .top, spacing: 14) {
                RoundedRectangle(cornerRadius: 2).fill(ComposerKind.colour(hit.conversation) ?? ink.opacity(0.3)).frame(width: 4, height: 20).padding(.top, 2)
                VStack(alignment: .leading, spacing: 3) {
                    Text(Self.marked(hit.conversation.title.isEmpty ? "A conversation" : hit.conversation.title, words, ink: ink))
                        .font(.geist(17)).lineLimit(1)
                    if let snippet = hit.snippet {
                        Text(Self.marked(snippet, words, ink: soft)).font(.geist(14)).lineLimit(2)
                    }
                }
                Spacer(minLength: 0)
                if model.busy == "open-\(hit.conversation.id)" { ProgressView().controlSize(.small) }
            }
            .padding(.horizontal, 12).padding(.vertical, 12)
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
    }

    struct Hit {
        let conversation: ComposerConversation
        let snippet: String?
    }

    /// Titles first; else the first thing said that holds the words, cut to start near them.
    static func hits(_ conversations: [ComposerConversation], _ words: String) -> [Hit] {
        conversations.compactMap { conversation in
            if conversation.title.localizedCaseInsensitiveContains(words) { return Hit(conversation: conversation, snippet: nil) }
            for message in conversation.messages {
                let text = message.lead?.isEmpty == false ? message.lead! : message.text
                guard let range = text.range(of: words, options: .caseInsensitive) else { continue }
                var start = text.index(range.lowerBound, offsetBy: -34, limitedBy: text.startIndex) ?? text.startIndex
                if start != text.startIndex, let space = text[start..<range.lowerBound].firstIndex(of: " ") { start = text.index(after: space) }
                return Hit(conversation: conversation, snippet: (start == text.startIndex ? "" : "…") + text[start...])
            }
            return nil
        }
    }

    /// The words marked where they appear.
    static func marked(_ text: String, _ words: String, ink: Color) -> AttributedString {
        var result = AttributedString(text)
        result.foregroundColor = ink
        var from = result.startIndex
        while let range = result[from...].range(of: words, options: .caseInsensitive) {
            result[range].backgroundColor = Palette.posterYellow.opacity(0.7)
            result[range].foregroundColor = Color(hex: 0x211D15)
            from = range.upperBound
        }
        return result
    }
}
