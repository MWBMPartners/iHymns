import SwiftUI

struct SongDetailView: View {
    var song: Song

    var body: some View {
        ScrollView {
            VStack(alignment: .leading) {
                Text(song.title)
                    .font(.title)
                    .padding(.bottom, 10)
                
                if let mediaUrl = URL(string: song.backgroundMediaUrl), song.backgroundMediaType == "video" {
                    VideoPlayer(player: AVPlayer(url: mediaUrl))
                        .frame(height: 300)
                } else if let mediaUrl = URL(string: song.backgroundMediaUrl), song.backgroundMediaType == "image" {
                    AsyncImage(url: mediaUrl)
                        .frame(height: 300)
                }

                Text(song.lyrics)
                    .padding(.top, 10)

                if let mediaLinks = song.embeddableMedia {
                    VStack(alignment: .leading) {
                        Text("Embeddable Media:")
                            .font(.headline)
                            .padding(.top, 10)

                        ForEach(mediaLinks.keys.sorted(), id: \.self) { key in
                            if let url = URL(string: mediaLinks[key]!) {
                                Link(key.capitalized, destination: url)
                                    .padding(.bottom, 5)
                            }
                        }
                    }
                }

                if let purchaseLinks = song.purchaseLinks {
                    VStack(alignment: .leading) {
                        Text("Purchase Links:")
                            .font(.headline)
                            .padding(.top, 10)

                        ForEach(purchaseLinks.keys.sorted(), id: \.self) { key in
                            if let url = URL(string: purchaseLinks[key]!) {
                                Link("Buy on \(key.capitalized)", destination: url)
                                    .padding(.bottom, 5)
                            }
                        }
                    }
                }
            }
            .padding()
        }
        .navigationBarTitle(Text(song.title), displayMode: .inline)
    }
}

struct SongDetailView_Previews: PreviewProvider {
    static var previews: some View {
        SongDetailView(song: Song.example)
    }
}
