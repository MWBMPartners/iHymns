<?php
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Control Panel</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css">
</head>
<body>
    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <div class="bg-light border-right" id="sidebar-wrapper">
            <div class="sidebar-heading">Admin Panel</div>
            <div class="list-group list-group-flush">
                <a href="#" class="list-group-item list-group-item-action bg-light" data-page="home">Admin Home</a>
                <a href="#" class="list-group-item list-group-item-action bg-light" data-page="add-song">Add Song</a>
                <a href="#" class="list-group-item list-group-item-action bg-light" data-page="list-songs">List All Songs</a>
                <a href="#" class="list-group-item list-group-item-action bg-light" data-page="add-songbook">Add Songbook</a>
                <a href="#" class="list-group-item list-group-item-action bg-light" data-page="add-media">Add/Link Media</a>
                <a href="#" class="list-group-item list-group-item-action bg-light" data-page="profile">User Profile</a>
                <a href="logout.php" class="list-group-item list-group-item-action bg-light">Log Out</a>
            </div>
        </div>
        <!-- /#sidebar-wrapper -->

        <!-- Page Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom">
                <button class="btn btn-primary" id="menu-toggle">Toggle Menu</button>
            </nav>
            <div class="container-fluid">
                <div id="page-content">
                    <h1 class="mt-4">Admin Home</h1>
                    <p>Welcome to the Admin Panel. Use the menu to navigate through different sections.</p>
                </div>
            </div>
        </div>
        <!-- /#page-content-wrapper -->
    </div>
    <!-- /#wrapper -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
    <script src="js/admin-scripts.js"></script>
</body>
</html>
