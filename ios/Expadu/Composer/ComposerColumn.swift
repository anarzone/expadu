import DesignSystem
import SceneKit
import SwiftUI

/// The advertising column (a Litfaßsäule): real geometry under fixed lights, so the shading stays
/// put while it turns. Flick it and it keeps turning on its own weight, slows, and settles on the
/// nearest poster; tap the one in front to ask it. It spins in once when Composer opens.
struct ComposerColumn: View {
    let posters: [ComposerPoster]
    let ask: (ComposerPoster) -> Void
    @State private var front = 0
    @State private var turnTo: Int?
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        ColumnScene(posters: posters, reduceMotion: reduceMotion, front: $front, turnTo: $turnTo) { index in
            if posters.indices.contains(index) { ask(posters[index]) }
        }
        .background(alignment: .center) {
            // Its shadow on the pavement.
            Ellipse().fill(Color(hex: 0x322614, opacity: 0.3)).frame(width: 300, height: 120)
                .blur(radius: 30).offset(x: 30, y: 210)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("An advertising column with today’s posters")
        .accessibilityValue(posters.indices.contains(front) ? "\(posters[front].head). \(posters[front].cta)" : "")
        .accessibilityHint("Swipe up or down to turn it; double-tap to ask the poster in front.")
        .accessibilityAddTraits(.isButton)
        .accessibilityAdjustableAction { direction in
            let count = max(posters.count, 1)
            turnTo = (front + (direction == .increment ? 1 : count - 1)) % count
        }
        .accessibilityAction { if posters.indices.contains(front) { ask(posters[front]) } }
        .sensoryFeedback(.selection, trigger: front)
    }
}

/// The SceneKit stage behind it.
private struct ColumnScene: UIViewRepresentable {
    let posters: [ComposerPoster]
    let reduceMotion: Bool
    @Binding var front: Int
    @Binding var turnTo: Int?
    let tap: (Int) -> Void

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIView(context: Context) -> SCNView {
        let view = SCNView()
        view.backgroundColor = .clear
        view.antialiasingMode = .multisampling4X
        view.scene = context.coordinator.scene
        view.pointOfView = context.coordinator.camera
        // It draws only while it turns: a still column costs nothing (isPlaying redraws every frame).
        view.isPlaying = false
        view.rendersContinuously = false
        context.coordinator.view = view
        view.addGestureRecognizer(UIPanGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.pan(_:))))
        view.addGestureRecognizer(UITapGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.tapped)))
        context.coordinator.paint(posters)
        context.coordinator.spinIn(reduceMotion: reduceMotion)
        return view
    }

    func updateUIView(_ view: SCNView, context: Context) {
        context.coordinator.parent = self
        if context.coordinator.painted != posters.map(\.id) { context.coordinator.paint(posters) }
        if let index = turnTo {
            context.coordinator.settle(on: index)
            DispatchQueue.main.async { turnTo = nil }
        }
    }

    @MainActor
    final class Coordinator: NSObject {
        var parent: ColumnScene
        let scene = SCNScene()
        let camera = SCNNode()
        private let column = SCNNode()
        private let drum: SCNNode
        private var angle: Float = 0
        private var startAngle: Float = 0
        private var count = 5
        var painted: [String] = []
        weak var view: SCNView?
        /// Which turn is the latest: an older one finishing doesn't put the column to sleep.
        private var turning = 0
        /// The drum's radius and the poster band's height, in scene units.
        private let radius: CGFloat = 1
        private let height: CGFloat = 2.6

        init(_ parent: ColumnScene) {
            self.parent = parent
            drum = SCNNode(geometry: SCNCylinder(radius: 1, height: 2.6))
            super.init()
            build()
        }

        private func build() {
            camera.camera = SCNCamera()
            camera.camera?.fieldOfView = 34
            camera.position = SCNVector3(0, 0.15, 7.4)
            scene.rootNode.addChildNode(camera)

            // Light from the upper left, a little fill: the shading stays put while it turns.
            let key = SCNNode()
            key.light = SCNLight()
            key.light?.type = .directional
            key.light?.intensity = 900
            key.eulerAngles = SCNVector3(-0.35, -0.6, 0)
            scene.rootNode.addChildNode(key)
            let fill = SCNNode()
            fill.light = SCNLight()
            fill.light?.type = .ambient
            fill.light?.intensity = 420
            scene.rootNode.addChildNode(fill)

            let band = SCNMaterial()
            band.diffuse.contents = UIColor.white
            band.roughness.contents = 0.82
            band.lightingModel = .physicallyBased
            let ends = SCNMaterial()
            ends.diffuse.contents = UIColor(red: 0.18, green: 0.2, blue: 0.17, alpha: 1)
            ends.lightingModel = .physicallyBased
            drum.geometry?.materials = [band, ends, ends]
            column.addChildNode(drum)

            // The roof: a shallow cap with a rim, in the column's dark green.
            let green = SCNMaterial()
            green.diffuse.contents = UIColor(red: 0.17, green: 0.27, blue: 0.21, alpha: 1)
            green.lightingModel = .physicallyBased
            green.metalness.contents = 0.25
            let rim = SCNNode(geometry: SCNCylinder(radius: radius * 1.08, height: 0.12))
            rim.geometry?.materials = [green]
            rim.position = SCNVector3(0, Float(height / 2) + 0.06, 0)
            let roof = SCNNode(geometry: SCNCone(topRadius: 0.12, bottomRadius: radius * 1.02, height: 0.42))
            roof.geometry?.materials = [green]
            roof.position = SCNVector3(0, Float(height / 2) + 0.33, 0)
            let finial = SCNNode(geometry: SCNSphere(radius: 0.09))
            finial.geometry?.materials = [green]
            finial.position = SCNVector3(0, Float(height / 2) + 0.58, 0)
            // The plinth.
            let plinth = SCNNode(geometry: SCNCylinder(radius: radius * 1.1, height: 0.34))
            plinth.geometry?.materials = [green]
            plinth.position = SCNVector3(0, -Float(height / 2) - 0.17, 0)
            [rim, roof, finial, plinth].forEach(column.addChildNode)
            column.position = SCNVector3(0, -0.1, 0)
            scene.rootNode.addChildNode(column)
        }

        /// The posters as one band around the drum, each a fifth of the way round.
        func paint(_ posters: [ComposerPoster]) {
            painted = posters.map(\.id)
            count = max(posters.count, 1)
            let face = CGSize(width: 300, height: 300 * height / (2 * .pi * radius / CGFloat(count)))
            let band = HStack(spacing: 0) {
                ForEach(posters) { PosterFace(poster: $0).frame(width: face.width, height: face.height) }
            }
            let renderer = ImageRenderer(content: band)
            renderer.scale = 2
            guard let image = renderer.uiImage else { return }
            let material = drum.geometry?.materials.first
            material?.diffuse.contents = image
            // SceneKit wraps a cylinder's texture from the back; a half-turn puts the first poster in front.
            material?.diffuse.contentsTransform = SCNMatrix4MakeTranslation(0.5 + 0.5 / Float(count), 0, 0)
            material?.diffuse.wrapS = .repeat
        }

        /// Once, from a few turns back, to the first poster.
        func spinIn(reduceMotion: Bool) {
            guard !reduceMotion else { return }
            angle = -Float.pi * 2.2
            drum.eulerAngles.y = angle
            settle(on: 0, duration: 1.6)
        }

        func settle(on index: Int, duration: TimeInterval? = nil) {
            let step = 2 * Float.pi / Float(count)
            // The nearest whole turn that lands this poster in front.
            var target = -Float(index) * step
            while target - angle > .pi { target -= 2 * .pi }
            while angle - target > .pi { target += 2 * .pi }
            let time = duration ?? TimeInterval(min(0.9, max(0.3, abs(target - angle) * 0.35)))
            angle = target
            drum.removeAllActions()
            let turn = SCNAction.rotateTo(x: 0, y: CGFloat(target), z: 0, duration: time, usesShortestUnitArc: false)
            turn.timingMode = .easeOut
            turning += 1
            let this = turning
            view?.isPlaying = true
            drum.runAction(turn) { [weak self] in
                DispatchQueue.main.async {
                    guard let self, self.turning == this else { return }
                    self.view?.isPlaying = false
                }
            }
            let front = ((index % count) + count) % count
            if parent.front != front { parent.front = front }
        }

        @objc func pan(_ gesture: UIPanGestureRecognizer) {
            guard let view = gesture.view else { return }
            // A finger's sweep across the column turns it about as far as the surface moved.
            let perPoint = Float(2 / max(view.bounds.width, 1)) * 1.7
            switch gesture.state {
            case .began:
                turning += 1
                self.view?.isPlaying = true
                drum.removeAllActions()
                angle = drum.presentation.eulerAngles.y
                startAngle = angle
            case .changed:
                angle = startAngle + Float(gesture.translation(in: view).x) * perPoint
                drum.eulerAngles.y = angle
            case .ended, .cancelled:
                // It keeps turning on its own weight, then settles on the nearest poster.
                let thrown = angle + Float(gesture.velocity(in: view).x) * perPoint * 0.28
                let step = 2 * Float.pi / Float(count)
                let index = Int((-thrown / step).rounded())
                settle(on: index)
            default: break
            }
        }

        @objc func tapped() { parent.tap(parent.front) }
    }
}

/// One poster as printed: its colour, the label and mark, the tape, the headline, the line under
/// it and what tapping does.
private struct PosterFace: View {
    let poster: ComposerPoster

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 8) {
                Image(systemName: poster.symbol).font(.system(size: 22, weight: .bold))
                Text(poster.label.uppercased()).font(.geist(20, .bold)).tracking(1)
                Spacer()
            }
            Spacer(minLength: 0)
            Image(systemName: poster.symbol).font(.system(size: 96, weight: .bold)).opacity(0.9)
            Text(poster.tape).font(.posterMarker(19)).foregroundStyle(Color(hex: 0x211D15))
                .padding(.horizontal, 10).padding(.vertical, 4)
                .background(Color(hex: 0xFFE363)).rotationEffect(.degrees(-2))
                .lineLimit(1).minimumScaleFactor(0.6)
            Text(poster.head).font(.posterGrotesque(40)).tracking(-0.5).lineLimit(4).minimumScaleFactor(0.6)
            if !poster.sub.isEmpty {
                Text(poster.sub).font(.geist(20, .medium)).opacity(0.85).lineLimit(2)
            }
            Spacer(minLength: 0)
            Text(poster.cta).font(.geist(21, .bold))
                .padding(.horizontal, 18).padding(.vertical, 12)
                .background(poster.ink.opacity(0.14), in: .rect(cornerRadius: 12, style: .continuous))
        }
        .foregroundStyle(poster.ink)
        .padding(26)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .background(poster.colour)
        .overlay(alignment: .trailing) { Rectangle().fill(.black.opacity(0.18)).frame(width: 3) }
    }
}
