import API
import Core
import DesignSystem
import SwiftUI

/// The drawer under the page (swipe from the left, or the menu): Composer's conversations, newest
/// first, each opened, renamed or deleted; a new question; and Composer's settings.
struct ComposerDrawer: View {
    @Binding var open: Bool
    @Binding var settings: Bool
    @Binding var searching: Bool
    /// 0 closed, 1 open: it slides in a little and comes up out of the dark as the page moves away.
    let progress: CGFloat
    /// Where the title sits, as the page's own buttons do.
    let top: CGFloat
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @State private var all = false
    @State private var renaming: ComposerConversation?
    @State private var name = ""
    @State private var deleting: ComposerConversation?

    private let ink = Color(light: 0x211D15, dark: 0xF6F1E5)
    private let soft = Color(light: 0x6A6253, dark: 0xA9A59C)

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack {
                Text("Composer").font(.posterGrotesque(30)).tracking(-0.4).foregroundStyle(ink)
                Spacer()
                Button { searching = true } label: {
                    Image(systemName: "magnifyingglass").font(.system(size: 16, weight: .semibold)).foregroundStyle(ink)
                        .frame(width: 44, height: 44)
                        .glassEffect(.regular.interactive(), in: .circle)
                        .contentShape(.circle)
                }
                .buttonStyle(PressStyle())
                .accessibilityLabel("Search conversations")
            }
            .frame(height: 44)
            .padding(.leading, 20).padding(.trailing, 14).padding(.top, top).padding(.bottom, 10)
            ScrollView {
                VStack(alignment: .leading, spacing: 2) {
                    place("My Plans", symbol: "calendar", .plans)
                    place("Saved", symbol: "star", .saved)
                    Text("Recents").font(.geist(14, .medium)).foregroundStyle(soft)
                        .padding(.horizontal, 12).padding(.top, 18).padding(.bottom, 6)
                    let rows = recents
                    if rows.isEmpty {
                        Text("Your conversations show here once you ask.")
                            .font(.geist(15)).foregroundStyle(soft).padding(.horizontal, 12).padding(.vertical, 8)
                    }
                    ForEach(all ? rows : Array(rows.prefix(6))) { conversation in
                        row(conversation)
                            .transition(.opacity.combined(with: .move(edge: .leading)))
                    }
                    if rows.count > 6 {
                        Button(all ? "Show fewer" : "See all…") { withAnimation(Motion.ios(0.3)) { all.toggle() } }
                            .font(.geist(16, .medium)).foregroundStyle(ink).padding(.horizontal, 12).frame(height: 48)
                    }
                }
                .padding(.horizontal, 8).padding(.bottom, 120)
                .animation(Motion.ios(0.35), value: recents.map(\.id))
            }
            .scrollIndicators(.hidden)
        }
        .frame(width: 322, alignment: .leading)
        .frame(maxHeight: .infinity, alignment: .top)
        .overlay(alignment: .bottomLeading) { foot }
        .offset(x: (progress - 1) * 56)
        .opacity(progress > 0 ? 0.35 + progress * 0.65 : 0)
        .allowsHitTesting(open)
        .accessibilityHidden(!open)
        .alert("Rename this conversation", isPresented: Binding(get: { renaming != nil }, set: { if !$0 { renaming = nil } })) {
            TextField("Name", text: $name)
            Button("Save") {
                guard let id = renaming?.id else { return }
                Task { await model.rename(id, to: name.trimmingCharacters(in: .whitespaces), api: session.api) }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Leave it empty for Composer’s own title.")
        }
        .alert("Delete this conversation?", isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } })) {
            Button("Delete", role: .destructive) {
                guard let id = deleting?.id else { return }
                Task { await model.delete(id, api: session.api) }
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("This can’t be undone.")
        }
    }

    private var recents: [ComposerConversation] { model.workspace?.recents ?? [] }

    /// My Plans and Saved: open on the paper, in place of the conversation.
    private func place(_ title: String, symbol: String, _ collection: ComposerModel.Collection) -> some View {
        let on = model.collection == collection
        return Button {
            model.show(collection)
            open = false
        } label: {
            HStack(spacing: 14) {
                Group {
                    if collection == .plans { CalendarMark() } else { Image(systemName: symbol).font(.system(size: 17, weight: .medium)) }
                }
                .foregroundStyle(ink).frame(width: 24)
                Text(title).font(.geist(17, on ? .semibold : .medium)).foregroundStyle(ink)
                Spacer(minLength: 0)
            }
            .padding(.horizontal, 12).frame(height: 50)
            .background(on ? ink.opacity(0.08) : .clear, in: .rect(cornerRadius: 14, style: .continuous))
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
    }

    private func row(_ conversation: ComposerConversation) -> some View {
        let on = conversation.id == model.workspace?.active && model.talking && model.collection == nil
        return Button {
            Task {
                await model.open(conversation.id, api: session.api)
                open = false
            }
        } label: {
            HStack(spacing: 14) {
                RoundedRectangle(cornerRadius: 2).fill(ComposerKind.colour(conversation) ?? ink.opacity(0.3)).frame(width: 4, height: 20)
                Text(conversation.title.isEmpty ? "A conversation" : conversation.title).font(.geist(16, on ? .semibold : .regular)).foregroundStyle(ink)
                    .lineLimit(1)
                Spacer(minLength: 0)
                if model.busy == "open-\(conversation.id)" { ProgressView().controlSize(.small) }
            }
            .padding(.horizontal, 12).frame(height: 48)
            .background(on ? ink.opacity(0.08) : .clear, in: .rect(cornerRadius: 14, style: .continuous))
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .contextMenu {
            Button { name = conversation.title; renaming = conversation } label: { Label("Rename", systemImage: "pencil") }
            Button(role: .destructive) { deleting = conversation } label: { Label("Delete", systemImage: "trash") }
        }
        .accessibilityHint("Opens it. Touch and hold to rename or delete.")
    }

    private var foot: some View {
        HStack {
            Button { settings = true } label: {
                Text(session.initial).font(.geist(16, .semibold)).foregroundStyle(Palette.avatarText)
                    .frame(width: 48, height: 48).background(Palette.avatarBackground, in: .circle)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Composer settings")
            Spacer()
            Button {
                Task {
                    await model.newQuestion(session.api)
                    open = false
                }
            } label: {
                Label("New question", systemImage: "plus").labelStyle(Tight())
                    .font(.geist(16.5, .semibold)).foregroundStyle(Color(light: 0xFFF8E6, dark: 0x15130E))
                    .padding(.leading, 16).padding(.trailing, 20).frame(height: 48)
                    .buttonFace(Color(light: 0x211D15, dark: 0xFFFDF8), radius: 16)
            }
            .buttonStyle(PressStyle())
        }
        .padding(.leading, 18).padding(.trailing, 16).padding(.bottom, 0)
        .frame(width: 322)
        .padding(.top, 46)
        // The list fades out behind the foot (.dw-foot::before).
        .background {
            LinearGradient(stops: [.init(color: Color(light: 0xEFE8DA, dark: 0x12141F).opacity(0), location: 0), .init(color: Color(light: 0xEFE8DA, dark: 0x12141F), location: 0.64)],
                           startPoint: .top, endPoint: .bottom)
                .ignoresSafeArea()
                .allowsHitTesting(false)
        }
    }
}

/// What a conversation was about, as the colour of its tab: Travel, Paperwork, events, places, plans.
enum ComposerKind {
    static func colour(_ conversation: ComposerConversation) -> Color? {
        for message in conversation.messages where !message.mine {
            if message.kind == "plan" || message.kind == "change" { return Color(hex: 0xFFCC33) }
            for block in message.blocks {
                switch block {
                case .trip, .departures, .place: return Color(hex: 0x95C11F)
                case .checklist, .record, .proposal, .permission, .handoff, .guidance: return Color(hex: 0xC23B0E)
                case .events: return Color(hex: 0x7A5CF0)
                case .places: return Color(hex: 0x14A3BA)
                case .planChanges: return Color(hex: 0xFFCC33)
                default: continue
                }
            }
        }
        return nil
    }
}

/// Composer's settings: what it sends and to whom, its Paperwork permission, stopping it, and
/// deleting its history. All of it is the server's: nothing here is kept on the phone.
struct ComposerSettings: View {
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @Environment(\.dismiss) private var dismiss
    @State private var paperwork: (enabled: Bool, available: Bool, hasRecord: Bool)?
    @State private var deleteAsked = false
    @State private var withdrawAsked = false
    @State private var headHeight: CGFloat = 0
    @State private var bodyHeight: CGFloat = 0

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(alignment: .top) {
                Text("Composer settings").font(.posterGrotesque(26)).foregroundStyle(Palette.text)
                Spacer()
                CloseButton { dismiss() }
            }
            .padding(.horizontal, 20).padding(.top, 22).padding(.bottom, 14)
            .onGeometryChange(for: CGFloat.self) { $0.size.height } action: { headHeight = $0 }
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    Text("Privacy").font(.posterGrotesque(19)).foregroundStyle(Palette.text)
                    VStack(alignment: .leading, spacing: 8) {
                        if let disclosure = model.disclosure {
                            if disclosure.available == true {
                                Text("Composer sends what you type, with your nearest stop and neighbourhood, to \(disclosure.processorName ?? "its processor") to answer you. It never sends \(ListFormatter.localizedString(byJoining: (disclosure.neverSent ?? []).map { $0.replacingOccurrences(of: "_", with: " ") })).")
                                    .font(.geist(14.5)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                                ForEach(disclosure.notice ?? [], id: \.self) { line in
                                    Text(line).font(.geist(13.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                                }
                                HStack(spacing: 8) {
                                    if let link = disclosure.processorPrivacyUrl.flatMap(URL.init(string:)) {
                                        Link("Privacy notice", destination: link).font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                                        Text("·").foregroundStyle(Palette.textSoft)
                                    }
                                    Button("Withdraw") { withdrawAsked = true }.font(.geist(14.5, .semibold)).foregroundStyle(TodayLink.ink)
                                        .disabled(model.busy == "withdraw")
                                }
                                .frame(minHeight: 36)
                            } else {
                                Text("Composer answers from Expadu’s own information right now; nothing you type is sent on.")
                                    .font(.geist(14.5)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                            }
                        } else {
                            ProgressView()
                        }
                    }
                    .padding(14).frame(maxWidth: .infinity, alignment: .leading).card(radius: 18)
                    VStack(alignment: .leading, spacing: 4) {
                        if let paperwork, paperwork.enabled {
                            Button("Withdraw the Paperwork permission") { Task { await setPaperwork(false) } }
                                .font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink).frame(minHeight: 40)
                        }
                        if !(model.workspace?.recents ?? []).isEmpty {
                            Button("Delete conversation history") { deleteAsked = true }
                                .font(.geist(15, .semibold)).foregroundStyle(Palette.conflictText).frame(minHeight: 40)
                        }
                    }
                    .padding(.horizontal, 2)
                }
                .padding(.horizontal, 16).padding(.bottom, 10)
            }
            .scrollIndicators(.hidden)
            .onScrollGeometryChange(for: CGFloat.self) { $0.contentSize.height } action: { _, now in bodyHeight = now }
        }
        .background(Palette.canvas)
        .fittedSheet(headHeight + bodyHeight)
        .task { paperwork = try? await session.api.paperworkPermission() }
        .alert("Delete conversation history?", isPresented: $deleteAsked) {
            Button("Delete history", role: .destructive) { Task { await model.deleteHistory(session.api); dismiss() } }
            Button("Keep it", role: .cancel) {}
        } message: {
            Text("This can’t be undone.")
        }
        .alert("Stop Composer sending things on?", isPresented: $withdrawAsked) {
            Button("Stop", role: .destructive) { Task { await model.withdraw(session.api) } }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Future processing stops and saved responses are removed. You can ask again any time.")
        }
    }

    private func setPaperwork(_ on: Bool) async {
        let notice = on ? try? await session.api.processingNotice("composer_paperwork") : nil
        do {
            try await session.api.setPaperworkPermission(on, disclosure: notice)
            paperwork = try? await session.api.paperworkPermission()
            model.say(on ? "Composer may read your Paperwork record." : "Composer won’t read your Paperwork record.")
        } catch {
            model.say(ComposerModel.words(error), problem: true)
        }
    }
}
