import API
import Core
import DesignSystem
import SwiftUI

/// The drawer under the page (swipe from the left, or the menu): Composer's conversations, newest
/// first, each opened, renamed or deleted; a new question; and Composer's settings.
struct ComposerDrawer: View {
    @Binding var open: Bool
    @Binding var settings: Bool
    @Environment(ComposerModel.self) private var model
    @Environment(Session.self) private var session
    @State private var query = ""
    @State private var all = false
    @State private var renaming: ComposerConversation?
    @State private var name = ""
    @State private var deleting: ComposerConversation?

    private let ink = Color(light: 0x211D15, dark: 0xF6F1E5)
    private let soft = Color(light: 0x6A6253, dark: 0xA9A59C)

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text("Composer").font(.posterGrotesque(30)).tracking(-0.4).foregroundStyle(ink)
                .padding(.horizontal, 20).padding(.top, 14).padding(.bottom, 12)
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass").font(.system(size: 15, weight: .semibold)).foregroundStyle(soft)
                TextField("Search conversations", text: $query).font(.geist(16)).foregroundStyle(ink)
                if !query.isEmpty {
                    Button { query = "" } label: { Image(systemName: "xmark.circle.fill").foregroundStyle(soft) }
                        .buttonStyle(.plain).accessibilityLabel("Clear search")
                }
            }
            .padding(.horizontal, 12).frame(height: 42)
            .background(ink.opacity(0.07), in: .rect(cornerRadius: 12, style: .continuous))
            .padding(.horizontal, 14)
            ScrollView {
                VStack(alignment: .leading, spacing: 2) {
                    Text(query.isEmpty ? "Recents" : "Matching").font(.geist(14, .medium)).foregroundStyle(soft)
                        .padding(.horizontal, 12).padding(.top, 18).padding(.bottom, 6)
                    let rows = recents
                    if rows.isEmpty {
                        Text(query.isEmpty ? "Your conversations show here once you ask." : "Nothing in your conversations for “\(query)”.")
                            .font(.geist(15)).foregroundStyle(soft).padding(.horizontal, 12).padding(.vertical, 8)
                    }
                    ForEach(all || !query.isEmpty ? rows : Array(rows.prefix(6))) { conversation in
                        row(conversation)
                    }
                    if query.isEmpty, rows.count > 6 {
                        Button(all ? "Show fewer" : "See all…") { withAnimation(Motion.ios(0.3)) { all.toggle() } }
                            .font(.geist(16, .medium)).foregroundStyle(ink).padding(.horizontal, 12).frame(height: 48)
                    }
                }
                .padding(.horizontal, 8).padding(.bottom, 120)
            }
            .scrollIndicators(.hidden)
        }
        .frame(width: 322, alignment: .leading)
        .frame(maxHeight: .infinity, alignment: .top)
        .overlay(alignment: .bottomLeading) { foot }
        .opacity(open ? 1 : 0)
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

    private var recents: [ComposerConversation] {
        let list = model.workspace?.recents ?? []
        guard !query.isEmpty else { return list }
        return list.filter { $0.title.localizedCaseInsensitiveContains(query) }
    }

    private func row(_ conversation: ComposerConversation) -> some View {
        let on = conversation.id == model.workspace?.active && model.talking
        return Button {
            Task {
                await model.open(conversation.id, api: session.api)
                withAnimation(Motion.ios(0.4)) { open = false }
            }
        } label: {
            HStack(spacing: 14) {
                RoundedRectangle(cornerRadius: 2).fill(on ? Palette.action : ink.opacity(0.3)).frame(width: 4, height: 20)
                Text(conversation.title.isEmpty ? "A conversation" : conversation.title).font(.geist(17, on ? .semibold : .regular)).foregroundStyle(ink)
                    .lineLimit(1)
                Spacer(minLength: 0)
                if model.busy == "open-\(conversation.id)" { ProgressView().controlSize(.small) }
            }
            .padding(.horizontal, 12).frame(height: 50)
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
                    .frame(width: 44, height: 44).background(Palette.avatarBackground, in: .circle)
            }
            .buttonStyle(PressStyle())
            .accessibilityLabel("Composer settings")
            Spacer()
            Button {
                Task {
                    await model.newQuestion(session.api)
                    withAnimation(Motion.ios(0.4)) { open = false }
                }
            } label: {
                Label("New question", systemImage: "plus").labelStyle(Tight())
                    .font(.geist(16.5, .semibold)).foregroundStyle(Color(light: 0xFFF8E6, dark: 0x15130E))
                    .padding(.leading, 16).padding(.trailing, 20).frame(height: 48)
                    .buttonFace(Color(light: 0x211D15, dark: 0xFFFDF8), radius: 16)
            }
            .buttonStyle(PressStyle())
        }
        .padding(.leading, 18).padding(.trailing, 16).padding(.bottom, 12)
        .frame(width: 322)
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
                            } else {
                                Text("Composer answers from Expadu’s own information right now; nothing you type is sent on.")
                                    .font(.geist(14.5)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true)
                            }
                        } else {
                            ProgressView()
                        }
                    }
                    .padding(14).frame(maxWidth: .infinity, alignment: .leading).card(radius: 18)
                    if let paperwork, paperwork.available, paperwork.hasRecord {
                        Toggle(isOn: Binding(get: { paperwork.enabled }, set: { on in Task { await setPaperwork(on) } })) {
                            VStack(alignment: .leading, spacing: 2) {
                                Text("Read your Paperwork record").font(.geist(15.5, .semibold)).foregroundStyle(Palette.text)
                                Text("Composer asks first; this remembers your Allow until you turn it off.").font(.geist(13)).foregroundStyle(Palette.textSoft)
                            }
                        }
                        .tint(PaperworkLook.green)
                        .padding(14).card(radius: 18)
                    }
                    VStack(spacing: 0) {
                        if model.disclosure?.available == true {
                            Button { withdrawAsked = true } label: {
                                PaperworkRow(symbol: "stop.circle", title: "Stop Composer sending things on", note: "Future answers come from Expadu alone; saved responses are removed", end: nil)
                            }
                            .buttonStyle(.plain)
                            PaperworkDivider()
                        }
                        Button { deleteAsked = true } label: {
                            PaperworkRow(symbol: "trash", tint: Palette.conflictText, back: Palette.conflictBackground, title: "Delete conversation history",
                                         note: "Every conversation, for good", end: nil)
                        }
                        .buttonStyle(.plain)
                        .disabled((model.workspace?.recents ?? []).isEmpty)
                    }
                    .card(radius: 18)
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
