<?php
session_start();
include('inc/header.php');

// Check if the user is logged in and has the appropriate admin role
if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['role'], ['Admin', 'Librarian', 'Assistant', 'Encoder'])) {
    header("Location: index.php");
    exit();
}

// Check if the user has the correct role
if ($_SESSION['role'] !== 'Admin' && $_SESSION['role'] !== 'Librarian') {
    header('Location: dashboard.php');
    exit();
}

include('../db.php');

// Fetch fines with related information
$query = "SELECT f.id, f.type, f.amount, f.status, f.date, f.payment_date,
          b.issue_date, b.due_date, b.return_date,
          bk.title AS book_title,
          CONCAT(u.firstname, ' ', u.lastname) AS borrower_name,
          u.school_id
          FROM fines f
          JOIN borrowings b ON f.borrowing_id = b.id
          JOIN books bk ON b.book_id = bk.id
          JOIN users u ON b.user_id = u.id
          ORDER BY f.date DESC";


// Run the query and store the result
$result = $conn->query($query);
?>

<style>
    .table-responsive {
        overflow-x: auto;
    }
    .table td, .table th {
        white-space: nowrap;
    }
</style>

<!-- Main Content -->
<div id="content" class="d-flex flex-column min-vh-100">
    <div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0 text-gray-800">Fines</h1>

            <!-- Generate Receipt Form -->
            <form action="fine-receipt.php" method="post" id="receiptForm" target="_blank" onsubmit="return validateForm()" class="d-flex align-items-center">
                <div class="col-auto p-2">
                    <label for="school_id" class="col-form-label" style="font-size:medium;">Enter ID Number:</label>
                </div>
                <div class="col-auto p-2" style="width:200px;">
                    <input type="text" name="school_id" id="school_id" class="form-control custom" placeholder="ID Number" required>
                </div>
                <div class="col-auto p-2">
                    <button class="btn btn-danger btn-block" type="submit">Generate Fine Receipt</button>
                </div>
            </form>
        </div>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Fines List</h6>
            </div>
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show mx-4 mt-3" role="alert">
                    <?php echo htmlspecialchars($_GET['success']); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show mx-4 mt-3" role="alert">
                    <?php echo htmlspecialchars($_GET['error']); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="finesTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th class="text-center">ID Number</th> <!-- New column for School ID -->
                                <th class="text-center">Borrower</th>
                                <th class="text-center">Book</th>
                                <th class="text-center">Type</th>
                                <th class="text-center">Amount</th>
                                <th class="text-center">Issue Date</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Payment Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr data-fine-id="<?php echo $row['id']; ?>"
                                    data-amount="<?php echo $row['amount']; ?>"
                                    data-borrower="<?php echo htmlspecialchars($row['borrower_name']); ?>"
                                    data-status="<?php echo $row['status']; ?>">
                                    <td class="text-left"><?php echo htmlspecialchars($row['school_id']); ?></td> <!-- Display School ID -->
                                    <td class="text-left"><?php echo htmlspecialchars($row['borrower_name']); ?></td>
                                    <td class="text-left"><?php echo htmlspecialchars($row['book_title']); ?></td>
                                    <td class="text-left"><?php echo htmlspecialchars($row['type']); ?></td>
                                    <td class="text-left">₱<?php echo number_format($row['amount'], 2); ?></td>
                                    <td class="text-left"><?php echo date('Y-m-d', strtotime($row['date'])); ?></td>
                                    <td class="text-left">
                                        <?php if ($row['status'] === 'Unpaid'): ?>
                                            <span class="badge badge-danger">Unpaid</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-left">
                                        <?php
                                        echo ($row['payment_date'] !== null && $row['payment_date'] !== '0000-00-00')
                                            ? date('Y-m-d', strtotime($row['payment_date']))
                                            : '-';
                                        ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Context Menu -->
<div class="context-menu" style="display: none; position: absolute; z-index: 1000;">
    <ul class="list-group">
        <li class="list-group-item" data-action="mark-paid">Mark as Paid</li>
    </ul>
</div>

<?php include('inc/footer.php'); ?>

<!-- Add SweetAlert2 CSS and JS -->
<link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Store references
    const contextMenu = $('.context-menu');
    let $selectedRow = null;

    // Initialize DataTable
    const table = $('#finesTable').DataTable({
        "dom": "<'row mb-3'<'col-sm-6'l><'col-sm-6 d-flex justify-content-end'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row mt-3'<'col-sm-5'i><'col-sm-7 d-flex justify-content-end'p>>",
        "pagingType": "simple_numbers",
        "pageLength": 25,
        "lengthMenu": [[10, 25, 50, 100, 500], [10, 25, 50, 100, 500]],
        "responsive": false,
        "scrollY": "60vh",
        "scrollCollapse": true,
        "fixedHeader": true,
        "order": [[5, "desc"]],
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search..."
        },
        "initComplete": function() {
            $('#finesTable_filter input').addClass('form-control form-control-sm');
            $('#finesTable_filter').addClass('d-flex align-items-center');
            $('#finesTable_filter label').append('<i class="fas fa-search ml-2"></i>');
            $('.dataTables_paginate .paginate_button').addClass('btn btn-sm btn-outline-primary mx-1');
        }
    });

    // Add window resize handler
    $(window).on('resize', function() {
        table.columns.adjust().draw();
    });

    // Right-click handler for table rows
    $('#finesTable tbody').on('contextmenu', 'tr', function(e) {
        e.preventDefault();

        $selectedRow = $(this);
        const status = $selectedRow.data('status');

        contextMenu.find('li').data('action', status === 'Unpaid' ? 'mark-paid' : 'mark-unpaid').text(status === 'Unpaid' ? 'Mark as Paid' : 'Mark as Unpaid');

        contextMenu.css({
            top: e.pageY + "px",
            left: e.pageX + "px",
            display: "block"
        });
    });

    // Hide context menu on document click
    $(document).on('click', function() {
        contextMenu.hide();
    });

    // Prevent hiding when clicking menu items
    $('.context-menu').on('click', function(e) {
        e.stopPropagation();
    });

    // Handle menu item clicks
    $(".context-menu li").on('click', function() {
        if (!$selectedRow) return;

        const fineId = $selectedRow.data('fine-id');
        const amount = $selectedRow.data('amount');
        const borrower = $selectedRow.data('borrower');
        const action = $(this).data('action');
        let url = '';

        if (action === 'mark-paid') {
            url = 'mark_fine_paid.php';
        } else if (action === 'mark-unpaid') {
            url = 'mark_fine_unpaid.php';
        }

        // Sweet Alert confirmation
        Swal.fire({
            title: 'Confirm Payment',
            html: `
                <div class="text-left">
                    <p class="mb-2"><strong>Borrower:</strong> ${borrower}</p>
                    <p class="mb-2"><strong>Amount:</strong> ₱${parseFloat(amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}</p>
                    <p class="mt-3">Are you sure you want to mark this fine as ${action === 'mark-paid' ? 'paid' : 'unpaid'}?</p>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: `<i class="fas fa-check"></i> Yes, Mark as ${action === 'mark-paid' ? 'Paid' : 'Unpaid'}`,
            cancelButtonText: '<i class="fas fa-times"></i> Cancel',
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#dc3545',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showLoaderOnConfirm: true,
            customClass: {
                confirmButton: 'btn btn-success',
                cancelButton: 'btn btn-danger'
            },
            preConfirm: () => {
                return fetch(`${url}?id=${fineId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'error') {
                            throw new Error(data.message);
                        }
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Error: ${error.message}`);
                    });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    icon: 'success',
                    title: 'Payment Recorded!',
                    text: `The fine has been successfully marked as ${action === 'mark-paid' ? 'paid' : 'unpaid'}.`,
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'OK'
                }).then(() => {
                    window.location.reload();
                });
            }
        });

        contextMenu.hide();
    });

    // Add custom styles for the context menu
    $('<style>')
        .text(`
            .context-menu {
                background: white;
                border: 1px solid #ddd;
                border-radius: 4px;
                box-shadow: 2px 2px 5px rgba(0,0,0,0.1);
            }
            .context-menu .list-group-item {
                cursor: pointer;
                padding: 8px 20px;
            }
            .context-menu .list-group-item:hover {
                background-color: #f8f9fa;
            }
            tr[data-fine-id] {
                cursor: context-menu;
            }
        `)
        .appendTo('head');
});
</script>
