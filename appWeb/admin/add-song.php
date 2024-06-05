<?php
// Add Song form
?>
<h1 class="mt-4">Add Song</h1>
<form method="POST" action="add-song-action.php">
    <div class="mb-3">
        <label for="title" class="form-label">Title</label>
        <input type="text" class="form-control" id="title" name="title" required>
    </div>
    <div class="mb-3">
        <label for="lyrics" class="form-label">Lyrics</label>
        <textarea class="form-control" id="lyrics" name="lyrics" rows="5" required></textarea>
    </div>
    <div class="mb-3">
        <label for="language" class="form-label">Language</label>
        <input type="text" class="form-control" id="language" name="language" required>
    </div>
    <button type="submit" class="btn btn-primary">Add Song</button>
</form>
