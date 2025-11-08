<?php
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
    die("Database connection failed. Please contact the administrator.");
}

// Fetch admin/staff info
$stmt = $pdo->prepare("SELECT AccountID, UserName, Role FROM account WHERE UserName = ? AND IsActive = 1");
$stmt->execute([$_SESSION['username']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
$adminId = $admin ? $admin['AccountID'] : 0;
$adminName = $admin ? htmlspecialchars($admin['UserName']) : 'Unknown User';
$adminRole = $admin ? htmlspecialchars($admin['Role']) : 'Staff';

// Handle page navigation
$page = isset($_GET['page']) ? filter_var($_GET['page'], FILTER_SANITIZE_STRING) : 'dashboard';

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
$casesByYearLevel = $pdo->query("SELECT 
    s.YearLevel, 
    COUNT(*) as Count 
    FROM guidancerecord g 
    JOIN student s ON g.StudentID = s.StudentID 
    GROUP BY s.YearLevel")->fetchAll(PDO::FETCH_ASSOC) ?: 
    array_map(fn($level) => ['YearLevel' => $level, 'Count' => 0], ['1', '2', '3', '4']);

// Fetch students by year
$studentsByYear = $pdo->query("SELECT YearLevel, COUNT(*) as Count FROM student GROUP BY YearLevel")->fetchAll(PDO::FETCH_ASSOC) ?: 
    array_map(fn($level) => ['YearLevel' => $level, 'Count' => 0], ['1', '2', '3', '4']);

// Handle student search and filters
$search = ($page === 'students' || $page === 'forms') && isset($_GET['search']) ? filter_var($_GET['search'], FILTER_SANITIZE_STRING) : '';
$yearFilter = ($page === 'students' || $page === 'forms') && isset($_GET['year']) ? filter_var($_GET['year'], FILTER_SANITIZE_STRING) : '';
$courseFilter = ($page === 'students' || $page === 'forms') && isset($_GET['course']) ? filter_var($_GET['course'], FILTER_SANITIZE_STRING) : '';

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
                        WHERE s.Course != '' ORDER BY s.Course")->fetchAll(PDO::FETCH_COLUMN);
$years = $pdo->query("SELECT DISTINCT s.YearLevel 
                      FROM student s 
                      WHERE s.YearLevel != '' ORDER BY s.YearLevel")->fetchAll(PDO::FETCH_COLUMN);

// Fetch testing records with filter
$testingSearch = $page === 'testingReports' && isset($_GET['testing_search']) ? filter_var($_GET['testing_search'], FILTER_SANITIZE_STRING) : '';
$testingQuery = "SELECT s.StudentID, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as student, 
                 s.YearLevel as year, s.Course as course, t.DateTaken as date, t.TestName as test, 
                 t.Purpose as purpose, t.Result as result, t.TRID as id
                 FROM testingrecord t JOIN student s ON t.StudentID = s.StudentID WHERE 1=1";
if ($testingSearch) {
    $testingQuery .= " AND (s.LastName LIKE ? OR s.FirstName LIKE ? OR s.MiddleName LIKE ? OR t.TestName LIKE ? OR t.Purpose LIKE ? OR t.Result LIKE ?)";
    $testingParams = array_fill(0, 6, "%$testingSearch%");
    $stmt = $pdo->prepare($testingQuery);
    $stmt->execute($testingParams);
} else {
    $stmt = $pdo->prepare($testingQuery);
    $stmt->execute();
}
$testingReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch guidance records with filter
$guidanceSearch = $page === 'guidanceReports' && isset($_GET['guidance_search']) ? filter_var($_GET['guidance_search'], FILTER_SANITIZE_STRING) : '';
$guidanceQuery = "SELECT s.StudentID, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as student, 
                  s.YearLevel as year, s.Course as course, g.Date as date, a.UserName as counselor, 
                  g.Purpose as purpose, g.Remarks as remarks, g.GRID as id
                  FROM guidancerecord g 
                  JOIN student s ON g.StudentID = s.StudentID 
                  JOIN account a ON g.AccountID = a.AccountID WHERE 1=1";
if ($guidanceSearch) {
    $guidanceQuery .= " AND (s.LastName LIKE ? OR s.FirstName LIKE ? OR s.MiddleName LIKE ? OR g.Purpose LIKE ? OR g.Remarks LIKE ?)";
    $guidanceParams = array_fill(0, 5, "%$guidanceSearch%");
    $stmt = $pdo->prepare($guidanceQuery);
    $stmt->execute($guidanceParams);
} else {
    $stmt = $pdo->prepare($guidanceQuery);
    $stmt->execute();
}
$guidanceReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all forms (pending and approved)
$formQuery = "SELECT f.FormID, s.StudentID, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as student, 
              f.Approvedate, f.ApproveStat, f.CivilStatus, f.Contactnum, f.Email, f.Address, 
              f.Nationality, f.Religion 
              FROM form f JOIN student s ON f.StudentID = s.StudentID";
$stmt = $pdo->prepare($formQuery);
$stmt->execute();
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $error_message = '';
    if (in_array($_POST['action'], ['approve_form', 'reject_form', 'undo_form_approval'])) {
        $formId = filter_var($_POST['form_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($formId === false || $formId <= 0) {
            $error_message = "Invalid form ID.";
            error_log("Validation failed: $error_message");
            echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
        } else {
            try {
                $pdo->beginTransaction();
                if ($_POST['action'] === 'approve_form') {
                    $stmt = $pdo->prepare("UPDATE form SET ApproveStat = 1, Approvedate = ? WHERE FormID = ?");
                    $stmt->execute([date('Y-m-d'), $formId]);
                    error_log("Approved form: formId=$formId");
                } elseif ($_POST['action'] === 'reject_form') {
                    $stmt = $pdo->prepare("SELECT StudentID FROM form WHERE FormID = ?");
                    $stmt->execute([$formId]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($student) {
                        $stmt = $pdo->prepare("DELETE FROM form WHERE FormID = ?");
                        $stmt->execute([$formId]);
                        $_SESSION['form_rejected_' . $student['StudentID']] = true;
                        error_log("Rejected form: formId=$formId");
                    } else {
                        $error_message = "Form not found.";
                        error_log("Validation failed: $error_message");
                        echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
                    }
                } elseif ($_POST['action'] === 'undo_form_approval') {
                    $stmt = $pdo->prepare("UPDATE form SET ApproveStat = 0, Approvedate = NULL WHERE FormID = ?");
                    $stmt->execute([$formId]);
                    error_log("Undid form approval: formId=$formId");
                }
                $pdo->commit();
                echo "<script>console.log('Form action completed successfully'); window.location.href='Staff.php?page=pendingForms';</script>";
                exit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error_message = "Database error: " . $e->getMessage();
                error_log("Database error: " . $e->getMessage());
                echo "<script>console.log('PHP database error: " . addslashes($e->getMessage()) . "'); alert('Failed to process form: " . addslashes($e->getMessage()) . "');</script>";
            }
        }
    }
    if ($_POST['action'] === 'add_testing' || $_POST['action'] === 'edit_testing') {
        $studentId = filter_var($_POST['studentId'] ?? 0, FILTER_VALIDATE_INT);
        $test = filter_var($_POST['test'] ?? '', FILTER_SANITIZE_STRING);
        $purpose = filter_var($_POST['purpose'] ?? '', FILTER_SANITIZE_STRING);
        $result = filter_var($_POST['result'] ?? '', FILTER_SANITIZE_STRING);
        $date = filter_var($_POST['date'] ?? '', FILTER_SANITIZE_STRING);
        error_log("Processing testing record: studentId=$studentId, test=$test, purpose=$purpose, result=$result, date=$date");
        if ($studentId <= 0 || empty($test) || empty($purpose) || empty($result) || empty($date)) {
            $error_message = "All fields are required for testing records.";
            error_log("Validation failed: $error_message");
            echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
        } else {
            // Check if studentId exists in student table
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM student WHERE StudentID = ?");
            $stmt->execute([$studentId]);
            if ($stmt->fetchColumn() == 0) {
                $error_message = "Invalid Student ID: Student does not exist.";
                error_log("Validation failed: $error_message");
                echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
            } else {
                try {
                    $pdo->beginTransaction();
                    if ($_POST['action'] === 'add_testing') {
                        $stmt = $pdo->prepare("INSERT INTO testingrecord (StudentID, TestName, Purpose, Result, DateTaken) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$studentId, $test, $purpose, $result, $date]);
                        error_log("Inserted testing record: studentId=$studentId");
                    } else {
                        $trid = filter_var($_POST['trid'] ?? 0, FILTER_VALIDATE_INT);
                        if ($trid <= 0) {
                            $error_message = "Invalid testing record ID.";
                            error_log("Validation failed: $error_message");
                            echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
                        } else {
                            $stmt = $pdo->prepare("UPDATE testingrecord SET StudentID = ?, TestName = ?, Purpose = ?, Result = ?, DateTaken = ? WHERE TRID = ?");
                            $stmt->execute([$studentId, $test, $purpose, $result, $date, $trid]);
                            error_log("Updated testing record: trid=$trid");
                        }
                    }
                    $pdo->commit();
                    echo "<script>console.log('Form submitted successfully'); alert('Record saved successfully!'); window.location.href='Staff.php?page=testingReports';</script>";
                    exit();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error_message = "Database error: " . $e->getMessage();
                    error_log("Database error: " . $e->getMessage());
                    echo "<script>console.log('PHP database error: " . addslashes($e->getMessage()) . "'); alert('Failed to save record: " . addslashes($e->getMessage()) . "');</script>";
                }
            }
        }
    } elseif ($_POST['action'] === 'add_guidance' || $_POST['action'] === 'edit_guidance') {
        $studentId = filter_var($_POST['studentId'] ?? 0, FILTER_VALIDATE_INT);
        $purpose = filter_var($_POST['purpose'] ?? '', FILTER_SANITIZE_STRING);
        $remarks = filter_var($_POST['remarks'] ?? '', FILTER_SANITIZE_STRING);
        $date = filter_var($_POST['date'] ?? '', FILTER_SANITIZE_STRING);
        error_log("Processing guidance record: studentId=$studentId, purpose=$purpose, remarks=$remarks, date=$date, adminId=$adminId");
        if ($studentId <= 0 || empty($purpose) || empty($remarks) || empty($date)) {
            $error_message = "All fields are required for guidance records.";
            error_log("Validation failed: $error_message");
            echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
        } else {
            // Check if studentId exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM student WHERE StudentID = ?");
            $stmt->execute([$studentId]);
            if ($stmt->fetchColumn() == 0) {
                $error_message = "Invalid Student ID: Student does not exist.";
                error_log("Validation failed: $error_message");
                echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
            } else {
                try {
                    $pdo->beginTransaction();
                    if ($_POST['action'] === 'add_guidance') {
                        $stmt = $pdo->prepare("INSERT INTO guidancerecord (StudentID, AccountID, Purpose, Remarks, Date) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$studentId, $adminId, $purpose, $remarks, $date]);
                        error_log("Inserted guidance record: studentId=$studentId");
                    } else {
                        $grid = filter_var($_POST['grid'] ?? 0, FILTER_VALIDATE_INT);
                        if ($grid <= 0) {
                            $error_message = "Invalid guidance record ID.";
                            error_log("Validation failed: $error_message");
                            echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
                        } else {
                            $stmt = $pdo->prepare("UPDATE guidancerecord SET StudentID = ?, Purpose = ?, Remarks = ?, Date = ? WHERE GRID = ?");
                            $stmt->execute([$studentId, $purpose, $remarks, $date, $grid]);
                            error_log("Updated guidance record: grid=$grid");
                        }
                    }
                    $pdo->commit();
                    echo "<script>console.log('Form submitted successfully'); alert('Record saved successfully!'); window.location.href='Staff.php?page=guidanceReports';</script>";
                    exit();
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error_message = "Database error: " . $e->getMessage();
                    error_log("Database error: " . $e->getMessage());
                    echo "<script>console.log('PHP database error: " . addslashes($e->getMessage()) . "'); alert('Failed to save record: " . addslashes($e->getMessage()) . "');</script>";
                }
            }
        }
    } elseif ($_POST['action'] === 'delete_testing') {
        $trid = filter_var($_POST['trid'] ?? 0, FILTER_VALIDATE_INT);
        if ($trid <= 0) {
            $error_message = "Invalid testing record ID.";
            error_log("Validation failed: $error_message");
            echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM testingrecord WHERE TRID = ?");
                $stmt->execute([$trid]);
                error_log("Deleted testing record: trid=$trid");
                echo "<script>console.log('Testing record deleted successfully'); window.location.href='Staff.php?page=testingReports';</script>";
                exit();
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
                error_log("Database error: " . $e->getMessage());
                echo "<script>console.log('PHP database error: " . addslashes($e->getMessage()) . "'); alert('Failed to delete record: " . addslashes($e->getMessage()) . "');</script>";
            }
        }
    } elseif ($_POST['action'] === 'delete_guidance') {
        $grid = filter_var($_POST['grid'] ?? 0, FILTER_VALIDATE_INT);
        if ($grid <= 0) {
            $error_message = "Invalid guidance record ID.";
            error_log("Validation failed: $error_message");
            echo "<script>console.log('PHP validation error: $error_message'); alert('$error_message');</script>";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM guidancerecord WHERE GRID = ?");
                $stmt->execute([$grid]);
                error_log("Deleted guidance record: grid=$grid");
                echo "<script>console.log('Guidance record deleted successfully'); window.location.href='Staff.php?page=guidanceReports';</script>";
                exit();
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
                error_log("Database error: " . $e->getMessage());
                echo "<script>console.log('PHP database error: " . addslashes($e->getMessage()) . "'); alert('Failed to delete record: " . addslashes($e->getMessage()) . "');</script>";
            }
        }
    }
    if ($error_message) {
        echo "<script>console.log('PHP error: $error_message'); alert('$error_message'); hideAllModals();</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap">
    <link rel="stylesheet" href="dash.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .tab-btn {
            padding: 10px 20px;
            background: #ccc;
            border: none;
            cursor: pointer;
            border-radius: 5px;
        }
        .tab-btn.active {
            background: #1c9f3f;
            color: #fff;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .reject {
            background: #ff0000;
        }
        .reject:hover {
            background: #cc0000;
        }
        .edit-btn {
            background: #ffa500;
            color: #fff;
        }
        .edit-btn:hover {
            background: #cc8400;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-content {
            background-color: #fff;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 5px;
            width: 80%;
            max-width: 500px;
            margin: auto;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            position: relative;
        }
        .close {
            position: absolute;
            right: 20px;
            top: 10px;
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover,
        .close:focus {
            color: #000;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="account">
            <img src="https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png" alt="Profile">
            <div class="acc-inf">
                <h3><?php echo $adminName; ?></h3>
                <p><?php echo $adminRole; ?></p>
            </div>
        </div>
        <div class="sideopt">
            <a href="?page=dashboard" <?php echo $page === 'dashboard' ? 'class="active"' : ''; ?> onclick="hideAllModals()">Dashboard</a>
            <a href="?page=students" <?php echo $page === 'students' ? 'class="active"' : ''; ?> onclick="hideAllModals()">Students</a>
            <a href="?page=pendingForms" <?php echo $page === 'pendingForms' ? 'class="active"' : ''; ?> onclick="hideAllModals()">Forms</a>
            <a href="?page=testingReports" <?php echo $page === 'testingReports' ? 'class="active"' : ''; ?> onclick="hideAllModals()">Testing Reports</a>
            <a href="?page=guidanceReports" <?php echo $page === 'guidanceReports' ? 'class="active"' : ''; ?> onclick="hideAllModals()">Guidance Reports</a>
        </div>
        <a href="?action=logout" class="logout" onclick="hideAllModals()">Logout</a>
    </div>

    <div class="Topbar">
        <h2>Guidance Office Staff Dashboard</h2>
    </div>

    <div class="contentbg">
        <div class="content">
            <?php if ($page === 'dashboard'): ?>
                <h2>Dashboard Overview</h2>
                <div class="cards">
                    <div class="card">
                        <h3>Students with Forms</h3>
                        <p><?php echo $studentsCount; ?></p>
                    </div>
                    <div class="card">
                        <h3>Guidance Records</h3>
                        <p><?php echo $guidanceCount; ?></p>
                    </div>
                    <div class="card">
                        <h3>Pending Forms</h3>
                        <p><?php echo $pendingCount; ?></p>
                    </div>
                </div>
                <div class="section-grid">
                    <div class="panel">
                        <h3>Cases by Year Level</h3>
                        <canvas id="yearLevelChart"></canvas>
                    </div>
                    <div class="panel">
                        <h3>Students by Year</h3>
                        <canvas id="yearChart"></canvas>
                    </div>
                </div>
                <script>
                    const yearLevelCtx = document.getElementById('yearLevelChart').getContext('2d');
                    new Chart(yearLevelCtx, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode(array_column($casesByYearLevel, 'YearLevel')); ?>,
                            datasets: [{ label: 'Cases', data: <?php echo json_encode(array_column($casesByYearLevel, 'Count')); ?>, backgroundColor: '#1c9f3f' }]
                        },
                        options: { scales: { y: { beginAtZero: true } } }
                    });

                    const yearCtx = document.getElementById('yearChart').getContext('2d');
                    new Chart(yearCtx, {
                        type: 'pie',
                        data: {
                            labels: <?php echo json_encode(array_column($studentsByYear, 'YearLevel')); ?>,
                            datasets: [{ data: <?php echo json_encode(array_column($studentsByYear, 'Count')); ?>, backgroundColor: ['#1c9f3f', '#147730', '#0a5c1a', '#2ecc71'] }]
                        }
                    });
                </script>
                <script>hideAllModals();</script>

            <?php elseif ($page === 'students'): ?>
                <h2>Students</h2>
                <form class="filter-form" method="GET">
                    <input type="hidden" name="page" value="students">
                    <div class="form-group">
                        <label for="search">Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name...">
                    </div>
                    <div class="form-group">
                        <label for="year">Year Level</label>
                        <select name="year">
                            <option value="">All</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $yearFilter === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="course">Course</label>
                        <select name="course">
                            <option value="">All</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?php echo htmlspecialchars($course); ?>" <?php echo $courseFilter === $course ? 'selected' : ''; ?>><?php echo htmlspecialchars($course); ?></option>
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
                                        <td><?php echo $student['form_status'] !== null ? ($student['form_status'] ? 'Approved' : 'Pending') : 'No Form'; ?></td>
                                        <td>
                                            <button class="btn" onclick="openStudentModal(<?php echo $student['id']; ?>)">View</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="studentModal" class="modal">
                    <div class="modal-content">
                        <span class="close" onclick="closeModal('studentModal')">&times;</span>
                        <h3>Student Details</h3>
                        <div id="studentDetails"></div>
                    </div>
                </div>
                <script>hideAllModals();</script>

            <?php elseif ($page === 'pendingForms'): ?>
                <h2>Forms</h2>
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
                                                    <input type="hidden" name="action" value="approve_form">
                                                    <input type="hidden" name="form_id" value="<?php echo $form['FormID']; ?>">
                                                    <button type="submit" class="btn">Approve</button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="reject_form">
                                                    <input type="hidden" name="form_id" value="<?php echo $form['FormID']; ?>">
                                                    <button type="submit" class="btn reject">Reject</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="undo_form_approval">
                                                    <input type="hidden" name="form_id" value="<?php echo $form['FormID']; ?>">
                                                    <button type="submit" class="btn">Undo</button>
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
                        <span class="close" onclick="closeModal('formModal')">&times;</span>
                        <h3>Form Details</h3>
                        <div id="formDetails"></div>
                    </div>
                </div>
                <script>hideAllModals();</script>

            <?php elseif ($page === 'testingReports'): ?>
                <h2>Testing Reports</h2>
                <button class="btn" onclick="openReportModal('add_testing')">Add Testing Record</button>
                <form method="GET" class="filter-form">
                    <input type="hidden" name="page" value="testingReports">
                    <div class="form-group">
                        <label for="testing_search">Search</label>
                        <input type="text" name="testing_search" value="<?php echo htmlspecialchars($testingSearch); ?>" placeholder="Search testing reports...">
                    </div>
                    <button type="submit" class="btn">Search</button>
                </form>
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($testingReports)): ?>
                                <tr><td colspan="8">No testing reports found.</td></tr>
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
                                        <td>
                                            <button class="btn edit-btn" onclick="openReportModal('edit_testing', <?php echo $report['id']; ?>)">Edit</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this testing record?');">
                                                <input type="hidden" name="action" value="delete_testing">
                                                <input type="hidden" name="trid" value="<?php echo $report['id']; ?>">
                                                <button type="submit" class="btn reject">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <script>hideAllModals();</script>

            <?php elseif ($page === 'guidanceReports'): ?>
                <h2>Guidance Reports</h2>
                <button class="btn" onclick="openReportModal('add_guidance')">Add Guidance Record</button>
                <form method="GET" class="filter-form">
                    <input type="hidden" name="page" value="guidanceReports">
                    <div class="form-group">
                        <label for="guidance_search">Search</label>
                        <input type="text" name="guidance_search" value="<?php echo htmlspecialchars($guidanceSearch); ?>" placeholder="Search guidance reports...">
                    </div>
                    <button type="submit" class="btn">Search</button>
                </form>
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($guidanceReports)): ?>
                                <tr><td colspan="8">No guidance reports found.</td></tr>
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
                                        <td>
                                            <button class="btn edit-btn" onclick="openReportModal('edit_guidance', <?php echo $report['id']; ?>)">Edit</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this guidance record?');">
                                                <input type="hidden" name="action" value="delete_guidance">
                                                <input type="hidden" name="grid" value="<?php echo $report['id']; ?>">
                                                <button type="submit" class="btn reject">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <script>hideAllModals();</script>

            <?php elseif ($page === 'getStudentDetails' && isset($_GET['studentId'])): ?>
                <?php
                ob_clean();
                try {
                    $studentId = filter_var($_GET['studentId'], FILTER_VALIDATE_INT);
                    if ($studentId === false || $studentId <= 0) {
                        throw new Exception('Invalid student ID.');
                    }

                    $stmt = $pdo->prepare("SELECT s.StudentID, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as name, 
                                           s.YearLevel, s.Course, a.UserName as email 
                                           FROM student s JOIN account a ON s.AccountID = a.AccountID 
                                           WHERE s.StudentID = ?");
                    $stmt->execute([$studentId]);
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$student) {
                        throw new Exception('Student not found.');
                    }

                    $stmt = $pdo->prepare("SELECT f.*, ai.ProfilePicture 
                                           FROM form f 
                                           LEFT JOIN additionalinformation ai ON f.AID = ai.AID 
                                           WHERE f.StudentID = ?");
                    $stmt->execute([$studentId]);
                    $form = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($form) {
                        $student['profilePicture'] = $form['ProfilePicture'] && file_exists($form['ProfilePicture']) ? $form['ProfilePicture'] : null;
                        $stmt = $pdo->prepare("SELECT * FROM additionalinformation WHERE AID = ?");
                        $stmt->execute([$form['AID']]);
                        $student['additional'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $stmt = $pdo->prepare("SELECT * FROM marriageinformation WHERE MarriageID = ?");
                        $stmt->execute([$form['MarriageID']]);
                        $student['marriage'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $student['form'] = $form;
                    } else {
                        $student['form'] = [];
                        $student['additional'] = [];
                        $student['marriage'] = [];
                        $student['profilePicture'] = null;
                    }

                    header('Content-Type: application/json');
                    echo json_encode($student);
                } catch (Exception $e) {
                    header('Content-Type: application/json', true, 400);
                    echo json_encode(['error' => $e->getMessage()]);
                    echo "<script>console.log('PHP error: " . addslashes($e->getMessage()) . "');</script>";
                }
                exit();
                ?>

            <?php elseif ($page === 'getFormDetails' && isset($_GET['formId'])): ?>
                <?php
                ob_clean();
                try {
                    $formId = filter_var($_GET['formId'], FILTER_VALIDATE_INT);
                    if ($formId === false || $formId <= 0) {
                        throw new Exception('Invalid form ID.');
                    }
                    $stmt = $pdo->prepare("SELECT f.*, s.StudentID, CONCAT(s.LastName, ', ', s.FirstName, ' ', s.MiddleName) as student, 
                                           ai.ProfilePicture 
                                           FROM form f 
                                           JOIN student s ON f.StudentID = s.StudentID 
                                           LEFT JOIN additionalinformation ai ON f.AID = ai.AID 
                                           WHERE f.FormID = ?");
                    $stmt->execute([$formId]);
                    $form = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$form) {
                        throw new Exception('Form not found.');
                    }
                    $form['profilePicture'] = $form['ProfilePicture'] && file_exists($form['ProfilePicture']) ? $form['ProfilePicture'] : null;
                    $stmt = $pdo->prepare("SELECT * FROM additionalinformation WHERE AID = ?");
                    $stmt->execute([$form['AID']]);
                    $form['additional'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $stmt = $pdo->prepare("SELECT * FROM marriageinformation WHERE MarriageID = ?");
                    $stmt->execute([$form['MarriageID']]);
                    $form['marriage'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    header('Content-Type: application/json');
                    echo json_encode($form);
                } catch (Exception $e) {
                    header('Content-Type: application/json', true, 400);
                    echo json_encode(['error' => $e->getMessage()]);
                    echo "<script>console.log('PHP error: " . addslashes($e->getMessage()) . "');</script>";
                }
                exit();
                ?>

            <?php elseif ($page === 'getTestingDetails' && isset($_GET['trid'])): ?>
                <?php
                ob_clean();
                try {
                    $trid = filter_var($_GET['trid'], FILTER_VALIDATE_INT);
                    if ($trid === false || $trid <= 0) {
                        throw new Exception('Invalid testing record ID.');
                    }
                    $stmt = $pdo->prepare("SELECT * FROM testingrecord WHERE TRID = ?");
                    $stmt->execute([$trid]);
                    $record = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$record) {
                        throw new Exception('Testing record not found.');
                    }
                    header('Content-Type: application/json');
                    echo json_encode($record);
                } catch (Exception $e) {
                    header('Content-Type: application/json', true, 400);
                    echo json_encode(['error' => $e->getMessage()]);
                    echo "<script>console.log('PHP error: " . addslashes($e->getMessage()) . "');</script>";
                }
                exit();
                ?>

            <?php elseif ($page === 'getGuidanceDetails' && isset($_GET['grid'])): ?>
                <?php
                ob_clean();
                try {
                    $grid = filter_var($_GET['grid'], FILTER_VALIDATE_INT);
                    if ($grid === false || $grid <= 0) {
                        throw new Exception('Invalid guidance record ID.');
                    }
                    $stmt = $pdo->prepare("SELECT * FROM guidancerecord WHERE GRID = ?");
                    $stmt->execute([$grid]);
                    $record = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$record) {
                        throw new Exception('Guidance record not found.');
                    }
                    header('Content-Type: application/json');
                    echo json_encode($record);
                } catch (Exception $e) {
                    header('Content-Type: application/json', true, 400);
                    echo json_encode(['error' => $e->getMessage()]);
                    echo "<script>console.log('PHP error: " . addslashes($e->getMessage()) . "');</script>";
                }
                exit();
                ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="reportModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('reportModal')">&times;</span>
            <h3 id="modalTitle">Add Record</h3>
            <form id="reportForm" method="POST" action="Staff.php" onsubmit="return validateReportForm()">
                <input type="hidden" name="action" id="reportAction">
                <input type="hidden" name="trid" id="trid">
                <input type="hidden" name="grid" id="grid">
                <div class="form-group">
                    <label for="studentId">Student</label>
                    <select name="studentId" id="studentId" required>
                        <option value="">Select Student</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="studentIdError" class="error" style="color: #ff0000; font-size: 0.9em; margin-top: 5px; display: none;">Please select a student.</div>
                </div>
                <div class="form-group">
                    <label for="date">Date</label>
                    <input type="date" name="date" id="date" required max="<?php echo date('Y-m-d'); ?>">
                    <div id="dateError" class="error" style="color: #ff0000; font-size: 0.9em; margin-top: 5px; display: none;">Please select a valid date.</div>
                </div>
                <div id="testingFields" style="display:none;">
                    <div class="form-group">
                        <label for="test">Test Name</label>
                        <input type="text" name="test" id="test">
                        <div id="testError" class="error" style="color: #ff0000; font-size: 0.9em; margin-top: 5px; display: none;">Test name is required.</div>
                    </div>
                    <div class="form-group">
                        <label for="result">Result</label>
                        <input type="text" name="result" id="result">
                        <div id="resultError" class="error" style="color: #ff0000; font-size: 0.9em; margin-top: 5px; display: none;">Result is required.</div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="purpose">Purpose</label>
                    <input type="text" name="purpose" id="purpose" required>
                    <div id="purposeError" class="error" style="color: #ff0000; font-size: 0.9em; margin-top: 5px; display: none;">Purpose is required.</div>
                </div>
                <div id="guidanceFields" style="display:none;">
                    <div class="form-group">
                        <label for="remarks">Remarks</label>
                        <textarea name="remarks" id="remarks" rows="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:5px;"></textarea>
                        <div id="remarksError" class="error" style="color: #ff0000; font-size: 0.9em; margin-top: 5px; display: none;">Remarks are required.</div>
                    </div>
                </div>
                <button type="submit" class="btn">Submit</button>
            </form>
        </div>
    </div>

    <script>
        // Function to hide all modals and reset report form
        function hideAllModals() {
            console.log('Hiding all modals');
            const modals = ['studentModal', 'formModal', 'reportModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'none';
                    console.log(`Modal state change: ${modalId} set to display=none`);
                }
            });
            setTimeout(() => {
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (modal) {
                        modal.style.display = 'none';
                        console.log(`Delayed modal state change: ${modalId} set to display=none`);
                    }
                });
            }, 200);
            const reportForm = document.getElementById('reportForm');
            if (reportForm) {
                reportForm.reset();
            }
            document.querySelectorAll('.error').forEach(el => el.style.display = 'none');
        }

        // Hide modals on DOM load
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, hiding modals');
            hideAllModals();
        });

        // Check for unexpected modal openings on load
        window.addEventListener('load', function() {
            const modals = ['studentModal', 'formModal', 'reportModal'];
            modals.forEach(modalId => {
                const modal = document.g
etElementById(modalId);
                if (modal && modal.style.display === 'block') {
                    console.log(`Unexpected modal open detected on load: ${modalId}`);
                    modal.style.display = 'none';
                    console.log(`Modal state change: ${modalId} set to display=none`);
                }
            });
        });

        function openReportModal(action, id = null) {
            console.log(`Opening report modal: action=${action}, id=${id}`);
            const isTesting = action.includes('testing');
            const isAdd = action.includes('add');
            document.getElementById('modalTitle').textContent = `${isAdd ? 'Add' : 'Edit'} ${isTesting ? 'Testing' : 'Guidance'} Record`;
            document.getElementById('reportAction').value = action;
            document.getElementById('testingFields').style.display = isTesting ? 'block' : 'none';
            document.getElementById('guidanceFields').style.display = isTesting ? 'none' : 'block';
            document.getElementById('reportForm').reset();
            document.querySelectorAll('.error').forEach(el => el.style.display = 'none');
            if (!isAdd && id) {
                fetch(`Staff.php?page=get${isTesting ? 'Testing' : 'Guidance'}Details&${isTesting ? 'trid' : 'grid'}=${id}`)
                    .then(res => {
                        console.log(`Fetch response status for ${isTesting ? 'Testing' : 'Guidance'}Details: ${res.status}`);
                        return res.json();
                    })
                    .then(data => {
                        if (data.error) {
                            console.log(`Error fetching record details: ${data.error}`);
                            alert(data.error);
                            return;
                        }
                        console.log(`Fetched record details:`, data);
                        document.getElementById('studentId').value = data.StudentID || '';
                        document.getElementById('date').value = data.DateTaken || data.Date || '';
                        document.getElementById('purpose').value = data.Purpose || '';
                        if (isTesting) {
                            document.getElementById('test').value = data.TestName || '';
                            document.getElementById('result').value = data.Result || '';
                            document.getElementById('trid').value = id;
                        } else {
                            document.getElementById('remarks').value = data.Remarks || '';
                            document.getElementById('grid').value = id;
                        }
                        document.getElementById('reportModal').style.display = 'block';
                        console.log(`Modal state change: reportModal set to display=block`);
                    })
                    .catch(err => {
                        console.log(`Fetch error: ${err.message}`);
                        alert('Failed to load record details.');
                    });
            } else {
                document.getElementById('reportModal').style.display = 'block';
                console.log(`Modal state change: reportModal set to display=block`);
            }
        }

        function closeModal(modalId) {
            console.log(`Closing modal: ${modalId}`);
            document.getElementById(modalId).style.display = 'none';
            console.log(`Modal state change: ${modalId} set to display=none`);
            document.getElementById('reportForm').reset();
            document.querySelectorAll('.error').forEach(el => el.style.display = 'none');
        }

        function validateReportForm() {
            console.log('Starting form validation');
            let isValid = true;
            const action = document.getElementById('reportAction').value;
            const isTesting = action.includes('testing');
            const fields = {
                studentId: document.getElementById('studentId')?.value?.trim(),
                date: document.getElementById('date')?.value?.trim(),
                purpose: document.getElementById('purpose')?.value?.trim(),
                test: isTesting ? document.getElementById('test')?.value?.trim() : null,
                result: isTesting ? document.getElementById('result')?.value?.trim() : null,
                remarks: !isTesting ? document.getElementById('remarks')?.value?.trim() : null
            };
            console.log('Form fields:', fields);
            document.querySelectorAll('.error').forEach(el => el.style.display = 'none');
            if (!fields.studentId) {
                document.getElementById('studentIdError').style.display = 'block';
                console.log('Validation failed: studentId is empty or invalid');
                isValid = false;
            }
            if (!fields.date || new Date(fields.date) > new Date()) {
                document.getElementById('dateError').style.display = 'block';
                console.log('Validation failed: date is empty or in the future');
                isValid = false;
            }
            if (!fields.purpose) {
                document.getElementById('purposeError').style.display = 'block';
                console.log('Validation failed: purpose is empty');
                isValid = false;
            }
            if (isTesting) {
                if (!fields.test) {
                    document.getElementById('testError').style.display = 'block';
                    console.log('Validation failed: test is empty');
                    isValid = false;
                }
                if (!fields.result) {
                    document.getElementById('resultError').style.display = 'block';
                    console.log('Validation failed: result is empty');
                    isValid = false;
                }
            } else {
                if (!fields.remarks) {
                    document.getElementById('remarksError').style.display = 'block';
                    console.log('Validation failed: remarks is empty');
                    isValid = false;
                }
            }
            console.log(`Validation result: ${isValid}`);
            if (isValid) {
                console.log('Form validation passed, submitting form');
                alert('Submitting form...');
            } else {
                console.log('Form validation failed, preventing submission');
            }
            return isValid;
        }

        function openStudentModal(id) {
            console.log(`Opening student modal: id=${id}`);
            fetch(`Staff.php?page=getStudentDetails&studentId=${id}`)
                .then(res => {
                    console.log(`Fetch response status for StudentDetails: ${res.status}`);
                    return res.json();
                })
                .then(data => {
                    if (data.error) {
                        console.log(`Error fetching student details: ${data.error}`);
                        alert(data.error);
                        return;
                    }
                    console.log(`Fetched student details:`, data);
                    let html = `
                        <img src="${data.profilePicture || 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'}" alt="Profile Picture" style="width:80px; height:80px; border-radius:50%; border:3px solid #4caf50; margin-bottom:10px; display: block; margin-left: auto; margin-right: auto;">
                        <p><strong>Name:</strong> ${data.name || 'N/A'}</p>
                        <p><strong>Year Level:</strong> ${data.YearLevel || 'N/A'}</p>
                        <p><strong>Course:</strong> ${data.Course || 'N/A'}</p>
                        <p><strong>Email:</strong> ${data.email || 'N/A'}</p>
                        <h4>Form Information</h4>
                        <p><strong>Civil Status:</strong> ${data.form?.CivilStatus || 'N/A'}</p>
                        <p><strong>Contact:</strong> ${data.form?.Contactnum || 'N/A'}</p>
                        <p><strong>Address:</strong> ${data.form?.Address || 'N/A'}</p>
                        <p><strong>Nationality:</strong> ${data.form?.Nationality || 'N/A'}</p>
                        <p><strong>Religion:</strong> ${data.form?.Religion || 'N/A'}</p>
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
                    document.getElementById('studentDetails').innerHTML = html;
                    document.getElementById('studentModal').style.display = 'block';
                    console.log(`Modal state change: studentModal set to display=block`);
                })
                .catch(err => {
                    console.log(`Fetch error: ${err.message}`);
                    alert('Failed to load student details.');
                });
        }

        function openFormModal(id) {
            console.log(`Opening form modal: id=${id}`);
            fetch(`Staff.php?page=getFormDetails&formId=${id}`)
                .then(res => {
                    console.log(`Fetch response status for FormDetails: ${res.status}`);
                    return res.json();
                })
                .then(data => {
                    if (data.error) {
                        console.log(`Error fetching form details: ${data.error}`);
                        alert(data.error);
                        return;
                    }
                    console.log(`Fetched form details:`, data);
                    let html = `
                        <img src="${data.profilePicture || 'https://cdn.pixabay.com/photo/2015/10/05/22/37/blank-profile-picture-973460_960_720.png'}" alt="Profile Picture" style="width:80px; height:80px; border-radius:50%; border:3px solid #4caf50; margin-bottom:10px; display: block; margin-left: auto; margin-right: auto;">
                        <p><strong>Form ID:</strong> ${data.FormID || 'N/A'}</p>
                        <p><strong>Student:</strong> ${data.student || 'N/A'}</p>
                        <p><strong>Civil Status:</strong> ${data.CivilStatus || 'N/A'}</p>
                        <p><strong>Contact:</strong> ${data.Contactnum || 'N/A'}</p>
                        <p><strong>Email:</strong> ${data.Email || 'N/A'}</p>
                        <p><strong>Address:</strong> ${data.Address || 'N/A'}</p>
                        <p><strong>Nationality:</strong> ${data.Nationality || 'N/A'}</p>
                        <p><strong>Religion:</strong> ${data.Religion || 'N/A'}</p>
                        <p><strong>Approval Status:</strong> ${data.ApproveStat ? 'Approved' : 'Pending'}</p>
                        <p><strong>Approval Date:</strong> ${data.Approvedate || 'N/A'}</p>
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
                    document.getElementById('formDetails').innerHTML = html;
                    document.getElementById('formModal').style.display = 'block';
                    console.log(`Modal state change: formModal set to display=block`);
                })
                .catch(err => {
                    console.log(`Fetch error: ${err.message}`);
                    alert('Failed to load form details.');
                });
        }
    </script>
</body>
</html>