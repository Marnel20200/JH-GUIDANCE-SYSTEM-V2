<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require 'vendor/autoload.php';
use NotificationAPI\NotificationAPI;

session_start();

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

// Check admin/staff access
if (!isset($_SESSION['username']) || !in_array(strtolower($_SESSION['role']), ['admin', 'staff'])) {
    if (isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'student') {
        header("Location: studland.php");
    } else {
        header("Location: login.php");
    }
    exit();
}

// Database connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=guidance;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Check server logs.");
}

// Fetch admin info
$stmt = $pdo->prepare("SELECT AccountID, UserName, Role FROM account WHERE UserName = ? AND IsActive = 1");
$stmt->execute([$_SESSION['username']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
$adminId = $admin ? $admin['AccountID'] : 0;
$adminName = $admin ? $admin['UserName'] : 'Unknown User';
$adminRole = $admin ? $admin['Role'] : 'Admin';

// Handle form submission status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_form_status') {
    $status = filter_var($_POST['form_status'] ?? '', FILTER_SANITIZE_STRING);
    if (in_array($status, ['open', 'closed'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO settings (FormSubmissionStatus, LastUpdated) VALUES (?, NOW())");
            $stmt->execute([$status]);
            header("Location: Admin-Dashboard.php?page=dashboard");
            exit();
        } catch (PDOException $e) {
            error_log("Form status update failed: " . $e->getMessage());
            echo "<script>alert('Failed to update form status: " . htmlspecialchars($e->getMessage()) . "');</script>";
        }
    } else {
        echo "<script>alert('Invalid form status.');</script>";
    }
}

// Fetch current form submission status
$stmt = $pdo->query("SELECT FormSubmissionStatus FROM settings ORDER BY LastUpdated DESC LIMIT 1");
$currentFormStatus = $stmt->fetchColumn() ?: 'open';

// Handle page navigation
$page = $_GET['page'] ?? 'dashboard';

// Fetch dashboard counts
try {
    $stmt = $pdo->query("SELECT 
        (SELECT COUNT(DISTINCT StudentID) FROM form) AS studentsCount,
        (SELECT COUNT(*) FROM guidancerecord) AS guidanceCount,
        (SELECT COUNT(*) FROM form WHERE ApproveStat = 0) AS pendingCount");
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    $studentsCount = $counts['studentsCount'] ?? 0;
    $guidanceCount = $counts['guidanceCount'] ?? 0;
    $pendingCount = $counts['pendingCount'] ?? 0;
} catch (PDOException $e) {
    error_log("Count query failed: " . $e->getMessage());
    $studentsCount = $guidanceCount = $pendingCount = 0;
}

// Fetch cases by year level
$casesByYearLevel = $pdo->query("SELECT s.YearLevel AS YearLevel, COUNT(*) as Count 
    FROM guidancerecord g JOIN student s ON g.StudentID = s.StudentID 
    GROUP BY s.YearLevel")->fetchAll(PDO::FETCH_ASSOC) ?: 
    array_map(fn($level) => ['YearLevel' => $level, 'Count' => 0], ['1', '2', '3', '4']);

// Fetch students by year
$studentsByYear = $pdo->query("SELECT YearLevel, COUNT(*) as Count FROM student GROUP BY YearLevel")->fetchAll(PDO::FETCH_ASSOC) ?: 
    array_map(fn($level) => ['YearLevel' => $level, 'Count' => 0], ['1', '2', '3', '4']);

// Handle student search and filters
$search = ($page === 'students' || $page === 'forms') && isset($_GET['search']) ? $_GET['search'] : '';
$yearFilter = ($page === 'students' || $page === 'forms') && isset($_GET['year']) ? $_GET['year'] : '';
$courseFilter = ($page === 'students' || $page === 'forms') && isset($_GET['course']) ? $_GET['course'] : '';

$studentQuery = "SELECT s.StudentID as id, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as name, 
                 s.YearLevel as year, s.Course as course, a.UserName as email, 
                 f.ApproveStat as form_status, f.FormID as form_id
                 FROM student s 
                 JOIN account a ON s.AccountID = a.AccountID 
                 LEFT JOIN form f ON s.StudentID = f.StudentID WHERE 1=1";
$studentParams = [];
if ($search) {
    $studentQuery .= " AND (s.LastName LIKE ? OR s.FirstName LIKE ? OR s.MiddleName LIKE ?)";
    $studentParams = array_fill(0, 3, "%$search%");
}
if ($yearFilter) {
    $studentQuery .= " AND s.YearLevel = ?";
    $studentParams[] = $yearFilter;
}
if ($courseFilter) {
    $studentQuery .= " AND s.Course LIKE ?";
    $studentParams[] = "%$courseFilter%";
}
$stmt = $pdo->prepare($studentQuery);
$stmt->execute($studentParams);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch courses and years
$courses = $pdo->query("SELECT DISTINCT s.Course 
                        FROM student s 
                        WHERE s.Course != ''")->fetchAll(PDO::FETCH_COLUMN);
$years = $pdo->query("SELECT DISTINCT s.YearLevel 
                      FROM student s 
                      WHERE s.YearLevel != ''")->fetchAll(PDO::FETCH_COLUMN);

// Fetch testing records
$testingQuery = "SELECT s.StudentID, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as student, 
                 s.YearLevel as year, s.Course as course, t.DateTaken as date, t.TestName as test, 
                 t.Purpose as purpose, t.Result as result 
                 FROM testingrecord t JOIN student s ON t.StudentID = s.StudentID";
$stmt = $pdo->prepare($testingQuery);
$stmt->execute();
$testingReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch guidance records
$guidanceQuery = "SELECT s.StudentID, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as student, 
                  s.YearLevel as year, s.Course as course, g.Date as date, a.UserName as counselor, 
                  g.Purpose as purpose, g.Remarks as remarks 
                  FROM guidancerecord g 
                  JOIN student s ON g.StudentID = s.StudentID 
                  JOIN account a ON g.AccountID = a.AccountID";
$stmt = $pdo->prepare($guidanceQuery);
$stmt->execute();
$guidanceReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch users
$userQuery = "SELECT AccountID as id, UserName as name, Role as role 
              FROM account WHERE Role IN ('Admin', 'Staff') AND IsActive = 1";
$stmt = $pdo->prepare($userQuery);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all forms (pending and approved)
$formQuery = "SELECT f.FormID, s.StudentID, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as student, 
              f.Approvedate, f.ApproveStat, f.CivilStatus, f.Contactnum, f.Email, f.Address, 
              f.Nationality, f.Religion 
              FROM form f JOIN student s ON f.StudentID = s.StudentID";
$stmt = $pdo->prepare($formQuery);
$stmt->execute();
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form and student actions 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $error_message = '';
    if (in_array($_POST['action'], ['approve_form', 'reject_form', 'undo_form_approval'])) {
        $formId = filter_var($_POST['form_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($formId === false || $formId < 0) {
            $error_message = "Invalid form ID.";
        } else {
            try {
                $pdo->beginTransaction();

                if ($_POST['action'] === 'approve_form') {
                    // ✅ APPROVE FORM
                    $stmt = $pdo->prepare("UPDATE form SET ApproveStat = 1, Approvedate = ? WHERE FormID = ?");
                    $stmt->execute([date('Y-m-d'), $formId]);

                    // Fetch form details for notification
                    $stmt = $pdo->prepare("SELECT Email, Contactnum FROM form WHERE FormID = ?");
                    $stmt->execute([$formId]);
                    $formDetails = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($formDetails) {
                        $email = $formDetails['Email'];
                        $contact = $formDetails['Contactnum'];
                        $phone = '+63' . substr($contact, 1); // Convert 09... → +639...

                        $notificationapi = new NotificationAPI(
                            "8tzrict1toxad6v7ghfpe52l4i", // Client ID
                            "wr1wd99a4fp4oyhahsyfaq5yea7hb0cnyu66gp9wn6ca5maegfutbszw64" // Client Secret
                        );

                        $notificationapi->send([
                            'type' => 'approval',
                            'to' => [
                                'id' => $email,
                                'email' => $email,
                                'number' => $phone
                            ],
                            'templateId' => 'template_one'
                        ]);
                    }

                } elseif ($_POST['action'] === 'reject_form') {
                    // ❌ REJECT FORM
                    $stmt = $pdo->prepare("SELECT StudentID, Email, Contactnum FROM form WHERE FormID = ?");
                    $stmt->execute([$formId]);
                    $formDetails = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($formDetails) {
                        $email = $formDetails['Email'];
                        $contact = $formDetails['Contactnum'];
                        $phone = '+63' . substr($contact, 1);

                        // Delete form
                        $stmt = $pdo->prepare("DELETE FROM form WHERE FormID = ?");
                        $stmt->execute([$formId]);

                        // Set rejection flag in session
                        $_SESSION['form_rejected_' . $formDetails['StudentID']] = true;

                        // 🔔 Send rejection notification
                        $notificationapi = new NotificationAPI(
                            "8tzrict1toxad6v7ghfpe52l4i", // Client ID
                            "wr1wd99a4fp4oyhahsyfaq5yea7hb0cnyu66gp9wn6ca5maegfutbszw64" // Client Secret
                        );

                        $notificationapi->send([
                            'type' => 'approval',
                            'to' => [
                                'id' => $email,
                                'email' => $email,
                                'number' => $phone
                            ],
                            'templateId' => 'template_two'
                        ]);
                    } else {
                        $error_message = "Form not found.";
                    }

                } elseif ($_POST['action'] === 'undo_form_approval') {
                    // 🔄 UNDO APPROVAL
                    $stmt = $pdo->prepare("UPDATE form SET ApproveStat = 0, Approvedate = NULL WHERE FormID = ?");
                    $stmt->execute([$formId]);
                }

                $pdo->commit();
                header("Location: Admin-Dashboard.php?page=pendingForms");
                exit();

            } catch (PDOException $e) {
                $pdo->rollBack();
                $error_message = "Database error: " . $e->getMessage();
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_message = "Notification error: " . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'add_user') {
        $name = filter_var($_POST['name'] ?? '', FILTER_SANITIZE_STRING);
        $password = $_POST['password'] ? password_hash($_POST['password'], PASSWORD_DEFAULT) : password_hash('default123', PASSWORD_DEFAULT);
        $role = $_POST['role'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $name)) {
            $error_message = "Invalid username. Use 3-50 alphanumeric characters or underscores.";
        } elseif (!in_array($role, ['Admin', 'Staff'])) {
            $error_message = "Invalid role selected.";
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE UserName = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                $error_message = "Username already exists.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO account (UserName, Password, Role, IsActive) VALUES (?, ?, ?, 1)");
                $stmt->execute([$name, $password, $role]);
                header("Location: Admin-Dashboard.php?page=userManagement");
                exit();
            }
        }
    }
    if ($_POST['action'] === 'delete_user') {
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            $error_message = "Invalid user ID.";
        } elseif ($id == $adminId) {
            $error_message = "Cannot delete your own account.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM account WHERE AccountID = ? AND Role IN ('Admin', 'Staff')");
            $stmt->execute([$id]);
            header("Location: Admin-Dashboard.php?page=userManagement");
            exit();
        }
    }
    if ($_POST['action'] === 'delete_student') {
        $studentId = filter_var($_POST['student_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($studentId === false || $studentId <= 0) {
            $error_message = "Invalid student ID.";
        } else {
            try {
                $pdo->beginTransaction();
                // Fetch AccountID before deletion
                $stmt = $pdo->prepare("SELECT AccountID FROM student WHERE StudentID = ?");
                $stmt->execute([$studentId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($student) {
                    // Delete from student table (cascades to related tables due to foreign key constraints)
                    $stmt = $pdo->prepare("DELETE FROM student WHERE StudentID = ?");
                    $stmt->execute([$studentId]);
                    // Delete from account table
                    $stmt = $pdo->prepare("DELETE FROM account WHERE AccountID = ?");
                    $stmt->execute([$student['AccountID']]);
                    $pdo->commit();
                    header("Location: Admin-Dashboard.php?page=students");
                    exit();
                } else {
                    $error_message = "Student not found.";
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error_message = "Database error: " . $e->getMessage();
            }
        }
    }
    if ($_POST['action'] === 'register_student') {
        try {
            $pdo->beginTransaction();

            // Account details
            $username = filter_var($_POST['username'] ?? '', FILTER_SANITIZE_EMAIL);
            $password = password_hash($_POST['password'] ?: 'student123', PASSWORD_DEFAULT);
            if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format.");
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE UserName = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Username already exists.");
            }
            $stmt = $pdo->prepare("INSERT INTO account (UserName, Password, Role, IsActive) VALUES (?, ?, 'Student', 1)");
            $stmt->execute([$username, $password]);
            $accountId = $pdo->lastInsertId();

            // Student details
            $firstName = filter_var($_POST['first_name'] ?? '', FILTER_SANITIZE_STRING);
            $middleName = filter_var($_POST['middle_name'] ?? '', FILTER_SANITIZE_STRING);
            $lastName = filter_var($_POST['last_name'] ?? '', FILTER_SANITIZE_STRING);
            $yearLevel = filter_var($_POST['year_level'] ?? '', FILTER_SANITIZE_STRING);
            $course = filter_var($_POST['course'] ?? '', FILTER_SANITIZE_STRING);
            if (empty($firstName) || empty($lastName) || empty($yearLevel) || empty($course)) {
                throw new Exception("Required student fields are missing.");
            }
            $stmt = $pdo->prepare("INSERT INTO student (AccountID, FirstName, MiddleName, LastName, YearLevel, Course) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$accountId, $firstName, $middleName, $lastName, $yearLevel, $course]);

            $pdo->commit();
            header("Location: Admin-Dashboard.php?page=students");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Failed to register student: " . $e->getMessage();
        }
    }
    if ($error_message) {
        echo "<script>alert('$error_message');</script>";
    }
}

if ($page === 'getStudentDetails' && isset($_GET['studentId'])) {
    try {
        $studentId = filter_var($_GET['studentId'], FILTER_VALIDATE_INT);
        if ($studentId === false || $studentId <= 0) {
            throw new Exception('Invalid student ID format');
        }

        $stmt = $pdo->prepare("SELECT s.StudentID, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as name, 
                               s.YearLevel, s.Course, a.UserName as email, ai.ProfilePicture 
                               FROM student s 
                               JOIN account a ON s.AccountID = a.AccountID 
                               LEFT JOIN additionalinformation ai ON s.StudentID = ai.StudentID 
                               WHERE s.StudentID = ?");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            throw new Exception('Student not found');
        }

        header('Content-Type: application/json');
        echo json_encode($student);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}

if ($page === 'getFormDetails' && isset($_GET['formId'])) {
    try {
        $formId = filter_var($_GET['formId'], FILTER_VALIDATE_INT);
        if ($formId === false || $formId <= 0) {
            throw new Exception('Invalid form ID format');
        }

        $stmt = $pdo->prepare("SELECT f.*, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as student, 
                               ai.*, ai.ProfilePicture, mi.* 
                               FROM form f 
                               JOIN student s ON f.StudentID = s.StudentID 
                               LEFT JOIN additionalinformation ai ON f.AID = ai.AID 
                               LEFT JOIN marriageinformation mi ON f.MarriageID = mi.MarriageID 
                               WHERE f.FormID = ?");
        $stmt->execute([$formId]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$form) {
            throw new Exception('Form not found');
        }

        header('Content-Type: application/json');
        echo json_encode([
            'student' => $form['student'],
            'CivilStatus' => $form['CivilStatus'],
            'Contactnum' => $form['Contactnum'],
            'Email' => $form['Email'],
            'Address' => $form['Address'],
            'Nationality' => $form['Nationality'],
            'Religion' => $form['Religion'],
            'Approvedate' => $form['Approvedate'],
            'additional' => [
                'LivingArrangement' => $form['LivingArrangement'] ?? null,
                'SourceofFinancial' => $form['SourceofFinancial'] ?? null,
                'Transportation' => $form['Transportation'] ?? null,
                'EmergencyName' => $form['EmergencyName'] ?? null,
                'EmergencyAddress' => $form['EmergencyAddress'] ?? null,
                'EmergencyNumber' => $form['EmergencyNumber'] ?? null
            ],
            'marriage' => [
                'FirstName' => $form['FirstName'] ?? null,
                'MiddleName' => $form['MiddleName'] ?? null,
                'LastName' => $form['LastName'] ?? null,
                'DateOfBirth' => $form['DateOfBirth'] ?? null,
                'Nationality' => $form['Nationality'] ?? null,
                'NumberOfChildren' => $form['NumberOfChildren'] ?? null,
                'DateOfMarriage' => $form['DateOfMarriage'] ?? null
            ],
            'profilePicture' => $form['ProfilePicture'] ?? null
        ]);
        exit();
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="dash.css">
</head>
<body>
    <div class="sidebar">
        <div class="account">
            <img src="https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png" alt="Profile">
            <div class="acc-inf">
                <h3><?php echo htmlspecialchars($adminName); ?></h3>
                <p><?php echo htmlspecialchars($adminRole); ?></p>
            </div>
        </div>
        <div class="sideopt">
            <a href="Admin-Dashboard.php?page=dashboard" <?php echo $page === 'dashboard' ? 'class="active"' : ''; ?>>Dashboard</a>
            <a href="Admin-Dashboard.php?page=students" <?php echo $page === 'students' ? 'class="active"' : ''; ?>>Students</a>
            <a href="Admin-Dashboard.php?page=pendingForms" <?php echo $page === 'pendingForms' ? 'class="active"' : ''; ?>>Pending Forms</a>
            <a href="Admin-Dashboard.php?page=testingReports" <?php echo $page === 'testingReports' ? 'class="active"' : ''; ?>>Testing Reports</a>
            <a href="Admin-Dashboard.php?page=guidanceReports" <?php echo $page === 'guidanceReports' ? 'class="active"' : ''; ?>>Guidance Reports</a>
            <?php if (strtolower($adminRole) === 'admin'): ?>
                <a href="Admin-Dashboard.php?page=userManagement" <?php echo $page === 'userManagement' ? 'class="active"' : ''; ?>>User Management</a>
            <?php endif; ?>
        </div>
        <a href="Admin-Dashboard.php?action=logout" class="logout">Logout</a>
    </div>
    <div class="Topbar">
        <h2>Admin Dashboard</h2>
    </div>
    <div class="contentbg">
        <div class="content">
            <?php if ($page === 'dashboard'): ?>
                <h2>Dashboard</h2>
                <div class="cards">
                    <div class="card">
                        <h3>Students</h3>
                        <p><?php echo $studentsCount; ?></p>
                    </div>
                    <div class="card">
                        <h3>Guidance Cases</h3>
                        <p><?php echo $guidanceCount; ?></p>
                    </div>
                    <div class="card">
                        <h3>Pending Forms</h3>
                        <p><?php echo $pendingCount; ?></p>
                    </div>
                </div>
                <div class="section-grid">
                    <div class="panel panel--wide">
                        <h3>Cases by Year Level</h3>
                        <!-- Chart or table here -->
                    </div>
                    <div class="panel panel--side">
                        <h3>Students by Year</h3>
                        <!-- Chart or table here -->
                    </div>
                </div>
                <div class="form-submission-control">
                    <h3>Form Submission Control</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_form_status">
                        <select name="form_status">
                            <option value="open" <?php echo $currentFormStatus === 'open' ? 'selected' : ''; ?>>Open</option>
                            <option value="closed" <?php echo $currentFormStatus === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        </select>
                        <button type="submit" class="btn">Update</button>
                    </form>
                </div>

            <?php elseif ($page === 'students'): ?>
                <h2>Students</h2>
                <div style="margin-bottom: 20px;">
                    <button class="btn btn-register" onclick="openRegisterModal()">Register New Student</button>
                </div>
                <form class="filter-form" method="GET">
                    <input type="hidden" name="page" value="students">
                    <div class="form-group">
                        <label for="search">Search by Name</label>
                        <input type="text" name="search" class="search-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="Enter name...">
                    </div>
                    <div class="form-group">
                        <label for="year">Year Level</label>
                        <select name="year">
                            <option value="">All</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $year === $yearFilter ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="course">Course</label>
                        <select name="course">
                            <option value="">All</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo htmlspecialchars($course); ?>" <?php echo $course === $courseFilter ? 'selected' : ''; ?>><?php echo htmlspecialchars($course); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn">Filter</button>
                </form>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Year</th>
                                <th>Course</th>
                                <th>Email</th>
                                <th>Form Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr><td colspan="7">No students found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['id']); ?></td>
                                        <td><?php echo htmlspecialchars($student['name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['year']); ?></td>
                                        <td><?php echo htmlspecialchars($student['course']); ?></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td><?php echo $student['form_status'] !== null ? ($student['form_status'] ? 'Approved' : 'Pending') : 'Not Submitted'; ?></td>
                                        <td>
                                            <button class="btn" onclick="openStudentModal(<?php echo $student['id']; ?>)">View</button>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_student">
                                                <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                                <button type="submit" class="btn reject">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="studentModal" class="modal">
                    <div class="modal-content">
                        <span class="close" onclick="closeStudentModal()">&times;</span>
                        <h3>Student Details</h3>
                        <div id="studentDetails"></div>
                    </div>
                </div>
                <div id="registerModal" class="modal">
                    <div class="modal-content">
                        <span class="close" onclick="closeRegisterModal()">&times;</span>
                        <h3>Register New Student</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="register_student">
                            <div class="form-group">
                                <label for="username">Email/Username</label>
                                <input type="email" name="username" required>
                            </div>
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" name="password" placeholder="Default: student123">
                            </div>
                            <div class="form-group">
                                <label for="first_name">First Name</label>
                                <input type="text" name="first_name" required>
                            </div>
                            <div class="form-group">
                                <label for="middle_name">Middle Name</label>
                                <input type="text" name="middle_name">
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last Name</label>
                                <input type="text" name="last_name" required>
                            </div>
                            <div class="form-group">
                                <label for="year_level">Year Level</label>
                                <input type="text" name="year_level" required>
                            </div>
                            <div class="form-group">
                                <label for="course">Course</label>
                                <input type="text" name="course" required>
                            </div>
                            <button type="submit" class="btn btn-register">Register</button>
                        </form>
                    </div>
                </div>
                <script>
                    function openStudentModal(studentId) {
                        fetch('Admin-Dashboard.php?page=getStudentDetails&studentId=' + encodeURIComponent(studentId))
                            .then(response => response.json())
                            .then(data => {
                                if (data.error) {
                                    document.getElementById('studentDetails').innerHTML = `<p class="error">${data.error}</p>`;
                                } else {
                                    document.getElementById('studentDetails').innerHTML = `
                                        <img src="${data.ProfilePicture || 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'}" alt="Profile Picture" style="width:80px; height:80px; border-radius:50%; border:3px solid #4caf50; margin-bottom:10px; display: block; margin-left: auto; margin-right: auto;">
                                        <p><strong>Name:</strong> ${data.name}</p>
                                        <p><strong>Year Level:</strong> ${data.YearLevel}</p>
                                        <p><strong>Course:</strong> ${data.Course}</p>
                                        <p><strong>Email:</strong> ${data.email}</p>
                                    `;
                                }
                                document.getElementById('studentModal').style.display = 'block';
                            })
                            .catch(error => {
                                document.getElementById('studentDetails').innerHTML = `<p class="error">Error loading student details: ${error.message}</p>`;
                                document.getElementById('studentModal').style.display = 'block';
                            });
                    }
                    function closeStudentModal() {
                        document.getElementById('studentModal').style.display = 'none';
                    }
                    function openRegisterModal() {
                        document.getElementById('registerModal').style.display = 'block';
                    }
                    function closeRegisterModal() {
                        document.getElementById('registerModal').style.display = 'none';
                    }
                </script>

            <?php elseif ($page === 'pendingForms'): ?>
                <h2>Pending Forms</h2>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Form ID</th>
                                <th>Student</th>
                                <th>Civil Status</th>
                                <th>Contact</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($forms)): ?>
                                <tr><td colspan="7">No forms found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($forms as $form): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($form['FormID']); ?></td>
                                        <td><?php echo htmlspecialchars($form['student']); ?></td>
                                        <td><?php echo htmlspecialchars($form['CivilStatus']); ?></td>
                                        <td><?php echo htmlspecialchars($form['Contactnum']); ?></td>
                                        <td><?php echo htmlspecialchars($form['Email']); ?></td>
                                        <td><?php echo $form['ApproveStat'] ? 'Approved' : 'Pending'; ?></td>
                                        <td>
                                            <?php if (!$form['ApproveStat']): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="form_id" value="<?php echo $form['FormID']; ?>">
                                                    <input type="hidden" name="action" value="approve_form">
                                                    <button type="submit" class="btn approve" onclick="return confirm('Are you sure you want to approve this form?');">Approve</button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="form_id" value="<?php echo $form['FormID']; ?>">
                                                    <input type="hidden" name="action" value="reject_form">
                                                    <button type="submit" class="btn reject" onclick="return confirm('Are you sure you want to reject this form?');">Reject</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="form_id" value="<?php echo $form['FormID']; ?>">
                                                    <input type="hidden" name="action" value="undo_form_approval">
                                                    <button type="submit" class="btn undo" onclick="return confirm('Are you sure you want to undo this approval?');">Undo Approval</button>
                                                </form>
                                            <?php endif; ?>
                                            <button class="btn" onclick="openFormModal(<?php echo $form['FormID']; ?>)">View</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="formModal" class="modal">
                    <div class="modal-content">
                        <span class="close" onclick="closeFormModal()">&times;</span>
                        <h3>Form Details</h3>
                        <div id="formDetails"></div>
                    </div>
                </div>
                <script>
                    function openFormModal(formId) {
                        fetch('Admin-Dashboard.php?page=getFormDetails&formId=' + encodeURIComponent(formId))
                            .then(response => response.json())
                            .then(data => {
                                if (data.error) {
                                    document.getElementById('formDetails').innerHTML = `<p class="error">${data.error}</p>`;
                                } else {
                                    document.getElementById('formDetails').innerHTML = `
                                        <img src="${data.profilePicture || 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'}" alt="Profile Picture" style="width:80px; height:80px; border-radius:50%; border:3px solid #4caf50; margin-bottom:10px; display: block; margin-left: auto; margin-right: auto;">
                                        <p><strong>Student:</strong> ${data.student}</p>
                                        <p><strong>Civil Status:</strong> ${data.CivilStatus}</p>
                                        <p><strong>Contact:</strong> ${data.Contactnum}</p>
                                        <p><strong>Email:</strong> ${data.Email}</p>
                                        <p><strong>Address:</strong> ${data.Address}</p>
                                        <p><strong>Nationality:</strong> ${data.Nationality}</p>
                                        <p><strong>Religion:</strong> ${data.Religion}</p>
                                        <p><strong>Approved Date:</strong> ${data.Approvedate || 'N/A'}</p>
                                        <h4>Additional Information</h4>
                                        <p><strong>Living Arrangement:</strong> ${data.additional?.LivingArrangement || 'N/A'}</p>
                                        <p><strong>Financial Support:</strong> ${data.additional?.SourceofFinancial || 'N/A'}</p>
                                        <p><strong>Transportation:</strong> ${data.additional?.Transportation || 'N/A'}</p>
                                        <p><strong>Emergency Contact:</strong> ${data.additional?.EmergencyName || 'N/A'}</p>
                                        <p><strong>Emergency Address:</strong> ${data.additional?.EmergencyAddress || 'N/A'}</p>
                                        <p><strong>Emergency Number:</strong> ${data.additional?.EmergencyNumber || 'N/A'}</p>
                                        <h4>Marriage Information</h4>
                                        <p><strong>Spouse Name:</strong> ${data.marriage?.FirstName && data.marriage?.LastName ? data.marriage.FirstName + ' ' + data.marriage.LastName : 'N/A'}</p>
                                        <p><strong>Date of Birth:</strong> ${data.marriage?.DateOfBirth || 'N/A'}</p>
                                        <p><strong>Nationality:</strong> ${data.marriage?.Nationality || 'N/A'}</p>
                                        <p><strong>Number of Children:</strong> ${data.marriage?.NumberOfChildren ?? 'N/A'}</p>
                                        <p><strong>Date of Marriage:</strong> ${data.marriage?.DateOfMarriage || 'N/A'}</p>
                                    `;
                                }
                                document.getElementById('formModal').style.display = 'block';
                            })
                            .catch(error => {
                                document.getElementById('formDetails').innerHTML = `<p class="error">Error loading form details: ${error.message}</p>`;
                                document.getElementById('formModal').style.display = 'block';
                            });
                    }
                    function closeFormModal() {
                        document.getElementById('formModal').style.display = 'none';
                    }
                </script>

            <?php elseif ($page === 'testingReports'): ?>
                <h2>Testing Reports</h2>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Year</th>
                                <th>Course</th>
                                <th>Date</th>
                                <th>Test</th>
                                <th>Purpose</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($testingReports)): ?>
                                <tr><td colspan="7">No testing reports found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($testingReports as $report): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($report['student']); ?></td>
                                        <td><?php echo htmlspecialchars($report['year']); ?></td>
                                        <td><?php echo htmlspecialchars($report['course']); ?></td>
                                        <td><?php echo htmlspecialchars($report['date']); ?></td>
                                        <td><?php echo htmlspecialchars($report['test']); ?></td>
                                        <td><?php echo htmlspecialchars($report['purpose']); ?></td>
                                        <td><?php echo htmlspecialchars($report['result']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($page === 'guidanceReports'): ?>
                <h2>Guidance Reports</h2>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Year</th>
                                <th>Course</th>
                                <th>Date</th>
                                <th>Counselor</th>
                                <th>Purpose</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($guidanceReports)): ?>
                                <tr><td colspan="7">No guidance reports found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($guidanceReports as $report): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($report['student']); ?></td>
                                        <td><?php echo htmlspecialchars($report['year']); ?></td>
                                        <td><?php echo htmlspecialchars($report['course']); ?></td>
                                        <td><?php echo htmlspecialchars($report['date']); ?></td>
                                        <td><?php echo htmlspecialchars($report['counselor']); ?></td>
                                        <td><?php echo htmlspecialchars($report['purpose']); ?></td>
                                        <td><?php echo htmlspecialchars($report['remarks']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($page === 'userManagement'): ?>
                <h2>User Management</h2>
                <div style="margin-bottom: 20px;">
                    <button class="btn btn-register" onclick="openAddUserModal()">Add New User</button>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr><td colspan="4">No users found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['id']); ?></td>
                                        <td><?php echo htmlspecialchars($user['name']); ?></td>
                                        <td><?php echo htmlspecialchars($user['role']); ?></td>
                                        <td>
                                            <?php if ($user['id'] != $adminId): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" class="btn reject">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #4caf50;">Current User</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="addUserModal" class="modal">
                    <div class="modal-content">
                        <span class="close" onclick="closeAddUserModal()">&times;</span>
                        <h3>Add New User</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_user">
                            <div class="form-group">
                                <label for="name">Username</label>
                                <input type="text" name="name" required pattern="[a-zA-Z0-9_]{3,50}" title="Username must be 3-50 alphanumeric characters or underscores">
                            </div>
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" name="password" placeholder="Default: default123">
                            </div>
                            <div class="form-group">
                                <label for="role">Role</label>
                                <select name="role" required>
                                    <option value="Admin">Admin</option>
                                    <option value="Staff">Staff</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-register">Add User</button>
                        </form>
                    </div>
                </div>
                <script>
                    function openAddUserModal() {
                        document.getElementById('addUserModal').style.display = 'block';
                    }
                    function closeAddUserModal() {
                        document.getElementById('addUserModal').style.display = 'none';
                    }
                </script>

            <?php endif; ?>
        </div>
    </div>
</body>
</html>