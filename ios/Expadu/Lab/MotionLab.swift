#if LAB
import DesignSystem
import SwiftUI
import UIKit

extension Notification.Name {
    static let deviceDidShake = Notification.Name("deviceDidShake")
}

extension UIWindow {
    override open func motionEnded(_ motion: UIEvent.EventSubtype, with event: UIEvent?) {
        if motion == .motionShake {
            NotificationCenter.default.post(name: .deviceDidShake, object: nil)
        }
        super.motionEnded(motion, with: event)
    }
}

/// A shake opens this over the real screen: play a token's variants on the real state change,
/// slow them down, and pick one. Picks land in a JSON file the agent reads.
struct MotionLab: View {
    @Binding var isOpen: Bool
    @State private var token = MotionToken.navSelect
    @State private var picked = false
    private var store: MotionStore { .shared }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Menu {
                    ForEach(MotionToken.allCases) { item in
                        Button(item.rawValue) {
                            token = item
                            picked = false
                        }
                    }
                } label: {
                    Label(token.rawValue, systemImage: "chevron.up.chevron.down").font(.geist(14, .semibold))
                }
                Spacer()
                Button("Close", systemImage: "xmark") { isOpen = false }.labelStyle(.iconOnly)
            }
            Picker("Variant", selection: Binding(
                get: { store.variant(of: token).id },
                set: { id in
                    store.choose(id, for: token)
                    store.replay(token)
                    picked = false
                }
            )) {
                ForEach(token.variants) { Text($0.id).tag($0.id) }
            }
            .pickerStyle(.segmented)
            HStack(spacing: 8) {
                Button("Replay", systemImage: "arrow.counterclockwise") { store.replay(token) }
                Toggle("Slow", isOn: Binding(get: { store.slow }, set: { store.slow = $0 }))
                    .toggleStyle(.button)
                Spacer()
                Button(picked ? "Picked" : "Pick this") {
                    write()
                    picked = true
                }
                .buttonStyle(.borderedProminent)
            }
            .font(.geist(14, .medium))
        }
        .padding(14)
        .glassEffect(in: .rect(cornerRadius: 20))
        .padding(.horizontal, 12)
    }

    private func write() {
        let picks = Dictionary(uniqueKeysWithValues: MotionToken.allCases.map { ($0.rawValue, store.variant(of: $0).id) })
        guard let data = try? JSONEncoder().encode(picks),
              let folder = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask).first
        else { return }
        try? data.write(to: folder.appending(path: "motion-picks.json"))
    }
}
#endif
