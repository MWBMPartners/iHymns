<?php
include '../../backend/api/db_connection.php';
$songs = $conn->query("SELECT * FROM songs");
?>
<h1 class="mt-4">List All Songs</h1>
<table class="table">
    <thead>
        <tr>
            <th>Title</th>
            <th>Language</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($song = $songs->fetch_assoc()): ?>
        <tr>
            <td><?php echo htmlspecialchars($song['title']); ?></td>
            <td><?php echo htmlspecialchars($song['language']); ?></td>
            <td>
                <a href="#" class="btn btn-warning" data-page="edit-song" data-id="<?php echo $song['id']; ?>">Edit</a>
                <a href="delete-song.php?id=<?php echo $song['id']; ?>" class="btn btn-danger">Delete</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
