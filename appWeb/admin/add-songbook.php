<?php
// Add Songbook form
?>
<h1 class="mt-4">Add Songbook</h1>
<form method="POST" action="add-songbook-action.php">
    <div class="mb-3">
        <label for="name" class="form-label">Name</label>
        <input type="text" class="form-control" id="name" name="name" required>
    </div>
    <div class="mb-3">
        <label for="description" class="form-label">Description</label>
        <textarea class="form-control" id="description" name="description" rows="5" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Add Songbook</button>
</form>
