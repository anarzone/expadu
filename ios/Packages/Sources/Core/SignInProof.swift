import CryptoKit
import Foundation

/// The one-time secrets that tie a sign-in sheet's answer to the app that opened it.
public enum SignInProof {
    private static let alphabet = Array("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~")

    /// A PKCE verifier (RFC 7636): 64 characters from the unreserved set.
    public static func verifier() -> String {
        random(64)
    }

    /// The verifier's S256 challenge: base64url of its SHA-256, always 43 characters.
    public static func challenge(for verifier: String) -> String {
        Data(SHA256.hash(data: Data(verifier.utf8)))
            .base64EncodedString()
            .replacingOccurrences(of: "+", with: "-")
            .replacingOccurrences(of: "/", with: "_")
            .replacingOccurrences(of: "=", with: "")
    }

    /// A nonce for Sign in with Apple: Apple signs its hash into the token, the server checks the original.
    public static func nonce() -> String {
        random(32)
    }

    /// What Apple's request carries: the nonce's SHA-256 as lowercase hex.
    public static func hashed(_ nonce: String) -> String {
        SHA256.hash(data: Data(nonce.utf8)).map { String(format: "%02x", $0) }.joined()
    }

    private static func random(_ count: Int) -> String {
        var generator = SystemRandomNumberGenerator()
        return String((0..<count).map { _ in alphabet.randomElement(using: &generator)! })
    }
}
