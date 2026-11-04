import Foundation
import Security

/// The token and the account it belongs to, on this device only, readable after the first unlock
/// so background work can use it later.
public struct StoredSession: Codable, Equatable, Sendable {
    public let token: AppToken
    public let account: Account

    public init(token: AppToken, account: Account) {
        self.token = token
        self.account = account
    }
}

public enum TokenKeychain {
    private static var query: [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: "com.expadu.app.session",
            kSecAttrAccount as String: "current",
        ]
    }

    public static func load() -> StoredSession? {
        var item: CFTypeRef?
        var search = query
        search[kSecReturnData as String] = true
        guard SecItemCopyMatching(search as CFDictionary, &item) == errSecSuccess, let data = item as? Data else {
            return nil
        }
        return try? JSONDecoder().decode(StoredSession.self, from: data)
    }

    public static func save(_ session: StoredSession) {
        guard let data = try? JSONEncoder().encode(session) else { return }
        clear()
        var add = query
        add[kSecValueData as String] = data
        add[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        SecItemAdd(add as CFDictionary, nil)
    }

    public static func clear() {
        SecItemDelete(query as CFDictionary)
    }
}
