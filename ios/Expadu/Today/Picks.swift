import API
import Core
import DesignSystem
import SwiftUI

// MARK: - What you'd go to

/// "What would you go to?" on Today (the canvas "What you'd go to, from day one", owner's A on 17 Nov):
/// the kinds of event as posters to pick, tonight's ideas moving under them as they're picked; three
/// or more and Done folds it into one line that opens it again. Only with personalisation on.
struct PicksPlace: View {
    let picks: TodayPage.Picks
    @Environment(TodayModel.self) private var model

    var body: some View {
        Group {
            if model.picksOpen ?? picks.ask {
                PickWall(picks: picks)
                    .transition(.opacity.combined(with: .scale(scale: 0.97, anchor: .top)))
            } else if !model.picked.isEmpty {
                PicksLine(picks: picks)
                    .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .padding(.top, 12)
    }
}

/// How many Today asks for before Done (EventPicks::ASK_AT_LEAST).
private let askAtLeast = 3

struct PickWall: View {
    let picks: TodayPage.Picks
    @Environment(Session.self) private var session
    @Environment(TodayModel.self) private var model

    var body: some View {
        let picked = model.picked
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 8) {
                Text("What would you go to?").font(.posterGrotesque(19)).foregroundStyle(Palette.text)
                    .accessibilityAddTraits(.isHeader)
                Spacer(minLength: 4)
                if picked.count >= askAtLeast {
                    PaperworkButton(title: "Done", small: true) { model.closePicks(session.api) }
                        .transition(.opacity.combined(with: .scale(scale: 0.8)))
                } else {
                    Button("Not now") { model.closePicks(session.api) }
                        .font(.geist(14.5, .semibold)).foregroundStyle(Palette.textSoft)
                        .frame(minHeight: 44)
                        .buttonStyle(PressStyle())
                }
            }
            .animation(.snappy, value: picked.count >= askAtLeast)
            Text("Pick 3 or more · \(Text(picked.count >= askAtLeast ? "\(picked.count) picked" : "\(picked.count) of 3 picked").fontWeight(.semibold).foregroundStyle(Palette.text))")
                .font(.geist(13.5)).foregroundStyle(Palette.textSoft)
                .contentTransition(.numericText())
                .padding(.bottom, 12)
            LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10), count: 4), spacing: 12) {
                ForEach(Array(picks.kinds.enumerated()), id: \.element.id) { index, kind in
                    PickTile(label: kind.label, on: picked.contains(kind.key), tilt: PickTile.tilts[index % PickTile.tilts.count],
                             count: kind.today == 0 ? "none today" : "\(kind.today) on today") {
                        model.togglePick(session.api, kind.key)
                    }
                }
            }
        }
        .padding(.horizontal, 14).padding(.top, 10).padding(.bottom, 14)
        .card(radius: 22)
    }
}

/// Folded: what Today is sorted by, in one line; Change opens the posters again.
struct PicksLine: View {
    let picks: TodayPage.Picks
    @Environment(TodayModel.self) private var model

    var body: some View {
        let picked = model.picked
        let labels = picked.compactMap { key in picks.kinds.first { $0.key == key }?.label }
        Button { model.reopenPicks() } label: {
            HStack(spacing: 10) {
                HStack(spacing: 4) {
                    ForEach(Array(labels.prefix(4).enumerated()), id: \.offset) { index, label in
                        Image(systemName: Poster.symbol(label) ?? "star.fill").font(.system(size: 11, weight: .bold))
                            .foregroundStyle(PickTile.yellow)
                            .frame(width: 22, height: 28)
                            .background(PickTile.indigo, in: .rect(cornerRadius: 4))
                            .rotationEffect(.degrees([-4, 3, -2, 4][index]))
                    }
                }
                .accessibilityHidden(true)
                Text(picks.learned ? "Sorted by what you picked and what you open"
                     : "Sorted by what you picked: \(ListFormatter.localizedString(byJoining: labels))")
                    .font(.geist(13.5)).foregroundStyle(Palette.text).multilineTextAlignment(.leading)
                    .fixedSize(horizontal: false, vertical: true)
                Spacer(minLength: 4)
                Text("Change").font(.geist(13.5, .semibold)).foregroundStyle(TodayLink.ink)
            }
            .padding(.horizontal, 12).padding(.vertical, 10)
            .background(Palette.quiet, in: .rect(cornerRadius: 14))
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .accessibilityHint("Change what you picked")
    }
}

/// One kind as a poster, like the ideas' own: tilted and yellow; picked, it straightens, lifts and
/// turns to the night colours with a tick.
struct PickTile: View {
    let label: String
    let on: Bool
    let tilt: Double
    var count: String?
    let action: () -> Void
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    static let yellow = Color(hex: 0xFFE363)
    static let indigo = Color(hex: 0x3D2F9E)
    static let tilts: [Double] = [-3, 2, -2, 3, 2, -3, 3, -2]

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 5) {
                VStack(alignment: .leading, spacing: 0) {
                    Image(systemName: Poster.symbol(label) ?? "star.fill").font(.system(size: 18, weight: .semibold))
                    Spacer(minLength: 2)
                    // "Festivals" over "& markets", never a lone "&".
                    Text(label.replacingOccurrences(of: " & ", with: " &\u{00A0}"))
                        .font(.posterGrotesque(label.count > 12 ? 11.5 : 13))
                        .lineLimit(3).minimumScaleFactor(0.8).multilineTextAlignment(.leading)
                }
                .foregroundStyle(on ? Self.yellow : Self.indigo)
                .padding(7)
                .frame(maxWidth: .infinity, minHeight: 70, maxHeight: 70, alignment: .leading)
                .background(on ? Self.indigo : Self.yellow, in: .rect(cornerRadius: 9))
                .shadow(color: on ? Self.indigo.opacity(0.32) : Color(hex: 0x211D15, opacity: 0.18), radius: on ? 7 : 3, y: on ? 6 : 2)
                .overlay(alignment: .topTrailing) {
                    Image(systemName: "checkmark").font(.system(size: 11, weight: .heavy)).foregroundStyle(Self.yellow)
                        .frame(width: 22, height: 22).background(Palette.text, in: .circle)
                        .offset(x: 7, y: -7)
                        .scaleEffect(on ? 1 : 0.3).opacity(on ? 1 : 0)
                }
                .rotationEffect(.degrees(on || reduceMotion ? 0 : tilt))
                .offset(y: on ? -3 : 0)
                .animation(reduceMotion ? .easeInOut(duration: 0.2) : .spring(response: 0.38, dampingFraction: 0.6), value: on)
                if let count {
                    Text(count).font(.geist(11, .medium)).foregroundStyle(count.hasPrefix("none") ? Palette.textSoft.opacity(0.7) : Palette.textSoft)
                        .padding(.leading, 2)
                }
            }
            .contentShape(.rect)
        }
        .buttonStyle(PressStyle())
        .sensoryFeedback(.selection, trigger: on)
        .accessibilityLabel(count.map { "\(label), \($0)" } ?? label)
        .accessibilityAddTraits(on ? [.isSelected] : [])
    }
}
