import API
import Core
import DesignSystem
import SwiftUI

/// Pages the whole app opens over whatever is showing: from Today, Composer and the account menu alike.
@Observable @MainActor
final class Pages {
    /// What Expadu knows about you.
    var knows = false
    /// Settings › Notifications.
    var notifications = false

    /// Opens it as iOS pushes a page: no rise from the bottom; the page slides in from the right itself.
    func openKnows() {
        var quiet = Transaction()
        quiet.disablesAnimations = true
        withTransaction(quiet) { knows = true }
    }

    func openNotifications() {
        var quiet = Transaction()
        quiet.disablesAnimations = true
        withTransaction(quiet) { notifications = true }
    }
}

/// What Expadu knows about the person, and everything they can do with it (canvas "Expadu iPhone:
/// What Expadu knows", owner's go on 16 Nov): personalisation on or off, what they told Composer
/// (add, change, forget with Undo), what it learned they like, their situation and German level.
@Observable @MainActor
final class KnowsModel {
    var knows: Knows?
    var problem: String?
    var editing: Int?
    var draft = ""
    var editRefusal: String?
    var adding = false
    var addDraft = ""
    var addRefusal: String?
    var busy = false
    var toast: Toast?
    /// Just turned on, or just erased: said once at the top.
    var justOn = false
    var erased = false
    var sixteen = false
    var needSixteen = false
    var kept = 0
    var forgot = 0

    struct Toast: Equatable {
        let id = UUID()
        let text: String
        let fact: Knows.Fact
        let index: Int

        static func == (a: Toast, b: Toast) -> Bool { a.id == b.id }
    }

    func load(_ api: AppAPI) async {
        do {
            knows = try await api.knows()
            problem = nil
        } catch {
            problem = Self.words(error)
        }
    }

    func edit(_ fact: Knows.Fact) {
        withAnimation(.snappy) {
            editing = fact.id
            draft = fact.text
            editRefusal = nil
            adding = false
        }
    }

    func save(_ api: AppAPI) async {
        guard let id = editing, let fact = knows?.facts.first(where: { $0.id == id }) else { return }
        let text = draft.trimmingCharacters(in: .whitespacesAndNewlines)
        if text == fact.text { withAnimation(.snappy) { editing = nil }; return }
        busy = true
        defer { busy = false }
        do {
            let changed = try await api.change(fact, to: text)
            withAnimation(.snappy) {
                if let at = knows?.facts.firstIndex(where: { $0.id == id }) { knows?.facts[at] = changed }
                editing = nil
            }
            kept += 1
        } catch {
            withAnimation(.snappy) { editRefusal = Self.words(error) }
        }
    }

    func add(_ api: AppAPI) async {
        let text = addDraft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty else { return }
        busy = true
        defer { busy = false }
        do {
            let fact = try await api.remember(text)
            withAnimation(.snappy) {
                knows?.facts.append(fact)
                adding = false
                addDraft = ""
                addRefusal = nil
            }
            kept += 1
        } catch {
            withAnimation(.snappy) { addRefusal = Self.words(error) }
        }
    }

    /// Gone at once, with Undo for a few seconds (no confirmation: it comes back).
    func forget(_ fact: Knows.Fact, _ api: AppAPI) async {
        guard let index = knows?.facts.firstIndex(where: { $0.id == fact.id }) else { return }
        withAnimation(.snappy) {
            _ = knows?.facts.remove(at: index)
            editing = nil
        }
        forgot += 1
        do {
            let gone = try await api.forget(fact)
            let toast = Toast(text: "Forgot: \(gone.text)", fact: gone, index: index)
            withAnimation(.snappy) { self.toast = toast }
            // Long enough to read it and reach Undo.
            try? await Task.sleep(for: .seconds(8))
            if self.toast == toast { withAnimation(.snappy) { self.toast = nil } }
        } catch {
            // Not forgotten after all: back where it was, and why. (The place is read before the change.)
            let at = min(index, knows?.facts.count ?? 0)
            withAnimation(.snappy) { knows?.facts.insert(fact, at: at) }
            problem = Self.words(error)
        }
    }

    /// A kind of event in or out of what they picked: kept at once; back as it was if it doesn't go through.
    func togglePick(_ key: String, _ api: AppAPI) async {
        guard let picks = knows?.picks else { return }
        var next = picks.kinds
        if let at = next.firstIndex(of: key) { next.remove(at: at) } else { next.append(key) }
        knows?.picks?.kinds = next
        do {
            try await api.savePicks(next, done: picks.done)
        } catch {
            if knows?.picks?.kinds == next { knows?.picks?.kinds = picks.kinds }
            problem = Self.words(error)
        }
    }

    func undo(_ api: AppAPI) async {
        guard let toast else { return }
        withAnimation(.snappy) { self.toast = nil }
        do {
            let back = try await api.restore(toast.fact)
            let at = min(toast.index, knows?.facts.count ?? 0)
            withAnimation(.snappy) { knows?.facts.insert(back, at: at) }
        } catch {
            problem = Self.words(error)
        }
    }

    func turnOn(_ api: AppAPI) async {
        guard sixteen else { withAnimation(.snappy) { needSixteen = true }; return }
        busy = true
        defer { busy = false }
        do {
            try await api.setPersonalisation(true)
            await load(api)
            withAnimation(.snappy) { justOn = true; erased = false; sixteen = false; needSixteen = false }
        } catch {
            problem = Self.words(error)
        }
    }

    func turnOff(_ api: AppAPI) async {
        busy = true
        defer { busy = false }
        do {
            try await api.setPersonalisation(false)
            await load(api)
            withAnimation(.snappy) { erased = true; justOn = false; toast = nil; editing = nil; adding = false }
        } catch {
            problem = Self.words(error)
        }
    }

    func setProfile(_ field: String, _ value: String, _ api: AppAPI) async {
        let before = knows
        withAnimation(.snappy) {
            if field == "situation" { knows?.situation = value } else { knows?.germanLevel = value }
        }
        do {
            try await api.setProfile(field, value)
        } catch {
            withAnimation(.snappy) { knows = before }
            problem = Self.words(error)
        }
    }

    static func words(_ error: Error) -> String {
        switch error as? APIFailure {
        case let .refused(why): why
        case .signedOut: "You’re signed out. Sign in again."
        case .tooManyTries: "Too many tries. Wait a moment."
        default: "Couldn’t reach Expadu. Try again in a moment."
        }
    }
}

struct KnowsView: View {
    @Environment(Session.self) private var session
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var model = KnowsModel()
    @State private var askingOff = false
    /// The switch as it shows: turned off, it asks first, and springs back on Keep it.
    @State private var switchOn = true
    /// In from the right as it opens, out to the right as it goes back.
    @State private var arrived = false
    @FocusState private var focus: Field?

    enum Field { case edit, add }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Button { back() } label: {
                    Image(systemName: "chevron.left").font(.system(size: 17, weight: .semibold)).foregroundStyle(Palette.text)
                }
                .buttonStyle(IconTileStyle(fill: Palette.surface, size: 44))
                .accessibilityLabel("Back")
                Text("What Expadu knows")
                    .font(.posterGrotesque(32)).tracking(-0.4).foregroundStyle(Palette.text)
                    .padding(.top, 14).padding(.horizontal, 4).padding(.bottom, 6)
                    .accessibilityAddTraits(.isHeader)

                if let knows = model.knows {
                    if knows.personalised && knows.current {
                        on(knows)
                    } else {
                        off(knows)
                    }
                    // Its own switch, apart from personalisation: nothing of it is learned or kept.
                    CalendarSection().padding(.top, 26)
                } else if let problem = model.problem {
                    Text(problem).font(.geist(15)).foregroundStyle(Palette.conflictText).padding(.horizontal, 4).padding(.top, 10)
                    PaperworkButton(title: "Try again", quiet: true, small: true) { Task { await model.load(session.api) } }.padding(.top, 10)
                } else {
                    ProgressView().frame(maxWidth: .infinity).padding(.top, 40)
                }
            }
            .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 120)
            // On the content, not beside the erase question: two alerts on one view show only one.
            .alert("That didn’t go through", isPresented: Binding(get: { model.problem != nil && model.knows != nil }, set: { if !$0 { model.problem = nil } })) {
                Button("OK", role: .cancel) {}
            } message: {
                Text(model.problem ?? "")
            }
        }
        .scrollIndicators(.hidden)
        .scrollDismissesKeyboard(.interactively)
        .background(Palette.canvas)
        .overlay(alignment: .bottom) { toast }
        // Gone already as the swipe let go: closed without a second slide.
        .swipeBack { quietly(dismiss) }
        .visualEffect { [arrived] content, proxy in content.offset(x: arrived ? 0 : proxy.size.width) }
        .onAppear { withAnimation(reduceMotion ? nil : .smooth(duration: 0.38)) { arrived = true } }
        .task { await model.load(session.api) }
        .sensoryFeedback(.success, trigger: model.kept)
        .sensoryFeedback(.impact(weight: .light), trigger: model.forgot)
        .alert("Erase what Expadu knows?", isPresented: $askingOff) {
            Button("Erase and turn off", role: .destructive) { Task { await model.turnOff(session.api); switchOn = true } }
            Button("Keep it", role: .cancel) { withAnimation(.snappy) { switchOn = true } }
        } message: {
            Text("What you told Composer, what it learned you like, and Today’s order for you are erased. This can’t be undone.")
        }
    }

    /// Back: out to the right, then closed.
    private func back() {
        withAnimation(reduceMotion ? nil : .smooth(duration: 0.3)) { arrived = false } completion: { quietly(dismiss) }
    }

    private func quietly(_ close: DismissAction) {
        var quiet = Transaction()
        quiet.disablesAnimations = true
        withTransaction(quiet) { close() }
    }

    // MARK: On

    @ViewBuilder
    private func on(_ knows: Knows) -> some View {
        Text("Composer and Today use this to answer for you. Only you see it, and you can change any of it.")
            .font(.geist(15)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
            .padding(.horizontal, 4).padding(.bottom, 18)
        if model.justOn {
            note("On. Tell Composer about yourself, or add it below.")
        }
        HStack(spacing: 14) {
            VStack(alignment: .leading, spacing: 2) {
                Text("Personalisation").font(.geist(16, .semibold)).foregroundStyle(Palette.text)
                Text("Learns from what you open, save and skip, what you tell Composer and the kinds of event you pick.")
                    .font(.geist(13.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 4)
            Toggle("Personalisation", isOn: $switchOn)
                .labelsHidden().disabled(model.busy)
                .onChange(of: switchOn) { _, on in if !on { askingOff = true } }
        }
        .padding(.horizontal, 16).padding(.vertical, 14).card(radius: 18)

        HStack(alignment: .firstTextBaseline) {
            heading("You told Composer")
            Spacer()
            Text("\(knows.facts.count) of \(knows.limit)").font(.geist(13)).foregroundStyle(Palette.textSoft).monospacedDigit()
                .contentTransition(.numericText())
        }
        .padding(.top, 26).padding(.bottom, 8).padding(.horizontal, 4)
        VStack(spacing: 0) {
            ForEach(knows.facts) { fact in
                Group {
                    if model.editing == fact.id { editRow(fact) } else { factRow(fact) }
                }
                .transition(reduceMotion ? .opacity : .asymmetric(insertion: .opacity.combined(with: .move(edge: .bottom)), removal: .opacity.combined(with: .move(edge: .leading))))
                Divider().overlay(Palette.border).padding(.leading, 16)
            }
            if model.adding { addRow } else {
                Button { withAnimation(.snappy) { model.adding = true; model.editing = nil; model.addRefusal = nil } } label: {
                    Label("Add something about you", systemImage: "plus").labelStyle(Tight())
                        .font(.geist(15.5, .semibold)).foregroundStyle(TodayLink.ink)
                        .frame(maxWidth: .infinity, minHeight: 50, alignment: .leading).padding(.horizontal, 16)
                        .contentShape(.rect)
                }
                .buttonStyle(PressStyle())
                .disabled(knows.facts.count >= knows.limit)
            }
        }
        .card(radius: 18)
        .clipShape(.rect(cornerRadius: 18))
        Text("Or just tell Composer: “I’m vegetarian”, “we have a toddler”. Tap one to change or forget it.")
            .font(.geist(13)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
            .padding(.horizontal, 4).padding(.top, 8)

        if let picks = knows.picks, !knows.pickKinds.isEmpty {
            heading("What you picked").padding(.top, 26).padding(.bottom, 4).padding(.horizontal, 4)
            Text("Today puts these first until what you open, save and go to says more. Tap one to take it out or add it.")
                .font(.geist(13.5)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 4).padding(.bottom, 10)
            VStack(alignment: .leading, spacing: 14) {
                LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10), count: 4), spacing: 14) {
                    ForEach(Array(knows.pickKinds.enumerated()), id: \.element.key) { index, kind in
                        PickTile(label: kind.label, on: picks.kinds.contains(kind.key), tilt: PickTile.tilts[index % PickTile.tilts.count]) {
                            Task { await model.togglePick(kind.key, session.api) }
                        }
                    }
                }
                Text(picks.kinds.isEmpty ? "Nothing picked. Today goes by what you open, save and go to." : "\(picks.kinds.count) picked")
                    .font(.geist(13.5, .semibold)).foregroundStyle(Palette.textSoft).contentTransition(.numericText())
            }
            .padding(.horizontal, 14).padding(.top, 16).padding(.bottom, 14)
            .card(radius: 18)
        }

        heading("What you tend to like").padding(.top, 26).padding(.bottom, 8).padding(.horizontal, 4)
        VStack(alignment: .leading, spacing: 0) {
            ForEach(Array(knows.leanings.enumerated()), id: \.offset) { _, leaning in
                HStack(spacing: 12) {
                    Text(leaning.name).font(.geist(15.5, .medium)).foregroundStyle(Palette.text)
                    Spacer(minLength: 8)
                    Text(leaning.level).font(.geist(13.5, .semibold)).foregroundStyle(leaning.avoid ? Palette.conflictText : Palette.textSoft)
                }
                .frame(minHeight: 48).padding(.horizontal, 16)
                Divider().overlay(Palette.border).padding(.leading, 16)
            }
            Text(knows.taste.map { "Learned from \($0.signals) things you opened, saved or skipped in the last 90 days. It changes as you do." }
                 ?? "Not enough yet. It learns as you open, save and skip things in Today, Travel and Composer.")
                .font(.geist(13)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 16).padding(.vertical, 12)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .card(radius: 18)

        heading("About you").padding(.top, 26).padding(.bottom, 8).padding(.horizontal, 4)
        VStack(alignment: .leading, spacing: 0) {
            profileRow("Here as", field: "situation", value: knows.situation, options: Knows.situations)
            Divider().overlay(Palette.border).padding(.leading, 16)
            profileRow("German", field: "german_level", value: knows.germanLevel, options: Knows.germanLevels)
            Divider().overlay(Palette.border).padding(.leading, 16)
            Text("From your profile. Composer reads it so events in English come first while your German grows.")
                .font(.geist(13)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 16).padding(.vertical, 12)
        }
        .card(radius: 18)

        RoutinesSection().padding(.top, 26)

        Text("**Never kept or learned:** health, religion or beliefs, sexuality, politics or a union, and places that would say them. Nor exactly where you are: Composer gets only your nearest stop.")
            .font(.geist(13.5)).foregroundStyle(Palette.posterLead).fixedSize(horizontal: false, vertical: true)
            .padding(.horizontal, 16).padding(.vertical, 13)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Palette.quiet, in: .rect(cornerRadius: 16))
            .padding(.top, 22)

        Button("Turn off and erase all of it") { askingOff = true }
            .font(.geist(15.5, .semibold)).foregroundStyle(Palette.conflictText)
            .frame(minHeight: 44).padding(.horizontal, 4).padding(.top, 14)
            .disabled(model.busy)
    }

    private func factRow(_ fact: Knows.Fact) -> some View {
        Button { model.edit(fact) } label: {
            HStack(spacing: 12) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(fact.text).font(.geist(16, .medium)).foregroundStyle(Palette.text).multilineTextAlignment(.leading)
                    Text(Self.sub(fact)).font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                }
                Spacer(minLength: 8)
                Image(systemName: "pencil").font(.system(size: 15, weight: .medium)).foregroundStyle(Palette.borderStrong)
            }
            .padding(.leading, 16).padding(.trailing, 14).padding(.vertical, 12)
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .accessibilityHint("Change or forget it")
        .contextMenu {
            Button("Forget", systemImage: "trash", role: .destructive) { Task { await model.forget(fact, session.api) } }
        }
    }

    private func editRow(_ fact: Knows.Fact) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Change what it remembers").font(.geist(12.5, .semibold)).foregroundStyle(Palette.textSoft)
            field("", text: Binding(get: { model.draft }, set: { model.draft = $0; model.editRefusal = nil }), focused: .edit) {
                Task { await model.save(session.api) }
            }
            if let refusal = model.editRefusal { refusalLine(refusal) }
            HStack(spacing: 6) {
                Button("Forget") { Task { await model.forget(fact, session.api) } }
                    .font(.geist(15, .semibold)).foregroundStyle(Palette.conflictText).frame(minHeight: 44)
                Spacer()
                Button("Cancel") { withAnimation(.snappy) { model.editing = nil } }
                    .font(.geist(15, .semibold)).foregroundStyle(Palette.textSoft).frame(minHeight: 44).padding(.horizontal, 8)
                PaperworkButton(title: "Save", small: true, working: model.busy) { Task { await model.save(session.api) } }
            }
        }
        .padding(.leading, 16).padding(.trailing, 14).padding(.vertical, 12)
    }

    private var addRow: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Something lasting about you").font(.geist(12.5, .semibold)).foregroundStyle(Palette.textSoft)
            field("Like “Vegan” or “Cycles everywhere”", text: Binding(get: { model.addDraft }, set: { model.addDraft = $0; model.addRefusal = nil }), focused: .add) {
                Task { await model.add(session.api) }
            }
            if let refusal = model.addRefusal { refusalLine(refusal) }
            HStack(spacing: 6) {
                Spacer()
                Button("Cancel") { withAnimation(.snappy) { model.adding = false; model.addDraft = "" } }
                    .font(.geist(15, .semibold)).foregroundStyle(Palette.textSoft).frame(minHeight: 44).padding(.horizontal, 8)
                PaperworkButton(title: "Add", small: true, working: model.busy) { Task { await model.add(session.api) } }
            }
        }
        .padding(.leading, 16).padding(.trailing, 14).padding(.vertical, 12)
        .transition(.opacity)
    }

    private func field(_ prompt: String, text: Binding<String>, focused: Field, submit: @escaping () -> Void) -> some View {
        TextField(prompt, text: text)
            .font(.geist(16, .medium)).foregroundStyle(Palette.text)
            .submitLabel(.done)
            .onSubmit(submit)
            .focused($focus, equals: focused)
            // Opened to type in: the keyboard comes with it.
            .onAppear { focus = focused }
            .padding(.horizontal, 12).frame(height: 44)
            .background(Palette.canvas, in: .rect(cornerRadius: 12))
            .overlay { RoundedRectangle(cornerRadius: 12).strokeBorder(focus == focused ? Palette.focus : Palette.border, lineWidth: 1.5) }
    }

    private func refusalLine(_ words: String) -> some View {
        Text(words).font(.geist(13.5)).foregroundStyle(Palette.conflictText).fixedSize(horizontal: false, vertical: true)
            .transition(.opacity)
    }

    private func profileRow(_ label: String, field: String, value: String?, options: [(key: String, label: String)]) -> some View {
        Menu {
            Picker(label, selection: Binding(get: { value ?? "" }, set: { new in Task { await model.setProfile(field, new, session.api) } })) {
                ForEach(options, id: \.key) { option in Text(option.label).tag(option.key) }
            }
        } label: {
            HStack(spacing: 10) {
                Text(label).font(.geist(15.5, .medium)).foregroundStyle(Palette.text)
                Spacer(minLength: 8)
                Text(options.first { $0.key == value }?.label ?? "Not set").font(.geist(15)).foregroundStyle(Palette.textSoft)
                    .contentTransition(.opacity)
                Image(systemName: "chevron.up.chevron.down").font(.system(size: 12, weight: .semibold)).foregroundStyle(Palette.borderStrong)
            }
            .frame(minHeight: 50).padding(.leading, 16).padding(.trailing, 14)
            .contentShape(.rect)
        }
    }

    // MARK: Off

    @ViewBuilder
    private func off(_ knows: Knows) -> some View {
        if model.erased {
            note("Erased. Nothing about you is kept.").padding(.top, 8)
        }
        // On under an older wording: the same yes, again, to what it says now.
        let again = knows.personalised && !knows.current
        VStack(alignment: .leading, spacing: 0) {
            Text(again ? "Personalisation says more now" : "Let Expadu get to know you")
                .font(.posterGrotesque(23)).foregroundStyle(Palette.text).padding(.bottom, 6)
            Text(again ? "It now also keeps the kinds of event you pick on Today. Turn it on again to keep using it."
                 : "Then Composer and Today answer for you, not for everyone. Off, they still work, just the same for all.")
                .font(.geist(15)).foregroundStyle(Palette.textSoft).fixedSize(horizontal: false, vertical: true).padding(.bottom, 10)
            ForEach(["What you open, save and skip in Today, Travel and Composer", "What you tell Composer about yourself, like “I’m vegetarian”", "The kinds of event you pick, like music or tours", "Your situation and German level"], id: \.self) { use in
                HStack(alignment: .top, spacing: 12) {
                    Image(systemName: "checkmark").font(.system(size: 13, weight: .bold)).foregroundStyle(Palette.focus)
                        .frame(width: 30, height: 30).background(Palette.selectBackground, in: .rect(cornerRadius: 10))
                    Text(use).font(.geist(15)).foregroundStyle(Palette.text).fixedSize(horizontal: false, vertical: true).padding(.top, 5)
                }
                .padding(.vertical, 6)
            }
            Text("Never: health, religion or beliefs, sexuality, politics or a union, or exactly where you are. Today is put in order for you by Claude Haiku (Anthropic). Kept 90 days, all of it on this page; turning it off erases it.")
                .font(.geist(13.5)).foregroundStyle(Palette.posterLead).fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 14).padding(.vertical, 12)
                .background(Palette.quiet, in: .rect(cornerRadius: 14))
                .padding(.top, 10)
            Button {
                withAnimation(.snappy) { model.sixteen.toggle(); model.needSixteen = false }
            } label: {
                HStack(spacing: 12) {
                    Image(systemName: model.sixteen ? "checkmark.square.fill" : "square").font(.system(size: 22))
                        .foregroundStyle(model.sixteen ? Palette.action : Palette.borderStrong)
                        .contentTransition(.symbolEffect(.replace))
                    Text("I’m 16 or older").font(.geist(15.5, .medium)).foregroundStyle(Palette.text)
                }
                .frame(minHeight: 48)
            }
            .buttonStyle(PressStyle())
            .accessibilityAddTraits(model.sixteen ? [.isSelected] : [])
            .padding(.top, 10)
            if model.needSixteen {
                Text("Tick that you’re 16 or older to turn it on.").font(.geist(13.5)).foregroundStyle(Palette.conflictText)
                    .padding(.leading, 34).padding(.bottom, 6).transition(.opacity)
            }
            PaperworkButton(title: again ? "Turn it on again" : "Turn on personalisation", grow: true, working: model.busy) {
                Task { await model.turnOn(session.api) }
            }
            .padding(.top, 8)
        }
        .padding(.horizontal, 16).padding(.top, 18).padding(.bottom, 16)
        .card(radius: 18)
        .padding(.top, 10)
    }

    // MARK: Pieces

    private func heading(_ words: String) -> some View {
        Text(words).font(.posterGrotesque(19)).foregroundStyle(Palette.text).accessibilityAddTraits(.isHeader)
    }

    private func note(_ words: String) -> some View {
        Text(words).font(.geist(14.5, .semibold)).foregroundStyle(Palette.goodText)
            .padding(.horizontal, 14).padding(.vertical, 12).frame(maxWidth: .infinity, alignment: .leading)
            .background(Palette.goodBackground, in: .rect(cornerRadius: 16))
            .padding(.bottom, 14)
            .transition(.opacity.combined(with: .move(edge: .top)))
    }

    @ViewBuilder
    private var toast: some View {
        if let toast = model.toast {
            HStack(spacing: 10) {
                Text(toast.text).font(.geist(14.5, .medium)).foregroundStyle(.white).lineLimit(2)
                Spacer(minLength: 8)
                Button("Undo") { Task { await model.undo(session.api) } }
                    .font(.geist(15, .bold)).underline().foregroundStyle(Palette.posterYellow)
                    .frame(minHeight: 44).padding(.horizontal, 8)
            }
            .padding(.leading, 16).padding(.trailing, 8).frame(minHeight: 52)
            .background(Color(hex: 0x211D15), in: .rect(cornerRadius: 18))
            .shadow(color: .black.opacity(0.25), radius: 16, y: 8)
            .padding(.horizontal, 16).padding(.bottom, 24)
            .transition(.move(edge: .bottom).combined(with: .opacity))
            .id(toast.id)
        }
    }

    /// "Told Composer · 14 Nov" or "Added here · 16 Nov".
    static func sub(_ fact: Knows.Fact) -> String {
        let when = (try? Date.ISO8601FormatStyle(includingFractionalSeconds: true).parse(fact.createdAt))
            ?? (try? Date.ISO8601FormatStyle().parse(fact.createdAt))
        let day = when.map { $0.formatted(.dateTime.day().month(.abbreviated)) }
        return (fact.source == "added" ? "Added here" : "Told Composer") + (day.map { " · \($0)" } ?? "")
    }
}
