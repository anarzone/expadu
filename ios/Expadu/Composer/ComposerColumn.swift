import DesignSystem
import SceneKit
import SwiftUI
import UIKit

/// The advertising column (a Litfaßsäule), as the canvas builds it: real geometry under fixed
/// lights, so the shading stays put while it turns. A patinated copper dome with ribs, gold rings,
/// a scalloped frieze, the poster drum and a panelled plinth. Flick it and it keeps turning on its
/// own weight, slows, and springs onto the nearest poster; tap the poster in front to ask it, or a
/// poster at the side to turn to it. It spins in once when Composer opens.
struct ComposerColumn: View {
    /// Prints the column's surfaces in the background ahead of opening (Shell calls it with Today).
    static func prepare(_ posters: [ComposerPoster]) {
        Task.detached(priority: .utility) {
            _ = (ColumnArt.dome, ColumnArt.frieze, ColumnArt.base)
            _ = ColumnArt.posters(posters)
        }
    }

    let posters: [ComposerPoster]
    let ask: (ComposerPoster) -> Void
    @State private var front = 0
    @State private var turnTo: Int?
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        ColumnScene(posters: posters, reduceMotion: reduceMotion, front: $front, turnTo: $turnTo) { index in
            if posters.indices.contains(index) { ask(posters[index]) }
        }
        .background {
            // Its shadow on the wall (.col-shadow).
            GeometryReader { proxy in
                let k = proxy.size.width / 402
                RoundedRectangle(cornerSize: CGSize(width: 170 * k, height: 60 * k), style: .continuous)
                    .fill(Color(hex: 0x322614, opacity: 0.3))
                    .frame(width: 388 * k, height: 470 * k)
                    .blur(radius: 30 * k)
                    .offset(x: 60 * k, y: 70 * k)
            }
            .allowsHitTesting(false)
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
        // It draws only when it moves: a still column costs nothing.
        view.isPlaying = false
        view.rendersContinuously = false
        context.coordinator.view = view
        view.addGestureRecognizer(UIPanGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.pan(_:))))
        view.addGestureRecognizer(UITapGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.tapped(_:))))
        // It spins in once its posters are printed, as the canvas's waits for its fonts.
        let coordinator = context.coordinator, still = reduceMotion
        coordinator.paint(posters) { coordinator.spinIn(reduceMotion: still) }
        return view
    }

    func updateUIView(_ view: SCNView, context: Context) {
        context.coordinator.parent = self
        if context.coordinator.painted != posters { context.coordinator.paint(posters) }
        if let index = turnTo {
            context.coordinator.turn(to: index)
            DispatchQueue.main.async { turnTo = nil }
        }
    }

    static func dismantleUIView(_ view: SCNView, coordinator: Coordinator) { coordinator.stop() }

    @MainActor
    final class Coordinator: NSObject {
        var parent: ColumnScene
        let scene = SCNScene()
        let camera = SCNNode()
        weak var view: SCNView?
        var painted: [ComposerPoster] = []
        private let column = SCNNode()
        private let posterMaterial = SCNMaterial()
        /// The canvas's own numbers: the drum's radius and the posters' height, the camera 1000 away.
        private let radius: CGFloat = 190
        private let posterHeight: CGFloat = 400
        private var count = 5
        private var step: Double { 360 / Double(count) }

        /// Degrees; 0 has the first poster in front, each step to the left the next one.
        private var rot: Double = 0
        private var sway: Double = 0
        private var startRot: Double = 0
        private var link: CADisplayLink?
        private var last: CFTimeInterval = 0
        private enum Motion {
            case still
            case spin(from: Double, to: Double, began: CFTimeInterval, duration: Double, power: Double)
            /// Degrees a millisecond.
            case coast(velocity: Double)
        }
        private var motion = Motion.still

        init(_ parent: ColumnScene) {
            self.parent = parent
            super.init()
            build()
        }

        // MARK: The scene

        private func build() {
            rot = -400
            camera.camera = SCNCamera()
            camera.camera?.fieldOfView = 38.1
            camera.camera?.projectionDirection = .vertical
            camera.camera?.zNear = 10
            camera.camera?.zFar = 4000
            scene.rootNode.addChildNode(camera)

            // Sky and ground light (the canvas's hemisphere), the sun from the upper left, a cool rim.
            light(.ambient, UIColor(red: 0.73, green: 0.69, blue: 0.62, alpha: 1), 620, from: nil)
            light(.directional, UIColor(red: 1, green: 0.98, blue: 0.94, alpha: 1), 150, from: SCNVector3(0, 1000, 0))
            light(.directional, UIColor(red: 1, green: 0.945, blue: 0.863, alpha: 1), 620, from: SCNVector3(-520, 360, 760))
            light(.directional, UIColor(red: 0.776, green: 0.847, blue: 1, alpha: 1), 200, from: SCNVector3(640, 40, 160))

            let R = radius, top = posterHeight / 2, bot = -posterHeight / 2
            let gold = Self.material(UIColor(red: 0.847, green: 0.671, blue: 0.29, alpha: 1), roughness: 0.38, metalness: 0.35)

            // The dome: patinated copper with raised ribs, and its finial.
            var dome: [(CGFloat, CGFloat)] = [(0, top + 104)]
            for i in stride(from: 28, through: 0, by: -1) {
                let a = CGFloat(i) / 28 * .pi / 2
                dome.append(((R + 18) * pow(cos(a), 0.82), top + 26 + 78 * pow(sin(a), 1.12)))
            }
            lathe(dome, Self.material(ColumnArt.dome, roughness: 0.5, metalness: 0.22))
            lathe([(0, top + 142), (1.2, top + 136), (2.6, top + 124), (4, top + 116), (7.5, top + 112), (8, top + 108), (5, top + 104), (13, top + 100), (15, top + 97), (0, top + 97)], gold)

            // Eave, scalloped frieze, top ring.
            lathe([(R + 10, top + 33), (R + 17, top + 33), (R + 21, top + 31), (R + 21, top + 24)], gold)
            lathe([(R + 8, top + 26), (R + 8, top + 2)], Self.material(ColumnArt.frieze, roughness: 0.55, metalness: 0.1))
            lathe([(R + 4, top + 5), (R + 8.5, top + 4), (R + 8.5, top - 1), (R + 4, top - 3)], gold)

            // The poster drum.
            posterMaterial.lightingModel = .physicallyBased
            posterMaterial.diffuse.contents = UIColor.white
            posterMaterial.roughness.contents = 0.82
            posterMaterial.metalness.contents = 0
            posterMaterial.isDoubleSided = true
            lathe([(R, top), (R, bot)], posterMaterial, segments: 180)

            // The plinth: panels and rivets that turn with the column.
            lathe([(R + 4, bot + 3), (R + 8.5, bot + 1), (R + 8.5, bot - 4), (R + 4, bot - 6)], gold)
            lathe([(R + 13, bot - 8), (R + 13, bot - 52)], Self.material(ColumnArt.base, roughness: 0.5, metalness: 0.15))
            lathe([(R + 6, bot - 5), (R + 13, bot - 8)], Self.material(UIColor(red: 0.114, green: 0.271, blue: 0.216, alpha: 1), roughness: 0.6, metalness: 0.1))
            lathe([(R + 13, bot - 52), (R + 22, bot - 58), (R + 22, bot - 66)], Self.material(UIColor(red: 0.071, green: 0.161, blue: 0.122, alpha: 1), roughness: 0.7, metalness: 0.1))

            scene.rootNode.addChildNode(column)
            apply()
        }

        private func light(_ type: SCNLight.LightType, _ colour: UIColor, _ intensity: CGFloat, from: SCNVector3?) {
            let node = SCNNode()
            node.light = SCNLight()
            node.light?.type = type
            node.light?.color = colour
            node.light?.intensity = intensity
            if let from {
                node.position = from
                node.look(at: SCNVector3(0, 0, 0))
            }
            scene.rootNode.addChildNode(node)
        }

        private static func material(_ contents: Any, roughness: CGFloat, metalness: CGFloat) -> SCNMaterial {
            let material = SCNMaterial()
            material.lightingModel = .physicallyBased
            material.diffuse.contents = contents
            material.diffuse.wrapS = .repeat
            material.roughness.contents = roughness
            material.metalness.contents = metalness
            material.isDoubleSided = true
            return material
        }

        /// A profile (radius, height) turned round the axis; the first point is the picture's top.
        private func lathe(_ profile: [(CGFloat, CGFloat)], _ material: SCNMaterial, segments: Int = 128) {
            var positions: [SCNVector3] = [], normals: [SCNVector3] = [], uvs: [CGPoint] = []
            var indices: [Int32] = []
            let rows = profile.count
            for i in 0..<rows {
                let before = profile[max(i - 1, 0)], after = profile[min(i + 1, rows - 1)]
                let tr = after.0 - before.0, ty = after.1 - before.1
                let length = max(hypot(tr, ty), 0.0001)
                let nr = -ty / length, ny = tr / length
                for j in 0...segments {
                    let theta = 2 * CGFloat.pi * CGFloat(j) / CGFloat(segments)
                    positions.append(SCNVector3(Float(profile[i].0 * sin(theta)), Float(profile[i].1), Float(profile[i].0 * cos(theta))))
                    normals.append(SCNVector3(Float(nr * sin(theta)), Float(ny), Float(nr * cos(theta))))
                    uvs.append(CGPoint(x: CGFloat(j) / CGFloat(segments), y: CGFloat(i) / CGFloat(rows - 1)))
                }
            }
            let width = Int32(segments + 1)
            for i in 0..<Int32(rows - 1) {
                for j in 0..<Int32(segments) {
                    let a = i * width + j, b = (i + 1) * width + j, c = a + 1, d = b + 1
                    indices += [a, b, c, b, d, c]
                }
            }
            let geometry = SCNGeometry(sources: [SCNGeometrySource(vertices: positions), SCNGeometrySource(normals: normals), SCNGeometrySource(textureCoordinates: uvs)],
                                       elements: [SCNGeometryElement(indices: indices, primitiveType: .triangles)])
            geometry.materials = [material]
            column.addChildNode(SCNNode(geometry: geometry))
        }

        /// The posters as one band round the drum: printed ahead of time (ComposerColumn.prepare),
        /// or off the main thread now, so opening Composer never waits on it.
        func paint(_ posters: [ComposerPoster], then: (@MainActor () -> Void)? = nil) {
            painted = posters
            count = max(posters.count, 1)
            rot = (rot / step).rounded() * step
            if let image = ColumnArt.cached(posters) {
                show(image)
                then?()
                return
            }
            Task { [weak self] in
                let image = await Task.detached(priority: .userInitiated) { ColumnArt.posters(posters) }.value
                guard let self, self.painted == posters else { return }
                self.show(image)
                then?()
            }
        }

        private func show(_ image: UIImage) {
            posterMaterial.diffuse.contents = image
            posterMaterial.diffuse.wrapS = .repeat
            posterMaterial.diffuse.mipFilter = .linear
            posterMaterial.diffuse.maxAnisotropy = 16
            apply()
        }

        // MARK: Turning

        private func apply() {
            column.eulerAngles.y = Float((rot - step / 2) * .pi / 180)
            camera.position = SCNVector3(Float(sway), -70, 1000)
            camera.look(at: SCNVector3(0, -2, 0))
            view?.setNeedsDisplay()
            let now = ((Int((-rot / step).rounded()) % count) + count) % count
            if parent.front != now { parent.front = now }
        }

        private var frontNow: Int { ((Int((-rot / step).rounded()) % count) + count) % count }

        private func run(_ next: Motion) {
            motion = next
            last = CACurrentMediaTime()
            guard link == nil else { return }
            let link = CADisplayLink(target: self, selector: #selector(tick(_:)))
            link.add(to: .main, forMode: .common)
            self.link = link
            view?.isPlaying = true
        }

        func stop() {
            link?.invalidate()
            link = nil
            motion = .still
            view?.isPlaying = false
        }

        @objc private func tick(_ link: CADisplayLink) {
            let now = CACurrentMediaTime()
            switch motion {
            case .still:
                stop()
            case let .spin(from, to, began, duration, power):
                let t = min(1, (now - began) / duration)
                rot = from + (to - from) * (1 - pow(1 - t, power))
                sway = 0
                apply()
                if t >= 1 { stop() }
            case var .coast(v):
                // It keeps turning on its own weight, slows, then springs onto the nearest poster.
                let dt = min(32, (now - last) * 1000)
                last = now
                if abs(v) > 0.07 {
                    v *= pow(0.9972, dt)
                    rot += v * dt
                } else {
                    let x = rot + (-rot / step).rounded() * step, w = 0.0105
                    v += (-w * w * x - 2 * 0.72 * w * v) * dt
                    rot += v * dt
                    if abs(x) < 0.03, abs(v) < 0.002 { rot -= x; v = 0 }
                }
                sway = max(-16, min(16, -v * 26))
                apply()
                if v == 0 { stop() } else { motion = .coast(velocity: v) }
            }
        }

        /// Once, from more than a turn back, to the first poster.
        func spinIn(reduceMotion: Bool) {
            guard !reduceMotion else { rot = 0; apply(); return }
            rot = -400
            apply()
            run(.spin(from: -400, to: 0, began: CACurrentMediaTime(), duration: 1.6, power: 3.2))
        }

        func turn(to index: Int) {
            var delta = index - frontNow
            if delta > count / 2 { delta -= count }
            if delta < -count / 2 { delta += count }
            let from = rot
            run(.spin(from: from, to: (rot / step).rounded() * step - Double(delta) * step, began: CACurrentMediaTime(), duration: 0.7, power: 3))
        }

        @objc func pan(_ gesture: UIPanGestureRecognizer) {
            guard let view = gesture.view else { return }
            // A finger's sweep across the column turns it as far as its surface moved.
            let scale = max(view.bounds.width, 1) / 402
            let perPoint = 180 / (Double.pi * Double(radius)) / Double(scale)
            switch gesture.state {
            case .began:
                stop()
                startRot = rot
            case .changed:
                rot = startRot + Double(gesture.translation(in: view).x) * perPoint
                sway = max(-16, min(16, -Double(gesture.velocity(in: view).x) * perPoint / 1000 * 26))
                apply()
            case .ended, .cancelled:
                let v = Double(gesture.velocity(in: view).x) * perPoint / 1000
                run(.coast(velocity: max(-0.9, min(0.9, v))))
            default: break
            }
        }

        /// The poster in front asks; one at the side turns to the front.
        @objc func tapped(_ gesture: UITapGestureRecognizer) {
            guard let view = gesture.view else { return }
            let dx = gesture.location(in: view).x / (max(view.bounds.width, 1) / 402) - 201
            if abs(dx) > 106 { turn(to: (frontNow + (dx > 0 ? 1 : count - 1)) % count) } else { parent.tap(frontNow) }
        }
    }
}

/// What is printed on the column, made off the main thread and kept: the dome, frieze and plinth
/// once for the app's life, the posters per set. ComposerColumn.prepare prints them while Today is
/// open, so Composer opens without stopping to draw (it cost ~110 ms of dropped frames).
nonisolated enum ColumnArt {
    static let dome = domeTexture()
    static let frieze = friezeTexture()
    static let base = baseTexture()

    private static let lock = NSLock()
    nonisolated(unsafe) private static var printed: (key: [String], image: UIImage)?

    static func cached(_ posters: [ComposerPoster]) -> UIImage? {
        lock.withLock { printed?.key == posters.map(\.printKey) ? printed?.image : nil }
    }

    /// The five posters as one band, 3× for a sharp print.
    static func posters(_ posters: [ComposerPoster]) -> UIImage {
        if let image = cached(posters) { return image }
        let count = max(posters.count, 1)
        let scale: CGFloat = 3, width = 2 * .pi * 190 / CGFloat(count), height: CGFloat = 400
        let image = picture((width * scale).rounded() * CGFloat(count), height * scale) { g, _, _ in
            for (index, poster) in posters.enumerated() {
                g.saveGState()
                g.translateBy(x: CGFloat(index) * (width * scale).rounded(), y: 0)
                g.scaleBy(x: scale, y: scale)
                g.clip(to: CGRect(x: 0, y: 0, width: width, height: height))
                PosterPrint.draw(poster, in: g, width: width, height: height)
                g.restoreGState()
            }
        }
        lock.withLock { printed = (posters.map(\.printKey), image) }
        return image
    }

    static func picture(_ width: CGFloat, _ height: CGFloat, _ draw: (CGContext, CGFloat, CGFloat) -> Void) -> UIImage {
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        format.opaque = true
        return UIGraphicsImageRenderer(size: CGSize(width: width, height: height), format: format).image { draw($0.cgContext, width, height) }
    }

    static func gradient(_ g: CGContext, _ from: UIColor, _ to: UIColor, _ rect: CGRect, horizontal: Bool = false) {
        guard let gradient = CGGradient(colorsSpace: CGColorSpaceCreateDeviceRGB(), colors: [from.cgColor, to.cgColor] as CFArray, locations: [0, 1]) else { return }
        g.saveGState()
        g.clip(to: rect)
        g.drawLinearGradient(gradient, start: rect.origin, end: horizontal ? CGPoint(x: rect.maxX, y: rect.minY) : CGPoint(x: rect.minX, y: rect.maxY), options: [])
        g.restoreGState()
    }

    static func domeTexture() -> UIImage {
        picture(2048, 256) { g, w, h in
            gradient(g, UIColor(hex: 0x2F6F5C), UIColor(hex: 0x4F9A80), CGRect(x: 0, y: 0, width: w, height: h))
            for _ in 0..<2600 {
                g.setFillColor((Bool.random() ? UIColor(red: 12 / 255, green: 44 / 255, blue: 34 / 255, alpha: .random(in: 0...0.1)) : UIColor(red: 150 / 255, green: 214 / 255, blue: 190 / 255, alpha: .random(in: 0...0.1))).cgColor)
                let r = CGFloat.random(in: 0...5)
                g.fillEllipse(in: CGRect(x: .random(in: 0...w) - r, y: .random(in: 0...h) - r, width: r * 2, height: r * 2))
            }
            for i in 0..<20 {
                let x = CGFloat(i) * w / 20
                g.setFillColor(UIColor(hex: 0x163D31).cgColor)
                g.fill(CGRect(x: x - 6, y: 0, width: 12, height: h))
                g.setFillColor(UIColor(hex: 0x7CC4AA).cgColor)
                g.fill(CGRect(x: x + 6, y: 0, width: 5, height: h))
            }
        }
    }

    static func friezeTexture() -> UIImage {
        picture(2048, 128) { g, w, h in
            g.setFillColor(UIColor(hex: 0x15291F).cgColor)
            g.fill(CGRect(x: 0, y: 0, width: w, height: h))
            g.setFillColor(UIColor(hex: 0xD8AB4A).cgColor)
            let n = 64
            for i in 0..<n {
                let x = (CGFloat(i) + 0.5) * w / CGFloat(n), r = w / CGFloat(n) / 2 - 2
                g.fillEllipse(in: CGRect(x: x - r, y: h - r, width: r * 2, height: r * 2))
                g.fillEllipse(in: CGRect(x: x - 7, y: 15, width: 14, height: 14))
            }
        }
    }

    static func baseTexture() -> UIImage {
        picture(2048, 256) { g, w, h in
            gradient(g, UIColor(hex: 0x2C6250), UIColor(hex: 0x173D31), CGRect(x: 0, y: 0, width: w, height: h))
            let n = 20, panel = w / CGFloat(n)
            for i in 0..<n {
                let x = CGFloat(i) * panel
                g.setStrokeColor(UIColor(hex: 0x0E241C).cgColor)
                g.setLineWidth(6)
                g.stroke(CGRect(x: x + 12, y: 26, width: panel - 24, height: h - 64))
                g.setStrokeColor(UIColor(red: 160 / 255, green: 220 / 255, blue: 195 / 255, alpha: 0.35).cgColor)
                g.setLineWidth(2)
                g.stroke(CGRect(x: x + 17, y: 31, width: panel - 34, height: h - 74))
                g.setFillColor(UIColor(hex: 0xD8AB4A).cgColor)
                g.fillEllipse(in: CGRect(x: x + panel / 2 - 6, y: h - 22, width: 12, height: 12))
            }
        }
    }
}

/// One poster as the canvas prints it: paper grain, a big faint number or mark, its label, the tape,
/// the headline in capitals, the line under it and what tapping does.
nonisolated private enum PosterPrint {
    static func draw(_ poster: ComposerPoster, in g: CGContext, width: CGFloat, height: CGFloat) {
        let paper = UIColor(poster.colour), ink = UIColor(poster.ink)
        g.setFillColor(paper.cgColor)
        g.fill(CGRect(x: 0, y: 0, width: width, height: height))
        for n in 0..<900 {
            g.setFillColor((n % 2 == 1 ? UIColor.white : UIColor.black).withAlphaComponent(.random(in: 0...0.035)).cgColor)
            g.fill(CGRect(x: .random(in: 0...width), y: .random(in: 0...height), width: 1 + .random(in: 0...2), height: 1 + .random(in: 0...2)))
        }
        let faint = ink.withAlphaComponent(0.15)
        if let number = poster.number {
            let font = self.font("PosterGrotesque-ExtraBold", poster.numberSize)
            text(number, font, faint, x: width - 8 - size(number, font).width, baseline: 128)
        }
        if let mark = poster.mark { self.mark(mark, in: g, x: width - 74, y: 94, colour: faint) }
        text(poster.label, font("Geist-SemiBold", 13.5), ink, x: 22, baseline: 30)

        var y = height - 22
        let ctaFont = font("Geist-Bold", 14), cta = poster.cta + "  →"
        let ctaWidth = size(cta, ctaFont).width + 30
        g.setFillColor(ink.cgColor)
        g.addPath(UIBezierPath(roundedRect: CGRect(x: 22, y: y - 40, width: ctaWidth, height: 40), cornerRadius: 20).cgPath)
        g.fillPath()
        text(cta, ctaFont, paper, x: 37, baseline: y - 15)
        y -= 54

        let subFont = font("Geist-Medium", 13.5)
        let subs = poster.sub.isEmpty ? [] : wrap(poster.sub, subFont, width - 44)
        for (n, line) in subs.reversed().enumerated() { text(line, subFont, ink, x: 22, baseline: y - CGFloat(n) * 17) }
        y -= CGFloat(subs.count) * 17 + 9

        // The headline shrinks until its longest word fits, and until it fits above the tape.
        let head = poster.head.uppercased()
        var headSize = poster.headSize
        var headFont = font("PosterGrotesque-ExtraBold", headSize)
        var heads = wrap(head, headFont, width - 44)
        // A real headline can be long: it shrinks to four lines, then is cut at five.
        while headSize > 19, (head.split(separator: " ").map { size(String($0), headFont).width }.max() ?? 0) > width - 44 || heads.count > 4 {
            headSize -= 1
            headFont = font("PosterGrotesque-ExtraBold", headSize)
            heads = wrap(head, headFont, width - 44)
        }
        if heads.count > 5 { heads = Array(heads.prefix(5)); heads[4] += "…" }
        for (n, line) in heads.reversed().enumerated() { text(line, headFont, ink, x: 22, baseline: y - CGFloat(n) * headSize * 0.98) }
        y -= CGFloat(heads.count) * headSize * 0.98 + 6

        g.saveGState()
        g.translateBy(x: 22, y: y)
        g.rotate(by: -0.035)
        let tapeFont = font("PermanentMarker-Regular", 14), tape = poster.tape.uppercased()
        g.setFillColor(UIColor(hex: 0xFFE363).cgColor)
        g.fill(CGRect(x: 0, y: -19, width: min(size(tape, tapeFont).width + 18, width - 30), height: 24))
        text(tape, tapeFont, UIColor(hex: 0x211D15), x: 9, baseline: -2)
        g.restoreGState()

        // Each poster's edges fall into shade where it meets the next.
        for (x, from, to) in [(CGFloat(0), 0.3, 0.0), (width - 7, 0.0, 0.3)] {
            guard let gradient = CGGradient(colorsSpace: CGColorSpaceCreateDeviceRGB(), colors: [UIColor.black.withAlphaComponent(from).cgColor, UIColor.black.withAlphaComponent(to).cgColor] as CFArray, locations: [0, 1]) else { continue }
            g.saveGState()
            g.clip(to: CGRect(x: x, y: 0, width: 7, height: height))
            g.drawLinearGradient(gradient, start: CGPoint(x: x, y: 0), end: CGPoint(x: x + 7, y: 0), options: [])
            g.restoreGState()
        }
    }

    private static func font(_ name: String, _ size: CGFloat) -> UIFont {
        UIFont(name: name, size: size) ?? .systemFont(ofSize: size, weight: .bold)
    }

    private static func size(_ string: String, _ font: UIFont) -> CGSize {
        (string as NSString).size(withAttributes: [.font: font])
    }

    private static func text(_ string: String, _ font: UIFont, _ colour: UIColor, x: CGFloat, baseline: CGFloat) {
        (string as NSString).draw(at: CGPoint(x: x, y: baseline - font.ascender), withAttributes: [.font: font, .foregroundColor: colour])
    }

    private static func wrap(_ string: String, _ font: UIFont, _ max: CGFloat) -> [String] {
        var lines: [String] = [], line = ""
        for word in string.split(separator: " ") {
            let next = line.isEmpty ? String(word) : line + " " + word
            if size(next, font).width > max, !line.isEmpty { lines.append(line); line = String(word) } else { line = next }
        }
        if !line.isEmpty { lines.append(line) }
        return lines
    }

    private static func mark(_ mark: ComposerPoster.Mark, in g: CGContext, x: CGFloat, y: CGFloat, colour: UIColor) {
        let path = UIBezierPath()
        switch mark {
        case .spark:
            let r: CGFloat = 74, q: CGFloat = 12
            path.move(to: CGPoint(x: 0, y: -r))
            path.addQuadCurve(to: CGPoint(x: r, y: 0), controlPoint: CGPoint(x: q, y: -q))
            path.addQuadCurve(to: CGPoint(x: 0, y: r), controlPoint: CGPoint(x: q, y: q))
            path.addQuadCurve(to: CGPoint(x: -r, y: 0), controlPoint: CGPoint(x: -q, y: q))
            path.addQuadCurve(to: CGPoint(x: 0, y: -r), controlPoint: CGPoint(x: -q, y: -q))
        case .pin:
            path.addArc(withCenter: CGPoint(x: 0, y: -16), radius: 52, startAngle: .pi * 0.86, endAngle: .pi * 0.14, clockwise: true)
            path.addLine(to: CGPoint(x: 0, y: 74))
            path.close()
            path.append(UIBezierPath(ovalIn: CGRect(x: -20, y: -36, width: 40, height: 40)))
        case .calendar:
            path.append(UIBezierPath(rect: CGRect(x: -62, y: -44, width: 124, height: 112)))
            path.append(UIBezierPath(rect: CGRect(x: -46, y: -14, width: 92, height: 66)))
        }
        path.usesEvenOddFillRule = true
        g.saveGState()
        g.translateBy(x: x, y: y)
        g.setFillColor(colour.cgColor)
        g.addPath(path.cgPath)
        g.fillPath(using: .evenOdd)
        if mark == .calendar {
            g.fill([CGRect(x: -38, y: -68, width: 16, height: 32), CGRect(x: 22, y: -68, width: 16, height: 32), CGRect(x: -62, y: -44, width: 124, height: 18)])
        }
        g.restoreGState()
    }
}

nonisolated private extension UIColor {
    convenience init(hex: UInt32) {
        self.init(red: CGFloat((hex >> 16) & 0xFF) / 255, green: CGFloat((hex >> 8) & 0xFF) / 255, blue: CGFloat(hex & 0xFF) / 255, alpha: 1)
    }
}
