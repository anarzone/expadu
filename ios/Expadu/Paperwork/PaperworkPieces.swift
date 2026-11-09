import API
import Core
import DesignSystem
import SwiftUI

/// The canvas's Paperwork colours (paperwork.css): each topic's own, the stamps' inks, the dates'.
enum PaperworkLook {
    static let green = Color(light: 0x2F9E5B, dark: 0x6AD08F)
    static let greenSoft = Color(light: 0xE4F4E8, dark: 0x1F3A28)
    static let greenInk = Color(light: 0x17603A, dark: 0x86E2AA)
    static let purple = Color(light: 0x6A459B, dark: 0xC4A6F0)
    static let purpleSoft = Color(light: 0xEFE7FB, dark: 0x2E2440)
    static let purpleInk = Color(light: 0x5A3887, dark: 0xCDB4F5)
    static let red = Color(light: 0xC2321A, dark: 0xFF7A5C)
    static let apptBack = Color(light: 0xEAF1F8, dark: 0x1E2C3D)
    static let apptInk = Color(light: 0x2B4A75, dark: 0xA9C8F0)
    static let warnBack = Color(light: 0xFFF1D6, dark: 0x3A2E14)
    static let warnInk = Color(light: 0x6B4300, dark: 0xF3C66C)
    static let band = Color(hex: 0x00768B)
    static let amber = Color(hex: 0xC98208)
    static let blockBack = Color(light: 0xFBE2D4, dark: 0x3D211A)
    static let blockInk = Color(light: 0xA9320B, dark: 0xFF8D72)

    /// A task's colour and symbol come from its topic, as the web's TopicIcon.
    static func topic(_ topic: String?) -> (color: Color, symbol: String) {
        switch topic {
        case "address": (Color(hex: 0x2F6FD0), "mappin.and.ellipse")
        case "health": (Color(hex: 0x2F9E5B), "cross.case.fill")
        case "money": (Color(hex: 0xC98208), "creditcard.fill")
        case "tax": (Color(hex: 0x8A5A2B), "eurosign.circle.fill")
        case "work": (Color(hex: 0x2F78C8), "briefcase.fill")
        case "family": (Color(hex: 0x6A459B), "person.2.fill")
        case "education": (Color(hex: 0x2F9E5B), "book.fill")
        default: (Color(hex: 0x6A459B), "doc.text.fill")
        }
    }

    /// The stamp on a task: its word and its ink (the canvas's WF).
    static func stamp(_ process: PaperworkProcess) -> (word: String, ink: Color, tilt: Double, struck: Bool) {
        let prep = Color(light: 0x00768B, dark: 0x5FC9DA), idle = Color(light: 0x6A6253, dark: 0xB8AE9C)
        if !process.tracked { return ("Not started", idle, 4, false) }
        return switch process.workflow {
        case "not_started": ("Tracking", prep, -6, false)
        case "preparing": ("Preparing", prep, -6, false)
        case "action_required": ("Needs you", red, -6, false)
        case "blocked": ("Blocked", red, -6, false)
        case "waiting_authority": ("Waiting", Color(light: 0xB06F00, dark: 0xF3B54A), -6, false)
        case "submitted": ("Submitted", Color(light: 0x2F6FD0, dark: 0x86B4F5), -6, false)
        case "completed": ("Done", green, -6, false)
        case "cancelled": ("Cancelled", idle, -6, true)
        default: (PaperworkWords.workflow[process.workflow] ?? "Tracking", prep, -6, false)
        }
    }
}

/// A progress ring: the track in the colour's tint, the done part in the colour itself.
struct PaperworkRing<Center: View>: View {
    let value: Double
    let color: Color
    var line: CGFloat = 4
    @ViewBuilder let center: Center

    var body: some View {
        ZStack {
            Circle().stroke(color.opacity(0.18), lineWidth: line)
            Circle().trim(from: 0, to: max(0.001, min(1, value)))
                .stroke(color, style: StrokeStyle(lineWidth: line, lineCap: .round))
                .rotationEffect(.degrees(-90))
                .animation(Motion.ios(0.7), value: value)
            center
        }
    }
}

/// The marker stamp, tilted, in its ink; it lands again whenever `round` changes.
struct PaperworkStamp: View {
    let word: String
    let ink: Color
    var tilt: Double = -6
    var struck = false
    var size: CGFloat = 15
    var round = 0
    @State private var landed = true
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        Text(word.uppercased())
            .font(.posterMarker(size)).strikethrough(struck)
            .lineLimit(1).fixedSize()
            .foregroundStyle(ink)
            .padding(.horizontal, size * 0.55).padding(.top, 4).padding(.bottom, 2)
            .background(ink.opacity(0.08), in: .rect(cornerRadius: 6))
            .overlay { RoundedRectangle(cornerRadius: 6).strokeBorder(ink, lineWidth: 2) }
            .rotationEffect(.degrees(tilt))
            .scaleEffect(landed ? 1 : 1.6)
            .opacity(landed ? 1 : 0)
            .onChange(of: round) {
                guard !reduceMotion else { return }
                landed = false
                withAnimation(.spring(duration: 0.45, bounce: 0.35)) { landed = true }
            }
            .accessibilityLabel(word)
    }
}

/// A filter, as iOS 26 draws one: the system's segmented control for a few short choices, each
/// with its count. No pill chips.
struct PaperworkSegments<Key: Hashable>: View {
    let items: [(key: Key, title: String, count: Int?)]
    @Binding var chosen: Key

    var body: some View {
        Picker("Show", selection: $chosen.animation(Motion.ios(0.35))) {
            ForEach(items, id: \.key) { item in
                Text(item.count.map { "\(item.title) \($0)" } ?? item.title).tag(item.key)
            }
        }
        .pickerStyle(.segmented)
        .controlSize(.large)
    }
}

/// A filter with many or long choices: a pull-down menu named by what it shows now, as Mail and
/// Files do. The choice is plain text with a chevron, no pill.
struct PaperworkFilterMenu<Key: Hashable>: View {
    let label: String
    let items: [(key: Key, title: String, count: Int?)]
    @Binding var chosen: Key

    var body: some View {
        Menu {
            Picker(label, selection: $chosen.animation(Motion.ios(0.35))) {
                ForEach(items, id: \.key) { item in
                    Text(item.count.map { "\(item.title) (\($0))" } ?? item.title).tag(item.key)
                }
            }
        } label: {
            HStack(spacing: 4) {
                Text(items.first { $0.key == chosen }?.title ?? label).lineLimit(1)
                Image(systemName: "chevron.up.chevron.down").font(.system(size: 11, weight: .bold))
            }
            .font(.geist(15, .semibold)).foregroundStyle(TodayLink.ink)
            .frame(minHeight: 44).contentShape(.rect)
        }
        .accessibilityLabel("\(label): \(items.first { $0.key == chosen }?.title ?? "")")
    }
}

/// A list card (.ig): rows on the surface, hairlines between them.
struct PaperworkList<Content: View>: View {
    @ViewBuilder let content: Content

    var body: some View {
        VStack(spacing: 0) { content }
            .card(radius: 22)
            .clipShape(.rect(cornerRadius: 22))
    }
}

/// A header over a part of the page (.t-h / .wd-h).
struct PaperworkHead<Trailing: View>: View {
    let title: String
    var count: String?
    @ViewBuilder let trailing: Trailing

    var body: some View {
        HStack(alignment: .center, spacing: 8) {
            HStack(alignment: .firstTextBaseline, spacing: 6) {
                Text(title).font(.posterGrotesque(23)).tracking(-0.3).foregroundStyle(Palette.text)
                if let count { Text(count).font(.geist(15, .semibold)).foregroundStyle(Palette.textSoft).contentTransition(.numericText()) }
            }
            .accessibilityElement(children: .combine).accessibilityAddTraits(.isHeader)
            Spacer(minLength: 8)
            trailing
        }
        .padding(.horizontal, 4)
    }
}

/// A small round quiet button (.pw-mini).
struct PaperworkMini: View {
    let symbol: String
    let label: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: symbol).font(.system(size: 16, weight: .semibold)).foregroundStyle(Palette.text)
        }
        .buttonStyle(IconTileStyle(fill: Palette.quiet, size: 40))
        .frame(width: 44, height: 44)
        .accessibilityLabel(label)
    }
}

/// A button as iOS 26 draws one: tinted Liquid Glass in a rounded rectangle whose corners follow
/// the card it sits in (Apple's concentric shapes); the glass lights and flexes under the finger. Prominent for the
/// one action a view leads with; quiet for the rest; plain (tinted words) for small side actions.
struct PaperworkButton: View {
    let title: String
    var symbol: String?
    var quiet = false
    var plain = false
    var small = false
    var grow = false
    var working = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 7) {
                if working { ProgressView().tint(quiet || plain ? Palette.text : Palette.onAction).controlSize(.small) } else if let symbol {
                    Image(systemName: symbol).font(.system(size: small ? 14 : 15, weight: .semibold))
                }
                Text(title).font(.geist(small ? 15 : 16, .semibold)).lineLimit(1)
            }
            .foregroundStyle(plain ? AnyShapeStyle(TodayLink.ink) : quiet ? AnyShapeStyle(Palette.text) : AnyShapeStyle(Palette.onAction))
            .padding(.horizontal, plain ? 2 : small ? 14 : 20)
            .frame(maxWidth: grow ? .infinity : nil)
            .frame(height: plain ? 44 : small ? 42 : 52)
            .modifier(Face(on: !plain, color: quiet ? Palette.quiet : Palette.action, radius: small ? 12 : 16))
            .contentShape(.rect(cornerRadius: small ? 12 : 16))
        }
        .buttonStyle(PressStyle())
        .disabled(working)
    }
}

private struct Face: ViewModifier {
    let on: Bool
    let color: Color
    let radius: CGFloat

    func body(content: Content) -> some View {
        if on { content.buttonFace(color, radius: radius) } else { content }
    }
}

/// A plain row in a list card: a round symbol, a title, a line under it, and what's at its end.
struct PaperworkRow: View {
    let symbol: String
    var tint: Color = Palette.text
    var back: Color = Palette.quiet
    let title: String
    var note: String?
    var noteInk: Color = Palette.textSoft
    var value: String?
    var end: String? = "chevron.right"

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: symbol).font(.system(size: 14, weight: .semibold)).foregroundStyle(tint)
                .frame(width: 34, height: 34).background(back, in: .rect(cornerRadius: 10, style: .continuous))
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.geist(15.5, .semibold)).foregroundStyle(Palette.text).multilineTextAlignment(.leading)
                if let note { Text(note).font(.geist(13)).foregroundStyle(noteInk).multilineTextAlignment(.leading) }
            }
            Spacer(minLength: 6)
            if let value { Text(value).font(.geist(14, .semibold)).foregroundStyle(Palette.textSoft).multilineTextAlignment(.trailing) }
            if let end { Image(systemName: end).font(.system(size: 12, weight: .bold)).foregroundStyle(Palette.textSoft) }
        }
        .padding(.horizontal, 14).padding(.vertical, 11)
        .frame(minHeight: 58)
        .contentShape(.rect)
    }
}

struct PaperworkDivider: View {
    var body: some View { Rectangle().fill(Palette.border).frame(height: 1) }
}

/// The line that says what just changed (the canvas's .toast), over the bar.
struct PaperworkNoteBar: View {
    let note: PaperworkModel.Note

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: note.problem ? "exclamationmark.circle.fill" : "checkmark.circle.fill")
                .font(.system(size: 15, weight: .semibold))
                .foregroundStyle(note.problem ? Color(hex: 0xFF9E80) : Color(hex: 0x86E2AA))
            Text(note.text).font(.geist(15)).foregroundStyle(.white).lineLimit(3)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 18).padding(.vertical, 12)
        .frame(minHeight: 52)
        .background(Color(hex: 0x2B2720), in: .rect(cornerRadius: 26))
        .accessibilityElement(children: .combine)
    }
}

// MARK: - Dates, in the page's words

enum PaperworkDay {
    static let berlin: Calendar = {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "Europe/Berlin")!
        calendar.locale = Locale(identifier: "en_GB")
        return calendar
    }()

    static func date(_ text: String?) -> Date? {
        guard let text, text.count >= 10 else { return nil }
        let parts = text.prefix(10).split(separator: "-").compactMap { Int($0) }
        guard parts.count == 3 else { return nil }
        return berlin.date(from: DateComponents(year: parts[0], month: parts[1], day: parts[2], hour: 12))
    }

    static func text(_ date: Date) -> String {
        let parts = berlin.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", parts.year ?? 0, parts.month ?? 0, parts.day ?? 0)
    }

    /// Days from today (0 today, 1 tomorrow, negative past).
    static func days(_ date: Date, from now: Date = .now) -> Int {
        berlin.dateComponents([.day], from: berlin.startOfDay(for: now), to: berlin.startOfDay(for: date)).day ?? 0
    }

    /// "Today", "Tomorrow", or "29 Nov".
    static func near(_ date: Date, now: Date = .now) -> String {
        switch days(date, from: now) {
        case 0: "Today"
        case 1: "Tomorrow"
        default: date.formatted(.dateTime.day().month(.abbreviated).locale(Locale(identifier: "en_GB")))
        }
    }

    static func weekday(_ date: Date) -> String { date.formatted(.dateTime.weekday(.abbreviated).locale(Locale(identifier: "en_GB"))) }
    static func month(_ date: Date) -> String { date.formatted(.dateTime.month(.abbreviated).locale(Locale(identifier: "en_GB"))) }
    static func dayNumber(_ date: Date) -> String { String(berlin.component(.day, from: date)) }

    /// An appointment's own clock time, from its ISO start.
    static func clock(_ startsAt: String?) -> String? {
        guard let startsAt, let at = startsAt.firstIndex(of: "T") else { return nil }
        return String(startsAt[startsAt.index(after: at)...].prefix(5))
    }

    /// Berlin's offset on that day, for an appointment's start ("+01:00").
    static func offset(_ date: Date) -> String {
        let seconds = berlin.timeZone.secondsFromGMT(for: date)
        return String(format: "%@%02d:%02d", seconds < 0 ? "-" : "+", abs(seconds) / 3600, abs(seconds) % 3600 / 60)
    }
}

extension PaperworkPlan {
    /// The dated rows of one task.
    func rows(of process: PaperworkProcess) -> [PaperworkDate] { timeline.filter { $0.occurrenceKey == process.occurrenceKey } }

    /// What a date is, in a few words, with the task it belongs to.
    func words(_ row: PaperworkDate) -> (title: String, note: String) {
        let task = row.occurrenceKey.flatMap(process)?.title
        let title: String = switch row.kind {
        case "document_expiry": row.document == "visa" ? "Your visa expires" : row.document.map { "\(PaperworkWords.option("document", $0)) expires" } ?? "Document expiry"
        case "appointment": task.map { "\($0) appointment" } ?? "Appointment"
        case "submission_recorded": "Submission recorded"
        default: PaperworkWords.kind[row.kind] ?? "Date"
        }
        var parts: [String] = []
        if row.kind == "appointment", let place = row.location?.label { parts.append(place) }
        if row.kind != "appointment", let task { parts.append(task) }
        if row.state == "date_unknown" {
            parts.append("needs \(PaperworkWords.fact[row.neededFact ?? ""] ?? "more information")")
        } else if row.kind == "preparation_target" {
            parts.append("not a legal deadline")
        } else if row.overdue == true {
            parts.append("overdue")
        }
        return (title, parts.joined(separator: " · "))
    }
}
