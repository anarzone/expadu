// swift-tools-version: 6.2
import PackageDescription

// Non-UI code stays free of SwiftUI and UIKit so `swift test` runs it in milliseconds.
let package = Package(
    name: "ExpaduKit",
    platforms: [.iOS("26.0"), .macOS("26.0")],
    products: [
        .library(name: "Core", targets: ["Core"]),
        .library(name: "DesignSystem", targets: ["DesignSystem"]),
        .library(name: "API", targets: ["API"]),
        .library(name: "Maps", targets: ["Maps"]),
    ],
    dependencies: [
        .package(url: "https://github.com/apple/swift-openapi-runtime", from: "1.8.0"),
        .package(url: "https://github.com/apple/swift-openapi-urlsession", from: "1.1.0"),
        // Open-source maps (BSD), drawn from OpenStreetMap tiles: the same map the web shows.
        .package(url: "https://github.com/maplibre/maplibre-gl-native-distribution", exact: "6.31.0"),
    ],
    targets: [
        .target(name: "Core"),
        .target(
            name: "API",
            dependencies: [
                "Core",
                .product(name: "OpenAPIRuntime", package: "swift-openapi-runtime"),
                .product(name: "OpenAPIURLSession", package: "swift-openapi-urlsession"),
            ],
            exclude: ["openapi.json", "openapi-generator-config.yaml"]
        ),
        .target(
            name: "DesignSystem",
            resources: [.copy("Resources/Fonts")],
            swiftSettings: [.defaultIsolation(MainActor.self)]
        ),
        .target(
            name: "Maps",
            dependencies: [
                "Core",
                // The map is iPhone-only; `swift test` on the Mac builds the rest without it.
                .product(name: "MapLibre", package: "maplibre-gl-native-distribution", condition: .when(platforms: [.iOS])),
            ],
            swiftSettings: [.defaultIsolation(MainActor.self)]
        ),
        .testTarget(name: "CoreTests", dependencies: ["Core"], resources: [.copy("Fixtures")]),
        .testTarget(name: "APITests", dependencies: ["API"]),
    ]
)
