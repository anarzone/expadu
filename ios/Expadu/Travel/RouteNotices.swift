import Core
import DesignSystem
import SwiftUI

// KVB's notices on Plan a trip's routes (the canvas "Expadu iPhone: KVB notices on your routes", owner
// 19 Nov 2026, "build the canvas"), by the two tiers agreed on 22 Oct 2026: an everyday one small and in
// place on the route, KVB's words on tap and the German one tap further; a major one as the one headline
// above the routes. A way whose late tram misses its change says so where it is.

/// The one headline above the routes: a major problem on a line they'd ride.
struct NoticeHeadline: View {
    let notice: TripPlan.Disruption
    /// Whether some way goes without that line: only then are they said to come first.
    var othersFirst = false
    @State private var german = false

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            if let line = notice.lines?.first {
                Text(line).font(.geist(15, .bold)).foregroundStyle(.white)
                    .frame(minWidth: 34, minHeight: 34).padding(.horizontal, 2)
                    .background(Palette.conflictText, in: .rect(cornerRadius: 10))
            }
            VStack(alignment: .leading, spacing: 4) {
                Text(notice.headline).font(.geist(16, .bold)).foregroundStyle(Palette.conflictText)
                    .fixedSize(horizontal: false, vertical: true)
                Text(notice.plain + (othersFirst ? " Ways without the \(notice.lines?.first ?? "line") come first." : ""))
                    .font(.geist(13.5)).fixedSize(horizontal: false, vertical: true)
                NoticeSource(notice: notice, german: $german)
            }
            Spacer(minLength: 0)
        }
        .padding(14)
        .background(Palette.conflictBackground, in: .rect(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(Palette.conflictBorder))
        .foregroundStyle(Palette.text)
        .accessibilityElement(children: .combine)
    }
}

/// Under a route: why it's late, affected or misses its change, in one line; tapped, KVB's words.
struct RouteNoticeLine: View {
    let notice: TripPlan.Disruption?
    let missed: TripPlan.Journey.Missed?
    @Binding var open: Bool
    @State private var german = false

    private var bad: Bool { missed != nil || notice?.major == true }
    private var reason: String {
        if let missed {
            return "The \(missed.after) is \(missed.lateMin > 0 ? "\(missed.lateMin) min " : "")late, so it gets to \(missed.stop.isEmpty ? "the change" : missed.stop) after the \(missed.line) leaves"
        }
        return notice?.short ?? ""
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Button { withAnimation(.smooth(duration: 0.25)) { open.toggle() } } label: {
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Image(systemName: "exclamationmark.triangle").font(.system(size: 12, weight: .semibold))
                    Text(reason).fixedSize(horizontal: false, vertical: true)
                    Spacer(minLength: 4)
                    Image(systemName: open ? "chevron.up" : "chevron.down").font(.system(size: 11, weight: .semibold))
                        .foregroundStyle(Palette.textSoft)
                }
                .font(.geist(13, .medium))
                .foregroundStyle(bad ? Palette.conflictText : Palette.warnText)
                .frame(minHeight: 30).contentShape(.rect)
            }
            .buttonStyle(PressStyle())
            .accessibilityHint(open ? "Hides what KVB says" : "Shows what KVB says")
            if open {
                VStack(alignment: .leading, spacing: 6) {
                    if let missed {
                        Text("The way after it is shown first. This one can’t be started: you’d wait at \(missed.stop.isEmpty ? "the change" : missed.stop) for the next \(missed.line).")
                            .font(.geist(13)).fixedSize(horizontal: false, vertical: true)
                    }
                    if let notice {
                        Text(notice.plain).font(.geist(13)).fixedSize(horizontal: false, vertical: true)
                        NoticeSource(notice: notice, german: $german)
                    }
                }
                .padding(.horizontal, 12).padding(.vertical, 10)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Palette.noticeBackground, in: .rect(cornerRadius: 12))
                .foregroundStyle(Palette.noticeText)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
    }
}

/// On a route's step: the notice on the line it's about, KVB's words and the German one tap away.
struct NoticeCard: View {
    let notice: TripPlan.Disruption
    @State private var german = false

    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            Image(systemName: "exclamationmark.triangle").font(.system(size: 14, weight: .semibold))
                .foregroundStyle(notice.major ? Palette.conflictText : Palette.warnText)
            VStack(alignment: .leading, spacing: 4) {
                Text(notice.headline).font(.geist(14, .bold))
                    .foregroundStyle(notice.major ? Palette.conflictText : Palette.warnText)
                    .fixedSize(horizontal: false, vertical: true)
                if notice.plain != notice.headline {
                    Text(notice.plain).font(.geist(13)).fixedSize(horizontal: false, vertical: true)
                }
                NoticeSource(notice: notice, german: $german)
            }
            Spacer(minLength: 0)
        }
        .padding(12)
        .background(notice.major ? Palette.conflictBackground : Palette.noticeBackground, in: .rect(cornerRadius: 14))
        .foregroundStyle(Palette.noticeText)
    }
}

/// "From KVB at 15:12 · Show the German", and the German when asked.
private struct NoticeSource: View {
    let notice: TripPlan.Disruption
    @Binding var german: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 4) {
                Text(notice.sinceTime.map { "From KVB at \($0)" } ?? "From KVB")
                if notice.details != nil {
                    Text("·")
                    Button(german ? "Hide the German" : "Show the German") {
                        withAnimation(.smooth(duration: 0.25)) { german.toggle() }
                    }
                    .underline()
                    .buttonStyle(.plain)
                    .frame(minHeight: 30)
                }
            }
            .font(.geist(12)).foregroundStyle(Palette.textSoft)
            if german, let details = notice.details {
                Text(details).font(.geist(12.5)).foregroundStyle(Palette.textSoft)
                    .fixedSize(horizontal: false, vertical: true)
                    .transition(.opacity)
            }
        }
    }
}
