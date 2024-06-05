import 'package:flutter/material.dart';
import 'package:hymn_app/song_detail_view.dart';
import 'package:hymn_app/song.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';

void main() {
  runApp(MyApp());
}

class MyApp extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hymns and Worship Songs',
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
        title: Text('Hymns and Worship Songs'),
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
