<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../login.php");
    exit;
}

// Prevent browser from caching the dashboard after logout
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
header("Pragma: no-cache");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistant Dashboard</title>
</head>
<body>
    <h1>Welcome to the Assistant Dashboard</h1>
    
    <!-- Logout Button -->
    <form action="../logout.php" method="POST">
        <button type="submit">Logout</button>
    </form>

    <!-- Dashboard Content -->
    <p>Here is your dashboard content...</p>
</body>
</html>
