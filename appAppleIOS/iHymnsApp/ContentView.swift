import SwiftUI

struct ContentView: View {
    @State private var searchTerm = ""
    @State private var songs = [Song]()

    var body: some View {
        NavigationView {
            VStack {
                HStack {
                    TextField("Search for songs", text: $searchTerm, onCommit: fetchSongs)
                        .textFieldStyle(RoundedBorderTextFieldStyle())
                        .padding()

                    Button(action: fetchSongs) {
                        Text("Search")
                    }
                    .padding(.trailing)
                }

                List(songs) { song in
                    NavigationLink(destination: SongDetailView(song: song)) {
                        Text(song.title)
                    }
                }
                .navigationBarTitle("Hymns and Worship Songs")
            }
        }
    }

    func fetchSongs() {
        guard let url = URL(string: "https://yourwebsite.com/api_get_song.php?search=\(searchTerm)&language=en") else { return }
        let task = URLSession.shared.dataTask(with: url) { data, response, error in
            if let data = data {
                if let decodedResponse = try? JSONDecoder().decode([Song].self, from: data) {
                    DispatchQueue.main.async {
                        self.songs = decodedResponse
                    }
                    return
                }
            }
        }
        task.resume()
    }
}

struct ContentView_Previews: PreviewProvider {
    static var previews: some View {
        ContentView()
    }
}
