<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['username'])) {
    if ($_SESSION['role'] === "admin" || $_SESSION['role'] === "Admin") {
        header("Location: Admin-Dashboard.php");
        exit();
    } else {
        header("Location: studland.php");
        exit();
    }
}

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$db = "guidance";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to match database
$conn->set_charset("utf8mb4");

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Prepare query to check username and active status
    $sql = "SELECT * FROM Account WHERE UserName = ? AND IsActive = 1 LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("SQL Error: " . $conn->error);
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();

        // Verify hashed password
        if (password_verify($password, $row['Password'])) {
            $_SESSION['username'] = $row['UserName'];
            $_SESSION['role'] = $row['Role'];

            if ($row['Role'] === "admin" || $row['Role'] === "Admin") {
                header("Location: Admin-Dashboard.php");
                exit();
            } elseif($row['Role'] === "staff" || $row['Role'] === "Staff") {
                header("Location: Staff.php");
                exit();
            } else {
                header("Location: studland.php");
                exit();
            }
        } else {
            echo "<script>alert('Invalid password.');</script>";
        }
    } else {
        echo "<script>alert('No active user found with that username.');</script>";
    }

    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>JHCSC Guidance System</title>
  <link rel="stylesheet" href="login.css">
</head>
<body>

  <!-- Navbar -->
  <div class="navbar" role="banner">
    <div class="container">
      <nav>
        <ul class="nav">
          <li><a href="https://jhcsc.edu.ph/" target="_blank">Go to jhcsc.edu.ph</a></li>
          <li><a href="https://opac.jhcsc.edu.ph/" target="_blank">College eLibrary</a></li>
          <li><a href="https://quest.jhcsc.edu.ph/" target="_blank">Learning Management System</a></li>
          <li><a href="https://www.facebook.com/profile.php?id=61574526132767" target="_blank">Follow us on Facebook</a></li>
        </ul>
      </nav>
    </div>
  </div>

  <div class="login">
    <div class="logo">
      <img src="log.png">
      <img src="guid.png">
    </div>
    <h3>JHCSC Guidance Student Inventory System</h3>
    <p>Login</p>

    <form method="POST" action="">
      <input type="text" name="username" placeholder="Username" required>
      <input type="password" name="password" placeholder="Password" required>
      <button type="submit" id="Login">Login</button>
    </form>
  </div>

  <footer>
    <p>Copyright © 
      <a href="https://jhcsc.edu.ph/" target="_blank">J.H. Cerilles State College</a> | 
      All Rights Reserved
    </p>
  </footer>

</body>
</html>