<?php
session_start();

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// Check if any staff member is logged in
if (isset($_SESSION['admin_id']) && isset($_SESSION['role']) && 
    in_array($_SESSION['role'], ['Admin', 'Librarian', 'Assistant', 'Encoder'])) {
    header("Location: dashboard.php");
    exit();
}

require '../db.php';

// Initialize error message
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = $_POST['employee_id'];
    $password = $_POST['password'];

    // Query to check for all valid staff roles
    $sql = "SELECT * FROM admins WHERE employee_id = ? AND role IN ('Admin', 'Librarian', 'Assistant', 'Encoder') AND (status != '0' OR status IS NULL)";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $admin = $result->fetch_assoc();
            
            if (password_verify($password, $admin['password'])) {
                // Set session variables
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_employee_id'] = $admin['employee_id'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_firstname'] = $admin['firstname'];
                $_SESSION['admin_lastname'] = $admin['lastname'];
                $_SESSION['admin_image'] = !empty($admin['image']) ? $admin['image'] : 'inc/img/default-avatar.jpg';
                $_SESSION['role'] = $admin['role'];
                $_SESSION['usertype'] = $admin['role'];
                $_SESSION['admin_date_added'] = $admin['date_added'];
                $_SESSION['admin_status'] = $admin['status'];
                $_SESSION['admin_last_update'] = $admin['last_update'];

                // Log the successful login with title and message
                $log_query = "INSERT INTO updates (user_id, role, title, message, `update`) VALUES (?, ?, ?, ?, NOW())";
                if ($log_stmt = $conn->prepare($log_query)) {
                    $login_title = "Admin Logged In";
                    $full_name = $admin['firstname'] . ' ' . $admin['lastname'];
                    
                    // Set appropriate status message based on admin status
                    if ($admin['status'] == '0') {
                        $login_status = $admin['role'] . " " . $full_name . " Logged In as Inactive";
                    } else if ($admin['status'] == '2') {
                        $login_status = $admin['role'] . " " . $full_name . " Logged In as Banned";
                    } else if ($admin['status'] == '3') {
                        $login_status = $admin['role'] . " " . $full_name . " Logged In as Disabled";
                    } else {
                        $login_status = $admin['role'] . " " . $full_name . " Logged In as Active";
                    }
                    
                    $log_stmt->bind_param("ssss", $admin['employee_id'], $admin['role'], $login_title, $login_status);
                    $log_stmt->execute();
                    $log_stmt->close();
                }

                // Direct header redirect
                header("Location: dashboard.php");
                exit();
            } else {
                $error_message = "Invalid credentials";
            }
        } else {
            $error_message = "Invalid credentials";
        }
        $stmt->close();
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Library System - User Login</title>

    <!-- Custom fonts for this template-->
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet" type="text/css">
    <link
        href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i"
        rel="stylesheet">

    <!-- Custom styles for this template-->
    <link href="inc/css/sb-admin-2.min.css" rel="stylesheet">

    <style>
        .bg-login-image {
    background: url('../Images/BG/bg-login.JPG') center center no-repeat;
    background-size: cover;
}
        
        /* Mobile-specific styles */
        @media (max-width: 768px) {
            .card {
                margin: 1rem !important;
            }
            .p-5 {
                padding: 2rem !important;
            }
            .my-5 {
                margin-top: 2rem !important;
                margin-bottom: 2rem !important;
            }
            /* Show background image on small screens too */
            .bg-login-mobile {
                min-height: 180px;
                background: url('../Images/BG/bg-login.JPG') center center no-repeat;
                background-size: cover;
                border-radius: 5px 5px 0 0;
            }
        }

        /* Centering the card */
        .row.justify-content-center {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh; /* Make sure the row takes at least the full viewport height */
        }
        
        /* Style for the entrance button */
        .library-entrance-btn {
            margin-top: 15px;
            background-color: #4e73df;
            border-color: #4e73df;
            color: white;
            transition: all 0.3s;
        }
        
        .library-entrance-btn:hover {
            background-color: #2e59d9;
            border-color: #2653d4;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
    </style>

</head>

<body class="bg-gradient-primary">

    <div class="container">

        <!-- Outer Row -->
        <div class="row justify-content-center">

            <div class="col-xl-10 col-lg-12 col-md-9">

                <div class="card o-hidden border-0 shadow-lg my-5">
                    <div class="card-body p-0">
                        <!-- Nested Row within Card Body -->

                        <div class="row">
                        <div class="col-lg-6 d-none d-lg-block bg-login-image"></div>
                            <!-- Mobile image that shows only on small screens -->
                            <div class="d-block d-lg-none w-100 bg-login-mobile"></div>
                            <div class="col-lg-6">
                                <div class="p-5">
                                    <div class="text-center">
                                        <h1 class="h4 text-gray-900 mb-4">Welcome Back!</h1>
                                    </div>
                                    <?php if(!empty($error_message) && $error_message !== "success"): ?>
                                        <div class="alert alert-danger">
                                            <?php echo $error_message; ?>
                                        </div>
                                    <?php endif; ?>
                                    <form class="user" method="POST" action="">
                                        <div class="form-group">
                                            <input type="text" class="form-control form-control-user"
                                            placeholder="ID"
                                            id="employee_id" name="employee_id" required>
                                        </div>
                                        <div class="form-group">
                                            <input type="password" class="form-control form-control-user"
                                                id="exampleInputPassword" placeholder="Password" name="password" required>
                                        </div>
                                        <div class="form-group">
                                            <div class="custom-control custom-checkbox small">
                                                <input type="checkbox" class="custom-control-input" id="customCheck">
                                                <label class="custom-control-label" for="customCheck">Remember Me</label>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-user btn-block">
                                            Login
                                        </button>
                                    </form>
                                    <hr>
                                    <div class="text-center">
                                        <a class="small" href="forgot_password.php">Forgot Password?</a>
                                    </div>
                                    
                                    <div class="text-center mt-3">
                                        <a href="library_entrance.php" class="btn btn-block library-entrance-btn">
                                            <i class="fas fa-door-open mr-2"></i>Library Access System
                                        </a>
                                    </div>
                                    
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- Bootstrap core JavaScript-->
    <script src="vendor/jquery/jquery.min.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

    <!-- Core plugin JavaScript-->
    <script src="vendor/jquery-easing/jquery.easing.min.js"></script>

    <!-- Custom scripts for all pages-->
    <script src="inc/js/sb-admin-2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        <?php if($error_message === "success"): ?>
            Swal.fire({
                title: 'Welcome Back!',
                text: 'Successfully logged in',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            }).then(function() {
                window.location.href = 'dashboard.php';
            });
        <?php endif; ?>
    </script>
</body>

</html>