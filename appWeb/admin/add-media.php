<?php
// Add/Link Media form
?>
<h1 class="mt-4">Add/Link Media</h1>
<form method="POST" action="add-media-action.php">
    <div class="mb-3">
        <label for="song_id" class="form-label">Song ID</label>
        <input type="text" class="form-control" id="song_id" name="song_id" required>
    </div>
    <div class="mb-3">
        <label for="media_type" class="form-label">Media Type</label>
        <input type="text" class="form-control" id="media_type" name="media_type" required>
    </div>
    <div class="mb-3">
        <label for="media_url" class="form-label">Media URL</label>
        <input type="text" class="form-control" id="media_url" name="media_url" required>
    </div>
    <button type="submit" class="btn btn-primary">Add Media</button>
</form>
