import 'package:flutter/material.dart';
import 'package:hymn_app/song.dart';
import 'package:video_player/video_player.dart';

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
                        child: Text(entry.key, style: TextStyle(fontSize: 16, color: Colors.blue)),
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
