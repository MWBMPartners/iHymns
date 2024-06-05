import Foundation

struct Song: Identifiable, Codable {
    var id: Int
    var title: String
    var lyrics: String
    var language: String
    var is_copy_protected: Bool
    var embeddable_media: [String: String]?
    var purchase_links: [String: String]?
    var background_media_type: String
    var background_media_url: String

    static var example: Song {
        return Song(
            id: 1,
            title: "Amazing Grace",
            lyrics: "Amazing grace! How sweet the sound...",
            language: "en",
            is_copy_protected: false,
            embeddable_media: ["YouTube": "https://youtube.com/example"],
            purchase_links: ["Amazon": "https://amazon.com/example"],
            background_media_type: "image",
            background_media_url: "https://example.com/image.jpg"
        )
    }
}
