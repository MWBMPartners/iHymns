<?php
include 'db_connection.php';
header('Content-Type: application/json');

$searchTerm = $_GET['search'];
$searchLanguage = $_GET['language'];
$userStatus = 'free'; // Determine user status based on authentication

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
echo json_encode($songs);
?>
