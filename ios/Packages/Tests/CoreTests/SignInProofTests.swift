import Core
import Testing

struct SignInProofTests {
    @Test func theChallengeIsRFC7636sExample() {
        // RFC 7636, appendix B.
        #expect(SignInProof.challenge(for: "dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk") == "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM")
    }

    @Test func aVerifierIsLongEnoughAndUnreserved() {
        let verifier = SignInProof.verifier()
        #expect(verifier.count == 64)
        #expect(verifier.allSatisfy { $0.isLetter || $0.isNumber || "-._~".contains($0) })
        #expect(SignInProof.challenge(for: verifier).count == 43)
        #expect(SignInProof.verifier() != verifier)
    }

    @Test func theNonceIsHashedAsTheServerChecksIt() {
        // PHP's hash('sha256', 'abc').
        #expect(SignInProof.hashed("abc") == "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad")
        #expect(SignInProof.nonce().count == 32)
    }
}
