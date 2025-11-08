<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Database connection
$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "guidance";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check form submission status from settings
$sql = "SELECT FormSubmissionStatus FROM settings ORDER BY LastUpdated DESC LIMIT 1";
$result = $conn->query($sql);
$formSubmissionStatus = $result->fetch_assoc()['FormSubmissionStatus'] ?? 'open';
$formOpen = ($formSubmissionStatus === 'open');

if (!$formOpen) {
    die("Form submission is currently closed.");
}

// Check if this is an update action
$isUpdate = isset($_GET['action']) && $_GET['action'] === 'update' && isset($_GET['formId']);
$formId = $isUpdate ? (int)$_GET['formId'] : 0;

// Fetch student data
$sql = "SELECT s.StudentID, s.FirstName, s.MiddleName, s.LastName, s.YearLevel, s.Course, 
               f.CivilStatus, f.Contactnum AS ContactNumber, f.Email, f.Address, f.Nationality, f.Religion, f.FormID
        FROM student s
        JOIN account a ON s.AccountID = a.AccountID
        LEFT JOIN form f ON s.StudentID = f.StudentID
        WHERE a.UserName = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("s", $_SESSION['username']);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
$stmt->close();

// Validate formId for updates
if ($isUpdate && $student && $formId !== ($student['FormID'] ?? 0)) {
    die("Invalid form ID for update.");
}

// Fetch additional information if it exists
$additional_info = [];
if ($student) {
    $sql = "SELECT * FROM additionalinformation WHERE StudentID = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $student['StudentID']);
    $stmt->execute();
    $additional_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Fetch marriage information if it exists
$marriage_info = [];
if ($student && ($student['CivilStatus'] ?? '') === 'Married') {
    $sql = "SELECT * FROM marriageinformation WHERE StudentID = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $student['StudentID']);
    $stmt->execute();
    $marriage_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Parse living arrangement for pre-filling form fields
$living_type = $additional_info['LivingArrangement'] ?? 'home';
$living_address = '';
$living_contact = '';
$others_specify = '';
if (isset($additional_info['LivingArrangement'])) {
    if ($living_type === 'boarding' || $living_type === 'relatives') {
        $living_address = $additional_info['EmergencyAddress'] ?? '';
        $living_contact = $additional_info['EmergencyNumber'] ?? '';
    } elseif ($living_type !== 'home') {
        $others_specify = $additional_info['LivingArrangement'];
        $living_type = 'others';
    }
}

// Handle form submission
$success_message = "";
$error_message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && $student) {
    // Form Information
    $civil_status = $_POST['civilStatus'] ?? $student['CivilStatus'] ?? 'Single';
    $contact_number = $_POST['contactNumber'] ?? $student['ContactNumber'] ?? '';
    $email = $_POST['email'] ?? $student['Email'] ?? '';
    $address = $_POST['address'] ?? $student['Address'] ?? '';
    $nationality = $_POST['nationality'] ?? $student['Nationality'] ?? '';
    $religion = $_POST['religion'] ?? $student['Religion'] ?? '';

    // Additional Information
    $living_arrangement = $_POST['living'] ?? 'home';
    if ($living_arrangement === 'boarding') {
        $living_arrangement = $_POST['boardingAddress'] ?? '';
    } elseif ($living_arrangement === 'relatives') {
        $living_arrangement = $_POST['relativesAddress'] ?? '';
    } elseif ($living_arrangement === 'others') {
        $living_arrangement = $_POST['othersSpecify'] ?? '';
    }
    $source_of_financial = $_POST['support'] ?? '';
    $transportation = $_POST['MTFS'] ?? '';
    $emergency_name = $_POST['EmerName'] ?? '';
    $emergency_address = $_POST['EmerAdres'] ?? '';
    $emergency_number = $_POST['EmerCon'] ?? '';

    // Handle image upload
    $profile_picture = $additional_info['ProfilePicture'] ?? '';
    if (isset($_FILES['profilePicture']) && $_FILES['profilePicture']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png'];
        $max_size = 2 * 1024 * 1024; // 2MB
        $file_type = $_FILES['profilePicture']['type'];
        $file_size = $_FILES['profilePicture']['size'];
        $file_tmp = $_FILES['profilePicture']['tmp_name'];
        $file_ext = strtolower(pathinfo($_FILES['profilePicture']['name'], PATHINFO_EXTENSION));
        $file_name = 'profile_' . $student['StudentID'] . '_' . time() . '.' . $file_ext;
        $upload_dir = 'Uploads/';
        $upload_path = $upload_dir . $file_name;

        // Validate image
        if (!in_array($file_type, $allowed_types)) {
            $error_message = "Only JPEG and PNG images are allowed.";
        } elseif ($file_size > $max_size) {
            $error_message = "Image size must not exceed 2MB.";
        } elseif (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true)) {
            $error_message = "Failed to create upload directory.";
        } elseif (!move_uploaded_file($file_tmp, $upload_path)) {
            $error_message = "Failed to upload image.";
        } else {
            $profile_picture = $upload_path;
        }
    }

    // Validate inputs
    if (empty($civil_status) || empty($contact_number) || empty($email) || empty($address) || 
        empty($nationality) || empty($religion) || empty($living_arrangement) || 
        empty($source_of_financial) || empty($transportation) || empty($emergency_name) || 
        empty($emergency_address) || empty($emergency_number)) {
        $error_message = "All required fields must be filled.";
    } else {
        // Start transaction
        $conn->begin_transaction();

        try {
            // Insert or update additional information
            if ($additional_info) {
                $sql = "UPDATE additionalinformation SET LivingArrangement = ?, SourceofFinancial = ?, Transportation = ?, EmergencyName = ?, EmergencyAddress = ?, EmergencyNumber = ?, ProfilePicture = ? WHERE StudentID = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssssi", $living_arrangement, $source_of_financial, $transportation, $emergency_name, $emergency_address, $emergency_number, $profile_picture, $student['StudentID']);
            } else {
                $sql = "INSERT INTO additionalinformation (LivingArrangement, SourceofFinancial, Transportation, EmergencyName, EmergencyAddress, EmergencyNumber, ProfilePicture, StudentID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssssi", $living_arrangement, $source_of_financial, $transportation, $emergency_name, $emergency_address, $emergency_number, $profile_picture, $student['StudentID']);
            }
            if (!$stmt->execute()) {
                throw new Exception("Error saving additional information: " . $stmt->error);
            }
            $aid = $additional_info ? $additional_info['AID'] : $conn->insert_id;
            $stmt->close();

            // Insert or update marriage information (if married)
            $marriage_id = 0;
            if ($civil_status === 'Married') {
                $first_name = $_POST['firstName'] ?? '';
                $middle_name = $_POST['middleName'] ?? '';
                $last_name = $_POST['lastName'] ?? '';
                $date_of_birth = $_POST['dateOfBirth'] ?? '';
                $spouse_nationality = $_POST['spouseNationality'] ?? '';
                $num_children = $_POST['children'] ?? 0;
                $marriage_date = $_POST['marriageDate'] ?? '';

                if (empty($first_name) || empty($middle_name) || empty($last_name) || empty($date_of_birth) || empty($spouse_nationality) || empty($marriage_date)) {
                    throw new Exception("All marriage fields are required if married.");
                }

                if ($marriage_info) {
                    $sql = "UPDATE marriageinformation SET FirstName = ?, MiddleName = ?, LastName = ?, DateOfBirth = ?, Nationality = ?, NumberOfChildren = ?, DateOfMarriage = ? WHERE StudentID = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssisi", $first_name, $middle_name, $last_name, $date_of_birth, $spouse_nationality, $num_children, $marriage_date, $student['StudentID']);
                } else {
                    $sql = "INSERT INTO marriageinformation (FirstName, MiddleName, LastName, DateOfBirth, Nationality, NumberOfChildren, DateOfMarriage, StudentID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssisi", $first_name, $middle_name, $last_name, $date_of_birth, $spouse_nationality, $num_children, $marriage_date, $student['StudentID']);
                }
                if (!$stmt->execute()) {
                    throw new Exception("Error saving marriage information: " . $stmt->error);
                }
                $marriage_id = $marriage_info ? $marriage_info['MarriageID'] : $conn->insert_id;
                $stmt->close();
            } else {
                // If not married, use existing or create a dummy marriage record
                if (!$marriage_info) {
                    $sql = "INSERT INTO marriageinformation (FirstName, MiddleName, LastName, DateOfBirth, Nationality, NumberOfChildren, DateOfMarriage, StudentID) VALUES ('N/A', 'N/A', 'N/A', '0000-00-00', 'N/A', 0, '0000-00-00', ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $student['StudentID']);
                    if (!$stmt->execute()) {
                        throw new Exception("Error creating dummy marriage record: " . $stmt->error);
                    }
                    $marriage_id = $conn->insert_id;
                    $stmt->close();
                } else {
                    $marriage_id = $marriage_info['MarriageID'];
                }
            }

            // Insert or update form
            $approve_date = '0000-00-00'; // Default for unapproved form
            $approve_stat = 0; // Always set to pending for new submissions or updates
            $sql = "SELECT FormID FROM form WHERE StudentID = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $student['StudentID']);
            $stmt->execute();
            $form_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($form_info) {
                $sql = "UPDATE form SET CivilStatus = ?, Contactnum = ?, Email = ?, Address = ?, Nationality = ?, Religion = ?, MarriageID = ?, AID = ?, Approvedate = ?, ApproveStat = ? WHERE StudentID = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssiiisi", $civil_status, $contact_number, $email, $address, $nationality, $religion, $marriage_id, $aid, $approve_date, $approve_stat, $student['StudentID']);
            } else {
                $sql = "INSERT INTO form (StudentID, MarriageID, AID, Approvedate, ApproveStat, CivilStatus, Contactnum, Email, Address, Nationality, Religion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iisisssssss", $student['StudentID'], $marriage_id, $aid, $approve_date, $approve_stat, $civil_status, $contact_number, $email, $address, $nationality, $religion);
            }
            if (!$stmt->execute()) {
                throw new Exception("Error saving form: " . $stmt->error);
            }
            $stmt->close();

            // Commit transaction
            $conn->commit();
            $success_message = $isUpdate ? "Form updated successfully! Awaiting approval." : "Form submitted successfully!";
            // Redirect to index.php after successful submission
            header("Location: studland.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Inventory Form</title>
  <link rel="stylesheet" href="studform.css">
</head>
<body>
<div class="form">
  <?php if ($student): ?>
    <h1><?php echo $isUpdate ? 'Update Student Inventory Form' : 'Student Inventory Form'; ?></h1>
    <?php if (!empty($success_message)): ?>
      <p style="color: green;"><?php echo htmlspecialchars($success_message); ?></p>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
      <p style="color: red;"><?php echo htmlspecialchars($error_message); ?></p>
    <?php endif; ?>
    <div class="name">
      <div class="pfp">
        <?php if (!empty($additional_info['ProfilePicture']) && file_exists($additional_info['ProfilePicture'])): ?>
          <img src="<?php echo htmlspecialchars($additional_info['ProfilePicture']); ?>" alt="Profile Picture">
        <?php else: ?>
          <img src="https://i.pinimg.com/originals/75/ae/6e/75ae6eeeeb590c066ec53b277b614ce3.jpg" alt="Default Profile Picture">
        <?php endif; ?>
      </div>
      <div class="fullname">
        <div><?php echo htmlspecialchars($student['LastName'] . ', ' . $student['FirstName'] . ' ' . $student['MiddleName']); ?></div>
        <div><?php echo htmlspecialchars($student['YearLevel'] . ' Year - ' . $student['Course']); ?></div>
      </div>
    </div>

    <form method="POST" enctype="multipart/form-data">
      <label for="profilePicture">Upload 2x2 Profile Picture:</label>
      <input type="file" id="profilePicture" name="profilePicture" accept="image/jpeg,image/png">

      <label for="civilStatus">Civil Status:</label>
      <select id="civilStatus" name="civilStatus" required>
        <option value="Single" <?php echo ($student['CivilStatus'] ?? '') === 'Single' ? 'selected' : ''; ?>>Single</option>
        <option value="Married" <?php echo ($student['CivilStatus'] ?? '') === 'Married' ? 'selected' : ''; ?>>Married</option>
        <option value="Widowed" <?php echo ($student['CivilStatus'] ?? '') === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
      </select>

      <label for="contactNumber">Cellphone Number:</label>
      <input type="tel" id="contactNumber" name="contactNumber" placeholder="09XXXXXXXXX" pattern="[0-9]{11}" value="<?php echo htmlspecialchars($student['ContactNumber'] ?? ''); ?>" required>

      <label for="email">Email:</label>
      <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($student['Email'] ?? ''); ?>" required>

      <label for="address">Address:</label>
      <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($student['Address'] ?? ''); ?>" required>

      <label for="nationality">Nationality:</label>
      <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($student['Nationality'] ?? ''); ?>" required>

      <label for="religion">Religion:</label>
      <input type="text" id="religion" name="religion" value="<?php echo htmlspecialchars($student['Religion'] ?? ''); ?>" required>

      <?php if (($student['CivilStatus'] ?? '') === 'Married'): ?>
        <h3>Marriage Information</h3>
        <label for="firstName">Spouse First Name:</label>
        <input type="text" id="firstName" name="firstName" value="<?php echo htmlspecialchars($marriage_info['FirstName'] ?? ''); ?>" required>

        <label for="middleName">Spouse Middle Name:</label>
        <input type="text" id="middleName" name="middleName" value="<?php echo htmlspecialchars($marriage_info['MiddleName'] ?? ''); ?>" required>

        <label for="lastName">Spouse Last Name:</label>
        <input type="text" id="lastName" name="lastName" value="<?php echo htmlspecialchars($marriage_info['LastName'] ?? ''); ?>" required>

        <label for="dateOfBirth">Spouse Date of Birth:</label>
        <input type="date" id="dateOfBirth" name="dateOfBirth" value="<?php echo htmlspecialchars($marriage_info['DateOfBirth'] ?? ''); ?>" required>

        <label for="spouseNationality">Spouse Nationality:</label>
        <input type="text" id="spouseNationality" name="spouseNationality" value="<?php echo htmlspecialchars($marriage_info['Nationality'] ?? ''); ?>" required>

        <label for="children">Number of Children:</label>
        <input type="number" id="children" name="children" value="<?php echo htmlspecialchars($marriage_info['NumberOfChildren'] ?? 0); ?>" min="0">

        <label for="marriageDate">Date of Marriage:</label>
        <input type="date" id="marriageDate" name="marriageDate" value="<?php echo htmlspecialchars($marriage_info['DateOfMarriage'] ?? ''); ?>" required>
      <?php endif; ?>

      <h3>Living Arrangement:</h3>
      <div class="radio-group">
        <label>
          <input type="radio" name="living" id="home" value="home" onclick="toggleLivingFields()" <?php echo $living_type === 'home' ? 'checked' : ''; ?>> 
          Living at home with family
        </label>
        <label>
          <input type="radio" name="living" id="boarding" value="boarding" onclick="toggleLivingFields()" <?php echo $living_type === 'boarding' ? 'checked' : ''; ?>> 
          Living in a boarding house
        </label>
        <div id="boardingFields" style="display:<?php echo $living_type === 'boarding' ? 'block' : 'none'; ?>; margin-left:20px;">
          <input type="text" id="boardingAddress" name="boardingAddress" placeholder="Address" value="<?php echo htmlspecialchars($living_address); ?>">
          <input type="tel" id="boardingContact" name="boardingContact" placeholder="Contact No. (09XXXXXXXXX)" pattern="[0-9]{11}" value="<?php echo htmlspecialchars($living_contact); ?>">
        </div>
        <label>
          <input type="radio" name="living" id="relatives" value="relatives" onclick="toggleLivingFields()" <?php echo $living_type === 'relatives' ? 'checked' : ''; ?>> 
          Living with relatives/guardians
        </label>
        <div id="relativesFields" style="display:<?php echo $living_type === 'relatives' ? 'block' : 'none'; ?>; margin-left:20px;">
          <input type="text" id="relativesAddress" name="relativesAddress" placeholder="Address" value="<?php echo htmlspecialchars($living_address); ?>">
          <input type="tel" id="relativesContact" name="relativesContact" placeholder="Contact No. (09XXXXXXXXX)" pattern="[0-9]{11}" value="<?php echo htmlspecialchars($living_contact); ?>">
        </div>
        <label>
          <input type="radio" name="living" id="others" value="others" onclick="toggleLivingFields()" <?php echo $living_type === 'others' ? 'checked' : ''; ?>> 
          Others (please specify)
        </label>
        <div id="othersFields" style="display:<?php echo $living_type === 'others' ? 'block' : 'none'; ?>; margin-left:20px;">
          <input type="text" id="othersSpecify" name="othersSpecify" value="<?php echo htmlspecialchars($others_specify); ?>">
        </div>
      </div>

      <h3>Source of Financial Support in College:</h3>
      <div class="radio-group">
        <label>
          <input type="radio" name="support" value="family" required <?php echo ($additional_info['SourceofFinancial'] ?? '') === 'family' ? 'checked' : ''; ?>>
          A. Family
        </label>
        <label>
          <input type="radio" name="support" value="scholarship" <?php echo ($additional_info['SourceofFinancial'] ?? '') === 'scholarship' ? 'checked' : ''; ?>>
          B. Scholarship
        </label>
        <label>
          <input type="radio" name="support" value="educational_plan" <?php echo ($additional_info['SourceofFinancial'] ?? '') === 'educational_plan' ? 'checked' : ''; ?>>
          C. Educational Plan
        </label>
        <label>
          <input type="radio" name="support" value="part_time_job" <?php echo ($additional_info['SourceofFinancial'] ?? '') === 'part_time_job' ? 'checked' : ''; ?>>
          D. Part-time Job
        </label>
      </div>

      <label for="MTFS">Mode of Transportation To and From School:</label>
      <input type="text" id="MTFS" name="MTFS" value="<?php echo htmlspecialchars($additional_info['Transportation'] ?? ''); ?>" required>

      <label for="EmerName">In case of emergency, please notify:</label>
      <input type="text" id="EmerName" name="EmerName" placeholder="Name" value="<?php echo htmlspecialchars($additional_info['EmergencyName'] ?? ''); ?>" required>
      <input type="text" id="EmerAdres" name="EmerAdres" placeholder="Address" value="<?php echo htmlspecialchars($additional_info['EmergencyAddress'] ?? ''); ?>" required>
      <input type="tel" id="EmerCon" name="EmerCon" placeholder="Contact No. (09XXXXXXXXX)" pattern="[0-9]{11}" value="<?php echo htmlspecialchars($additional_info['EmergencyNumber'] ?? ''); ?>" required>

      <button type="submit"><?php echo $isUpdate ? 'Update' : 'Submit'; ?></button>
    </form>
  <?php else: ?>
    <p style="color: red;">Student data not found. Please ensure you are logged in.</p>
  <?php endif; ?>
</div>

<script>
function toggleLivingFields() {
  const boarding = document.getElementById("boarding");
  const relatives = document.getElementById("relatives");
  const others = document.getElementById("others");

  document.getElementById("boardingFields").style.display = boarding.checked ? "block" : "none";
  document.getElementById("relativesFields").style.display = relatives.checked ? "block" : "none";
  document.getElementById("othersFields").style.display = others.checked ? "block" : "none";
}
</script>
</body>
</html>