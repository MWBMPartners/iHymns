class Song {
  final int id;
  final String title;
  final String lyrics;
  final String language;
  final bool isCopyProtected;
  final Map<String, String>? embeddableMedia;
  final Map<String, String>? purchaseLinks;
  final String backgroundMediaType;
  final String backgroundMediaUrl;

  Song({
    required this.id,
    required this.title,
    required this.lyrics,
    required this.language,
    required this.isCopyProtected,
    this.embeddableMedia,
    this.purchaseLinks,
    required this.backgroundMediaType,
    required this.backgroundMediaUrl,
  });

  factory Song.fromJson(Map<String, dynamic> json) {
    return Song(
      id: json['id'],
      title: json['title'],
      lyrics: json['lyrics'],
      language: json['language'],
      isCopyProtected: json['is_copy_protected'],
      embeddableMedia: json['embeddable_media'] != null ? Map<String, String>.from(json['embeddable_media']) : null,
      purchaseLinks: json['purchase_links'] != null ? Map<String, String>.from(json['purchase_links']) : null,
      backgroundMediaType: json['background_media_type'],
      backgroundMediaUrl: json['background_media_url'],
    );
  }
}
