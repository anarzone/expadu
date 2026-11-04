// swift-tools-version: 6.2
import PackageDescription

// Only here to run the generator; nothing in the app links it (Scripts/generate-api.sh).
let package = Package(
    name: "Tools",
    platforms: [.macOS("26.0")],
    dependencies: [
        .package(url: "https://github.com/apple/swift-openapi-generator", from: "1.14.0"),
    ]
)
