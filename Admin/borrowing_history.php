<?php
session_start();
include('inc/header.php');

// Check if the user is logged in and has appropriate role
if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['role'], ['Admin', 'Librarian'])) {
    header('Location: login.php');
    exit();
}

include('../db.php');
$query = "SELECT 
            b.id as borrow_id,
            b.borrow_date,
            b.due_date,
            b.return_date,
            b.report_date,
            b.replacement_date,
            b.status,
            bk.title as book_title,
            bk.accession,
            CONCAT(u.firstname, ' ', u.lastname) as borrower_name
          FROM borrowings b
          JOIN books bk ON b.book_id = bk.id
          JOIN users u ON b.user_id = u.id
          WHERE b.status != 'Active' AND b.status != 'Over Due'
          ORDER BY b.borrow_date DESC";
$result = $conn->query($query);
?>

<!-- Main Content -->
<div id="content" class="d-flex flex-column min-vh-100">
    <div class="container-fluid">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">Borrowing History</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>Book Title</th>
                                <th>Accession No.</th>
                                <th>Borrower</th>
                                <th>Borrow Date</th>
                                <th>Due Date</th>
                                <th>Return/Report Date</th>
                                <th>Replaced Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['book_title']); ?></td>
                                <td><?php echo htmlspecialchars($row['accession']); ?></td>
                                <td><?php echo htmlspecialchars($row['borrower_name']); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($row['borrow_date'])); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($row['due_date'])); ?></td>
                                <td><?php 
                                    if ($row['return_date']) {
                                        echo date('Y-m-d', strtotime($row['return_date']));
                                    } elseif ($row['report_date']) {
                                        echo date('Y-m-d', strtotime($row['report_date']));
                                    } else {
                                        echo 'N/A';
                                    }
                                ?></td>
                                <td><?php echo $row['replacement_date'] ? date('Y-m-d', strtotime($row['replacement_date'])) : '-'; ?></td>
                                <td><?php 
                                    if ($row['replacement_date']) {
                                        echo $row['status'] . ' (Replaced)';
                                    } else if ($row['report_date'] && $row['status'] == 'Lost') {
                                        echo 'Lost';
                                    } else if ($row['report_date'] && $row['status'] == 'Damaged') {
                                        echo 'Damaged';
                                    } else if ($row['return_date']) {
                                        echo 'Returned';
                                    } else {
                                        echo $row['status'];
                                    }
                                ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include('inc/footer.php'); ?>

<script>
$(document).ready(function() {
    const table = $('#dataTable').DataTable({
        "dom": "<'row mb-3'<'col-sm-6'l><'col-sm-6 d-flex justify-content-end'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row mt-3'<'col-sm-5'i><'col-sm-7 d-flex justify-content-end'p>>",
        "pageLength": 10,
        "lengthMenu": [[10, 25, 50, 100, 500], [10, 25, 50, 100, 500]],
        "responsive": true,
        "scrollY": "60vh",
        "scrollCollapse": true,
        "fixedHeader": true,
        "order": [[3, "desc"]], // Sort by borrow date by default
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search..."
        },
        "initComplete": function() {
            $('#dataTable_filter input').addClass('form-control form-control-sm');
            $('#dataTable_filter').addClass('d-flex align-items-center');
            $('#dataTable_filter label').append('<i class="fas fa-search ml-2"></i>');
            $('#dataTable_paginate .paginate_button').addClass('btn btn-sm btn-outline-primary mx-1');
        }
    });

    // Add window resize handler
    $(window).on('resize', function() {
        table.columns.adjust().draw();
    });
});
</script>
