<?php
session_start();

// Handle logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$success_message = isset($_GET['success']) ? htmlspecialchars($_GET['success']) : '';

$host = "localhost";
$dbname = "guidance";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch student data
    $stmt = $conn->prepare("SELECT s.StudentID, s.FirstName, s.LastName, s.MiddleName, s.YearLevel, s.Course 
                            FROM student s 
                            JOIN account a ON s.AccountID = a.AccountID 
                            WHERE a.UserName = :username AND a.IsActive = 1");
    $stmt->bindParam(':username', $_SESSION['username']);
    $stmt->execute();
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch role
    $stmt = $conn->prepare("SELECT Role FROM account WHERE UserName = :username AND IsActive = 1");
    $stmt->bindParam(':username', $_SESSION['username']);
    $stmt->execute();
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch form submission status
    $stmt = $conn->query("SELECT FormSubmissionStatus FROM settings ORDER BY LastUpdated DESC LIMIT 1");
    $formSubmissionStatus = $stmt->fetchColumn() ?: 'open';
    $formOpen = ($formSubmissionStatus === 'open');

    // Check form status
    $formStatus = 'not_submitted';
    $formId = null;
    if ($student) {
        $stmt = $conn->prepare("SELECT FormID, ApproveStat FROM form WHERE StudentID = :studentId ORDER BY Approvedate DESC LIMIT 1");
        $stmt->bindParam(':studentId', $student['StudentID']);
        $stmt->execute();
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($form) {
            $formStatus = $form['ApproveStat'] == 1 ? 'approved' : 'pending';
            $formId = $form['FormID'];
        }
    }

    // Default student data
    if (!$student) {
        $student = [
            'StudentID' => 'Not Available',
            'FirstName' => 'Unknown',
            'LastName' => 'User',
            'MiddleName' => '',
            'YearLevel' => 'Not Available',
            'Course' => 'Not Available'
        ];
    }
} catch (PDOException $e) {
    echo "Database error: " . htmlspecialchars($e->getMessage());
    exit();
}

// Ordinal function
function getOrdinal($number) {
    if (!is_numeric($number)) return $number;
    $number = (int)$number;
    if ($number % 100 >= 11 && $number % 100 <= 13) {
        return $number . 'th';
    }
    switch ($number % 10) {
        case 1: return $number . 'st';
        case 2: return $number . 'nd';
        case 3: return $number . 'rd';
        default: return $number . 'th';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Portal</title>
  <link rel="icon" type="image/x-icon" href="guid.png">
  <link rel="stylesheet" href="studform.css">
  <style>
    .landing {
      max-width: 900px;
      margin: 50px auto;
      background: whitesmoke;
      border-radius: 12px;
      padding: 30px;
      box-shadow: 0px 8px 20px rgba(0,0,0,0.2);
      text-align: center;
    }
    .landing h1 {
      margin-bottom: 10px;
      color: #1c9f3f;
    }
    .profile-card {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: center;
      gap: 30px;
      margin: 20px 0;
    }
    .profile-info {
      text-align: left;
    }
    .profile-info h3 {
      margin: 0;
      color: #333;
    }
    .profile-info p {
      margin: 5px 0;
      color: #555;
    }
    .profile-card img {
      width: 150px;
      height: 150px;
      border-radius: 50%;
      object-fit: cover;
      box-shadow: 0px 4px 10px rgba(0,0,0,0.3);
    }
    .status {
      padding: 10px;
      border-radius: 8px;
      font-weight: bold;
      display: inline-block;
    }
    .status.open { background: #e5f6e8; color: #1c9f3f; }
    .status.closed { background: #ffeaea; color: #d93025; }
    .status.pending { background: #fff3cd; color: #856404; }
    .status.approved { background: #d4edda; color: #155724; }
    .success-message {
      padding: 10px;
      background: #d4edda;
      color: #155724;
      border-radius: 8px;
      margin-bottom: 20px;
    }
    .btn {
      display: inline-block;
      padding: 12px 20px;
      background: #008000;
      color: white;
      border-radius: 6px;
      font-size: 16px;
      font-weight: bold;
      text-decoration: none;
      box-shadow: 0px 4px 10px rgba(0,0,0,0.2);
      transition: background 0.3s ease;
    }
    .btn:hover { background: #006400; }
    .btn.logout { background: #d93025; margin-top:20px;}
    .btn.logout:hover { background: #b71c1c; }
  </style>
</head>
<body>
  <div class="landing">
    <h1>Welcome, Student!</h1>
    <p>You are logged in to the JHCSC Guidance System.</p>
    <?php if (!empty($success_message)): ?>
      <div class="success-message"><?php echo $success_message; ?></div>
    <?php endif; ?>
    <div class="profile-card">
      <img src="https://i.pinimg.com/originals/75/ae/6e/75ae6eeeeb590c066ec53b277b614ce3.jpg" alt="Profile Picture">
      <div class="profile-info">
        <h3>
          <?php echo isset($student['FirstName']) && isset($student['LastName']) && isset($student['MiddleName']) 
              ? htmlspecialchars($student['FirstName'] . ' ' . $student['LastName'] . ' ' . $student['MiddleName']) 
              : 'Unknown User'; ?>
        </h3>
        <p><b>ID:</b> 
          <?php echo isset($student['StudentID']) ? htmlspecialchars($student['StudentID']) : 'Not Available'; ?>
        </p>
        <p><b>Course:</b> 
          <?php echo isset($student['Course']) ? htmlspecialchars($student['Course']) : 'Not Available'; ?>
        </p>
        <p><b>Year:</b> 
          <?php echo isset($student['YearLevel']) ? htmlspecialchars(getOrdinal($student['YearLevel'])) : 'Not Available'; ?>
        </p>
        <p><b>Role:</b> 
          <?php echo isset($account['Role']) ? htmlspecialchars($account['Role']) : 'Not Available'; ?>
        </p>
      </div>
    </div>
    <div id="statusBox" class="status"></div>
    <div id="formButton"></div>
    <div>
      <a href="?action=logout" class="btn logout">Logout</a>
    </div>
  </div>

  <script>
    const formOpen = <?php echo json_encode($formOpen); ?>;
    const formStatus = <?php echo json_encode($formStatus); ?>;
    const formId = <?php echo json_encode($formId); ?>;
    
    const statusBox = document.getElementById("statusBox");
    const formButton = document.getElementById("formButton");

    if (!formOpen) {
      statusBox.textContent = "Form submission is currently CLOSED.";
      statusBox.className = "status closed";
    } else if (formStatus === 'not_submitted') {
      statusBox.textContent = "Form submission is OPEN. Please fill out your form.";
      statusBox.className = "status open";
      formButton.innerHTML = `<a href="studform.php" class="btn">📋 Fill Out Student Inventory Form</a>`;
    } else if (formStatus === 'pending') {
      statusBox.textContent = "Your form is pending approval.";
      statusBox.className = "status pending";
      formButton.innerHTML = `<a href="studform.php?formId=${formId}&action=update" class="btn">📝 Update Student Inventory Form</a>`;
    } else if (formStatus === 'approved') {
      statusBox.textContent = "Your form has been approved.";
      statusBox.className = "status approved";
      formButton.innerHTML = `<a href="studform.php?formId=${formId}&action=update" class="btn">📝 Update Student Inventory Form</a>`;
    }
  </script>
</body>
</html>