<?php
session_start();
include '../db.php';

// Check if the user is logged in and has the appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['usertype'], ['Student', 'Faculty', 'Staff', 'Visitor'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$query = "SELECT b.title, br.issue_date, br.due_date, br.return_date, 
                 br.report_date, br.replacement_date, br.status,
                 a1.firstname AS issued_by_name, 
                 a2.firstname AS received_by_name
          FROM borrowings br 
          JOIN books b ON br.book_id = b.id 
          LEFT JOIN admins a1 ON br.issued_by = a1.id
          LEFT JOIN admins a2 ON br.recieved_by = a2.id
          WHERE br.user_id = ? AND br.status != 'Active'";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();

include 'inc/header.php';
?>

<head>
    <style>
        .dataTables_filter input {
            width: 400px; 
        }
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            margin-bottom: 1rem; 
        }
    </style>
</head>

<!-- Main Content -->
<div id="content" class="d-flex flex-column min-vh-100">
    <div class="container-fluid">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Borrowing History</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Return/Report Date</th>
                                <th>Status</th>
                                <th>Issued By</th>
                                <th>Received By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($row['issue_date'])); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($row['due_date'])); ?></td>
                                    <td>
                                        <?php 
                                        if ($row['status'] == 'Lost' || $row['status'] == 'Damaged') {
                                            echo 'Reported: ' . date('Y-m-d', strtotime($row['report_date']));
                                            if ($row['replacement_date']) {
                                                echo '<br>Replaced: ' . date('Y-m-d', strtotime($row['replacement_date']));
                                            }
                                        } else {
                                            echo 'Returned: ' . date('Y-m-d', strtotime($row['return_date']));
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                                    <td><?php echo htmlspecialchars($row['issued_by_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['received_by_name']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'inc/footer.php'; ?>

<script>
$(document).ready(function() {
    $('#dataTable').DataTable({
        "dom": "<'row mb-3'<'col-sm-6'l><'col-sm-6 d-flex justify-content-end'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row mt-3'<'col-sm-5'i><'col-sm-7 d-flex justify-content-end'p>>",
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search within results..."
        },
        "pageLength": 10,
        "order": [[1, 'desc']],
        "responsive": true,
        "initComplete": function() {
            $('#dataTable_filter input').addClass('form-control form-control-sm');
        }
    });
});
</script>