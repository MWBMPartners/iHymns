<?php
// index.php
include 'db_connection.php';
session_start();

$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
$searchLanguage = isset($_GET['language']) ? $_GET['language'] : 'en';
$userStatus = isset($_SESSION['subscription_status']) ? $_SESSION['subscription_status'] : 'free';

function searchSongs($searchTerm, $searchLanguage, $userStatus) {
    global $conn;

    $sql = "CALL SearchSongs(?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $searchTerm, $searchLanguage, $userStatus);
    $stmt->execute();
    $result = $stmt->get_result();

    $songs = [];
    while ($row = $result->fetch_assoc()) {
        $songs[] = $row;
    }

    return $songs;
}

$songs = searchSongs($searchTerm, $searchLanguage, $userStatus);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hymns and Worship Songs</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .song-title {
            font-weight: bold;
        }
        .chorus {
            font-style: italic;
        }
        .song-container {
            margin-bottom: 20px;
        }
        .background-video {
            position: absolute;
            top: 0;
            left: 0;
            min-width: 100%;
            min-height: 100%;
            z-index: -1;
        }
    </style>
</head>
<body>
<div class="container">
    <h1 class="my-4">Hymns and Worship Songs</h1>
    <form method="GET" action="index.php" class="mb-4">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Search for songs" value="<?php echo htmlspecialchars($searchTerm); ?>">
            <select name="language" class="form-select">
                <option value="en" <?php echo $searchLanguage == 'en' ? 'selected' : ''; ?>>English</option>
                <!-- Add more language options as needed -->
            </select>
            <button class="btn btn-primary" type="submit">Search</button>
        </div>
    </form>

    <?php foreach ($songs as $song): ?>
        <div class="song-container">
            <?php if ($song['background_media_type'] == 'video'): ?>
                <video class="background-video" autoplay loop muted>
                    <source src="<?php echo htmlspecialchars($song['background_media_url']); ?>" type="video/mp4">
                </video>
            <?php elseif ($song['background_media_type'] == 'image'): ?>
                <div style="background-image: url('<?php echo htmlspecialchars($song['background_media_url']); ?>'); background-size: cover; background-position: center; height: 300px;">
                </div>
            <?php endif; ?>
            <div class="card mt-4">
                <div class="card-body">
                    <h2 class="song-title"><?php echo htmlspecialchars($song['title']); ?></h2>
                    <p><?php echo nl2br(htmlspecialchars($song['lyrics'])); ?></p>
                    <?php if (!empty($song['embeddable_media'])): ?>
                        <div>
                            <?php
                            $media = json_decode($song['embeddable_media'], true);
                            foreach ($media as $provider => $url) {
                                echo '<a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars(ucfirst($provider)) . '</a> ';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($song['purchase_links'])): ?>
                        <div>
                            <?php
                            $links = json_decode($song['purchase_links'], true);
                            foreach ($links as $provider => $url) {
                                echo '<a href="' . htmlspecialchars($url) . '" target="_blank">Buy on ' . htmlspecialchars(ucfirst($provider)) . '</a> ';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
