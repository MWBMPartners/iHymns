import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';

void main() {
  runApp(MyApp());
}

class MyApp extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hymns & Worship Songs',
      theme: ThemeData(
        primarySwatch: Colors.blue,
      ),
      home: MyHomePage(),
    );
  }
}

class MyHomePage extends StatefulWidget {
  @override
  _MyHomePageState createState() => _MyHomePageState();
}

class _MyHomePageState extends State<MyHomePage> {
  TextEditingController _searchController = TextEditingController();
  List<Song> _songs = [];

  void _fetchSongs() async {
    final response = await http.get(
      Uri.parse('https://yourwebsite.com/api_get_song.php?search=${_searchController.text}&language=en'),
    );

    if (response.statusCode == 200) {
      final List<dynamic> songJson = json.decode(response.body);
      setState(() {
        _songs = songJson.map((json) => Song.fromJson(json)).toList();
      });
    } else {
      // Handle error
      throw Exception('Failed to load songs');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Hymns & Worship Songs'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(8.0),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                labelText: 'Search for songs',
                suffixIcon: IconButton(
                  icon: Icon(Icons.search),
                  onPressed: _fetchSongs,
                ),
              ),
            ),
          ),
          Expanded(
            child: ListView.builder(
              itemCount: _songs.length,
              itemBuilder: (context, index) {
                return ListTile(
                  title: Text(_songs[index].title),
                  onTap: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (context) => SongDetailView(song: _songs[index]),
                      ),
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

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

class SongDetailView extends StatelessWidget {
  final Song song;

  SongDetailView({required this.song});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(song.title),
      ),
      body: SingleChildScrollView(
        padding: EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(song.title, style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold)),
            SizedBox(height: 16),
            if (song.backgroundMediaType == 'video') ...[
              VideoPlayerWidget(url: song.backgroundMediaUrl),
              SizedBox(height: 16),
            ] else if (song.backgroundMediaType == 'image') ...[
              Image.network(song.backgroundMediaUrl),
              SizedBox(height: 16),
            ],
            Text(song.lyrics, style: TextStyle(fontSize: 16)),
            SizedBox(height: 16),
            if (song.embeddableMedia != null)
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Embeddable Media:', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  ...song.embeddableMedia!.entries.map((entry) {
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4.0),
                      child: InkWell(
                        onTap: () => _launchURL(entry.value),
                        child: Text(entry.key, style: TextStyle(fontSize: 16, color: Colors.blue)),
                      ),
                    );
                  }).toList(),
                ],
              ),
            SizedBox(height: 16),
            if (song.purchaseLinks != null)
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Purchase Links:', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  ...song.purchaseLinks!.entries.map((entry) {
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 4.0),
                      child: InkWell(
                        onTap: () => _launchURL(entry.value),
                        child: Text('Buy on ${entry.key}', style: TextStyle(fontSize: 16, color: Colors.blue)),
                      ),
                    );
                  }).toList(),
                ],
              ),
          ],
        ),
      ),
    );
  }

  void _launchURL(String url) async {
    if (await canLaunch(url)) {
      await launch(url);
    } else {
      throw 'Could not launch $url';
    }
  }
}

class VideoPlayerWidget extends StatefulWidget {
  final String url;

  VideoPlayerWidget({required this.url});

  @override
  _VideoPlayerWidgetState createState() => _VideoPlayerWidgetState();
}

class _VideoPlayerWidgetState extends State<VideoPlayerWidget> {
  late VideoPlayerController _controller;

  @override
  void initState() {
    super.initState();
    _controller = VideoPlayerController.network(widget.url)
      ..initialize().then((_) {
        setState(() {});
      });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return _controller.value.isInitialized
        ? AspectRatio(
            aspectRatio: _controller.value.aspectRatio,
            child: VideoPlayer(_controller),
          )
        : Center(child: CircularProgressIndicator());
  }
}
