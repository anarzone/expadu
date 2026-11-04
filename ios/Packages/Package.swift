// swift-tools-version: 6.2
import PackageDescription

// Non-UI code stays free of SwiftUI and UIKit so `swift test` runs it in milliseconds.
let package = Package(
    name: "ExpaduKit",
    platforms: [.iOS("26.0"), .macOS("26.0")],
    products: [
        .library(name: "Core", targets: ["Core"]),
        .library(name: "DesignSystem", targets: ["DesignSystem"]),
    ],
    targets: [
        .target(name: "Core"),
        .target(
            name: "DesignSystem",
            resources: [.copy("Resources/Fonts")],
            swiftSettings: [.defaultIsolation(MainActor.self)]
        ),
        .testTarget(name: "CoreTests", dependencies: ["Core"]),
    ]
)
