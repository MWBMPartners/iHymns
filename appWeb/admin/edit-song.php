<?php
include '../../backend/api/db_connection.php';
$song_id = $_GET['id'];
$song = $conn->query("SELECT * FROM songs WHERE id = $song_id")->fetch_assoc();
?>
<h1 class="mt-4">Edit Song</h1>
<form method="POST" action="edit-song-action.php">
    <input type="hidden" name="id" value="<?php echo $song['id']; ?>">
    <div class="mb-3">
        <label for="title" class="form-label">Title</label>
        <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($song['title']); ?>" required>
    </div>
    <div class="mb-3">
        <label for="lyrics" class="form-label">Lyrics</label>
        <textarea class="form-control" id="lyrics" name="lyrics" rows="5" required><?php echo htmlspecialchars($song['lyrics']); ?></textarea>
    </div>
    <div class="mb-3">
        <label for="language" class="form-label">Language</label>
        <input type="text" class="form-control" id="language" name="language" value="<?php echo htmlspecialchars($song['language']); ?>" required>
    </div>
    <button type="submit" class="btn btn-primary">Save Changes</button>
</form>
