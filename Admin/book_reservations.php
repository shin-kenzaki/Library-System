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
    header('Location: dashboard.php'); // Redirect to a page appropriate for their role or an error page
    exit();
}

// Fetch reservations data from the database
include('../db.php');

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateStart = isset($_GET['date_start']) ? $_GET['date_start'] : '';
$dateEnd = isset($_GET['date_end']) ? $_GET['date_end'] : '';
$userFilter = isset($_GET['user']) ? $_GET['user'] : '';
$bookFilter = isset($_GET['book']) ? $_GET['book'] : '';

// Build the SQL WHERE clause for filtering
$whereClause = "WHERE r.recieved_date IS NULL AND r.cancel_date IS NULL";
$filterParams = [];

if ($statusFilter) {
    $whereClause .= " AND r.status = '$statusFilter'";
    $filterParams[] = "status=$statusFilter";
}

if ($dateStart) {
    $whereClause .= " AND DATE(r.reserve_date) >= '$dateStart'";
    $filterParams[] = "date_start=$dateStart";
}

if ($dateEnd) {
    $whereClause .= " AND DATE(r.reserve_date) <= '$dateEnd'";
    $filterParams[] = "date_end=$dateEnd";
}

if ($userFilter) {
    $whereClause .= " AND (u.firstname LIKE '%$userFilter%' OR u.lastname LIKE '%$userFilter%' OR u.school_id LIKE '%$userFilter%')";
    $filterParams[] = "user=" . urlencode($userFilter);
}

if ($bookFilter) {
    $whereClause .= " AND (b.title LIKE '%$bookFilter%' OR b.accession LIKE '%$bookFilter%')";
    $filterParams[] = "book=" . urlencode($bookFilter);
}

$query = "SELECT 
    r.id AS reservation_id,
    CONCAT(u.firstname, ' ', u.lastname) AS user_name,
    b.title AS book_title,
    b.accession AS accession,
    r.reserve_date,
    r.ready_date,
    CONCAT(a1.firstname, ' ', a1.lastname) AS ready_by_name,
    r.issue_date,
    CONCAT(a2.firstname, ' ', a2.lastname) AS issued_by_name,
    r.cancel_date,
    CONCAT(COALESCE(a3.firstname, u2.firstname), ' ', COALESCE(a3.lastname, u2.lastname)) AS cancelled_by_name,
    r.cancelled_by_role,
    CONCAT(UPPER(SUBSTRING(r.status, 1, 1)), LOWER(SUBSTRING(r.status, 2))) as status,
    r.recieved_date
FROM reservations r
JOIN users u ON r.user_id = u.id
JOIN books b ON r.book_id = b.id
LEFT JOIN admins a1 ON r.ready_by = a1.id
LEFT JOIN admins a2 ON r.issued_by = a2.id
LEFT JOIN admins a3 ON (r.cancelled_by = a3.id AND r.cancelled_by_role = 'Admin')
LEFT JOIN users u2 ON (r.cancelled_by = u2.id AND r.cancelled_by_role = 'User')
$whereClause";
$result = $conn->query($query);

// Count total number of records for the filter summary
$countQuery = "SELECT COUNT(*) as total FROM reservations r 
              JOIN users u ON r.user_id = u.id
              JOIN books b ON r.book_id = b.id
              LEFT JOIN admins a1 ON r.ready_by = a1.id
              LEFT JOIN admins a2 ON r.issued_by = a2.id
              LEFT JOIN admins a3 ON (r.cancelled_by = a3.id AND r.cancelled_by_role = 'Admin')
              LEFT JOIN users u2 ON (r.cancelled_by = u2.id AND r.cancelled_by_role = 'User')
              $whereClause";
$countResult = $conn->query($countQuery);
$totalRecords = $countResult->fetch_assoc()['total'];

// Statistics queries
// Current status statistics
$pendingQuery = "SELECT COUNT(*) AS count FROM reservations WHERE status = 'Pending'";
$pendingResult = $conn->query($pendingQuery);
$pendingCount = $pendingResult->fetch_assoc()['count'];

$readyQuery = "SELECT COUNT(*) AS count FROM reservations WHERE status = 'Ready'";
$readyResult = $conn->query($readyQuery);
$readyCount = $readyResult->fetch_assoc()['count'];

// Today's statistics
$todayReceivedQuery = "SELECT COUNT(*) AS count FROM reservations WHERE status = 'Received' AND DATE(recieved_date) = CURDATE()";
$todayReceivedResult = $conn->query($todayReceivedQuery);
$todayReceivedCount = $todayReceivedResult->fetch_assoc()['count'];

$todayCancelledQuery = "SELECT COUNT(*) AS count FROM reservations WHERE status = 'Cancelled' AND DATE(cancel_date) = CURDATE()";
$todayCancelledResult = $conn->query($todayCancelledQuery);
$todayCancelledCount = $todayCancelledResult->fetch_assoc()['count'];

$todayReservationsQuery = "SELECT COUNT(*) AS count FROM reservations WHERE DATE(reserve_date) = CURDATE()";
$todayReservationsResult = $conn->query($todayReservationsQuery);
$todayReservationsCount = $todayReservationsResult->fetch_assoc()['count'];

// Overall statistics
$totalReservationsQuery = "SELECT COUNT(*) AS count FROM reservations";
$totalReservationsResult = $conn->query($totalReservationsQuery);
$totalReservationsCount = $totalReservationsResult->fetch_assoc()['count'];

$totalReceivedQuery = "SELECT COUNT(*) AS count FROM reservations WHERE status = 'Received'";
$totalReceivedResult = $conn->query($totalReceivedQuery);
$totalReceivedCount = $totalReceivedResult->fetch_assoc()['count'];

$totalCancelledQuery = "SELECT COUNT(*) AS count FROM reservations WHERE status = 'Cancelled'";
$totalCancelledResult = $conn->query($totalCancelledQuery);
$totalCancelledCount = $totalCancelledResult->fetch_assoc()['count'];

// Define styles as PHP variables to use inline
$cardStyles = "transition: all 0.3s; border-left: 4px solid;";
$cardHoverStyles = "transform: translateY(-5px); box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);";
$iconStyles = "font-size: 2rem; opacity: 0.6;";
$titleStyles = "font-size: 0.9rem; font-weight: bold; text-transform: uppercase;";
$numberStyles = "font-size: 1.5rem; font-weight: bold;";

$primaryCardBorder = "#4e73df";
$successCardBorder = "#1cc88a";
$infoCardBorder = "#36b9cc";
$dangerCardBorder = "#e74a3b";
$warningCardBorder = "#f6c23e";

$tableResponsiveStyles = "overflow-x: auto;";
$tableCellStyles = "white-space: nowrap;";
$tableCenterStyles = "text-align: center;";
$checkboxColumnStyles = "text-align: center; width: 40px; padding-left: 15px;";
$checkboxStyles = "margin: 0; vertical-align: middle;";
?>

<!-- Main Content -->
    <div class="container-fluid px-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0 text-gray-800">Book Reservations</h1>
        </div>

        <!-- Reservations Filter Card -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">Filter Reservations</h6>
                <button class="btn btn-sm btn-primary" id="toggleFilter">
                    <i class="fas fa-filter"></i> Toggle Filter
                </button>
            </div>
            <div class="card-body <?= empty($filterParams) ? 'd-none' : '' ?>" id="filterForm">
                <form method="get" action="" class="mb-0" id="reservationsFilterForm">
                    <div class="row">
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select class="form-control form-control-sm" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="Pending" <?= ($statusFilter == 'Pending') ? 'selected' : '' ?>>Pending</option>
                                    <option value="Ready" <?= ($statusFilter == 'Ready') ? 'selected' : '' ?>>Ready</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="date_start">From Date</label>
                                <input type="date" class="form-control form-control-sm" id="date_start" 
                                       name="date_start" value="<?= $dateStart ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="date_end">To Date</label>
                                <input type="date" class="form-control form-control-sm" id="date_end" 
                                       name="date_end" value="<?= $dateEnd ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="user">User</label>
                                <input type="text" class="form-control form-control-sm" id="user" 
                                       name="user" placeholder="Name or ID" value="<?= htmlspecialchars($userFilter) ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="book">Book</label>
                                <input type="text" class="form-control form-control-sm" id="book" 
                                       name="book" placeholder="Title or Accession" value="<?= htmlspecialchars($bookFilter) ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group d-flex justify-content-center" style="margin-top: 2rem">
                                <button type="submit" id="applyFilters" class="btn btn-primary btn-sm mr-2">
                                    <i class="fas fa-filter"></i> Apply
                                </button>
                                <button type="button" id="resetFilters" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">Book Reservations List</h6>
                <div>
                    <!-- Results summary - Moved from card-body to card-header -->
                    <span id="filterSummary" class="mr-3 <?= empty($filterParams) ? 'd-none' : '' ?>">
                        <span class="text-primary font-weight-bold">Filter applied:</span> 
                        Showing <span id="totalResults"><?= $totalRecords ?></span> result<span id="pluralSuffix"><?= $totalRecords != 1 ? 's' : '' ?></span>
                    </span>
                    <button id="bulkReadyBtn" class="btn btn-primary btn-sm mr-2" disabled>
                        Mark Ready (<span id="selectedCountReady">0</span>)
                    </button>
                    <button id="bulkReceiveBtn" class="btn btn-success btn-sm mr-2" disabled>
                        Mark Received (<span id="selectedCountReceive">0</span>)
                    </button>
                    <button id="bulkCancelBtn" class="btn btn-danger btn-sm" disabled>
                        Cancel Selected (<span id="selectedCount">0</span>)
                    </button>
                </div>
            </div>
            <!-- Add alert section -->
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mx-4 mt-3" role="alert">
                <?php echo htmlspecialchars($_GET['error']); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mx-4 mt-3" role="alert">
                <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <?php endif; ?>
            <div class="card-body">
                <!-- Remove the old filter summary div that was here -->
                <style>
                    /* Add alternating row colors */
                    #dataTable.table-striped tbody tr:nth-of-type(odd) {
                        background-color: rgba(0, 0, 0, 0.03);
                    }

                    #dataTable.table-striped tbody tr:hover {
                        background-color: rgba(0, 123, 255, 0.05);
                    }
                    
                    /* Add selected row styles */
                    #dataTable tbody tr.selected {
                        background-color: rgba(0, 123, 255, 0.1) !important;
                    }
                    
                    /* Ensure selected rows override striped styles */
                    #dataTable.table-striped tbody tr.selected:nth-of-type(odd),
                    #dataTable.table-striped tbody tr.selected:nth-of-type(even) {
                        background-color: rgba(0, 123, 255, 0.1) !important;
                    }
                    
                    /* Add hover effect styles */
                    #dataTable tbody tr {
                        transition: background-color 0.2s;
                        cursor: pointer;
                    }

                    #dataTable tbody tr:hover {
                        background-color: rgba(0, 123, 255, 0.05);
                    }
                    
                    /* Checkbox styling */
                    .checkbox-cell {
                        cursor: pointer;
                        text-align: center;
                        vertical-align: middle;
                        width: 40px !important;
                    }
                    
                    .checkbox-cell input[type="checkbox"] {
                        margin: 0 auto;
                        display: block;
                    }
                </style>
                <div class="table-responsive" style="<?php echo $tableResponsiveStyles; ?>">
                    <table class="table table-bordered table-striped" id="dataTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th style="<?php echo $checkboxColumnStyles; ?> width: 30px;" id="checkboxHeader">
                                    Select
                                </th>
                                <th style="<?php echo $tableCenterStyles; ?>">User</th>
                                <th style="<?php echo $tableCenterStyles; ?>">Book</th>
                                <th style="<?php echo $tableCenterStyles; ?>">Accession No.</th>
                                <th style="<?php echo $tableCenterStyles; ?>">Reserve Date</th>
                                <th style="<?php echo $tableCenterStyles; ?>">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if ($result->num_rows > 0): 
                                while ($row = $result->fetch_assoc()): 
                            ?>
                                <tr data-reservation-id='<?php echo $row["reservation_id"]; ?>' data-status='<?php echo $row["status"]; ?>'>
                                    <td style="<?php echo $checkboxColumnStyles; ?>">
                                        <input type="checkbox" class="reservation-checkbox" data-id="<?php echo $row["reservation_id"]; ?>" style="<?php echo $checkboxStyles; ?>">
                                    </td>
                                    <td style="<?php echo $tableCellStyles; ?>"><?php echo $row["user_name"]; ?></td>
                                    <td style="<?php echo $tableCellStyles; ?>"><?php echo $row["book_title"]; ?></td>
                                    <td style="<?php echo $tableCellStyles . $tableCenterStyles; ?>"><?php echo $row["accession"]; ?></td>
                                    <td style="<?php echo $tableCellStyles . $tableCenterStyles; ?>"><?php echo $row["reserve_date"]; ?></td>
                                    <?php
                                    $status = $row["status"];
                                    $statusClass = match($status) {
                                        'Pending' => 'text-warning',
                                        'Ready' => 'text-primary',
                                        'Received' => 'text-success',
                                        'Cancelled' => 'text-danger',
                                        default => 'text-secondary'
                                    };
                                    ?>
                                    <td style="<?php echo $tableCellStyles . $tableCenterStyles; ?>">
                                        <span class='font-weight-bold <?php echo $statusClass; ?>'><?php echo $status; ?></span>
                                    </td>
                                </tr>
                            <?php 
                                endwhile;
                            endif;
                            $conn->close();
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Statistics Dashboard -->
        <h4 class="mb-3 text-gray-800">Statistics Overview</h4>
        <div class="row mb-4">
            <!-- Pending Reservations -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card shadow h-100 py-2" style="<?php echo $cardStyles; ?> border-left-color: <?php echo $warningCardBorder; ?>;" 
                     onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 0.5rem 1rem rgba(0, 0, 0, 0.15)';" 
                     onmouseout="this.style.transform=''; this.style.boxShadow='';">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1" style="<?php echo $titleStyles; ?>">
                                    Pending Reservations</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800" style="<?php echo $numberStyles; ?>"><?php echo $pendingCount; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clock fa-2x text-gray-300" style="<?php echo $iconStyles; ?>"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ready Reservations -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card shadow h-100 py-2" style="<?php echo $cardStyles; ?> border-left-color: <?php echo $infoCardBorder; ?>;"
                     onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 0.5rem 1rem rgba(0, 0, 0, 0.15)';" 
                     onmouseout="this.style.transform=''; this.style.boxShadow='';">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1" style="<?php echo $titleStyles; ?>">
                                    Ready Reservations</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800" style="<?php echo $numberStyles; ?>"><?php echo $readyCount; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-book fa-2x text-gray-300" style="<?php echo $iconStyles; ?>"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overall Received -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card shadow h-100 py-2" style="<?php echo $cardStyles; ?> border-left-color: <?php echo $successCardBorder; ?>;"
                     onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 0.5rem 1rem rgba(0, 0, 0, 0.15)';" 
                     onmouseout="this.style.transform=''; this.style.boxShadow='';">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1" style="<?php echo $titleStyles; ?>">
                                    Overall Received</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800" style="<?php echo $numberStyles; ?>"><?php echo $totalReceivedCount; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-check-circle fa-2x text-gray-300" style="<?php echo $iconStyles; ?>"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overall Cancelled -->
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card shadow h-100 py-2" style="<?php echo $cardStyles; ?> border-left-color: <?php echo $dangerCardBorder; ?>;"
                     onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 0.5rem 1rem rgba(0, 0, 0, 0.15)';" 
                     onmouseout="this.style.transform=''; this.style.boxShadow='';">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1" style="<?php echo $titleStyles; ?>">
                                    Overall Cancelled</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800" style="<?php echo $numberStyles; ?>"><?php echo $totalCancelledCount; ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-ban fa-2x text-gray-300" style="<?php echo $iconStyles; ?>"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- End Statistics Dashboard -->
    </div>
</div>
<!-- End of Main Content -->

<!-- Footer -->
<?php include('inc/footer.php'); ?>
<!-- End of Footer -->

<!-- Scroll to Top Button-->
<a class="scroll-to-top rounded" href="#page-top">
    <i class="fas fa-angle-up"></i>
</a>

<div class="context-menu" style="display: none; position: absolute; z-index: 1000; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 2px 2px 5px rgba(0,0,0,0.1);">
    <ul class="list-group">
        <li class="list-group-item" data-action="ready" style="cursor: pointer; padding: 8px 20px;">Mark as Ready</li>
        <li class="list-group-item" data-action="received" style="cursor: pointer; padding: 8px 20px;">Mark as Received</li>
        <li class="list-group-item" data-action="cancel" style="cursor: pointer; padding: 8px 20px;">Cancel Reservation</li>
    </ul>
</div>

<!-- Add these before the closing </head> tag -->
<link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    $(document).ready(function() {
        // Toggle filter form visibility
        $('#toggleFilter').on('click', function() {
            $('#filterForm').toggleClass('d-none');
        });

        // Reset filters
        $('#resetFilters').on('click', function(e) {
            // Prevent default form submission
            e.preventDefault();
            
            // Store the current visibility state of the filter form
            const isFilterVisible = !$('#filterForm').hasClass('d-none');
            
            // Clear all filter values
            $('#status').val('');
            $('#date_start').val('');
            $('#date_end').val('');
            $('#user').val('');
            $('#book').val('');
            
            // Update the filter summary to indicate no filters
            $('#filterSummary').addClass('d-none');
            
            // Reload the current page with no filters but don't hide the filter form
            $.ajax({
                url: 'book_reservations.php',
                type: 'GET',
                success: function(data) {
                    // Extract the table content from the response
                    let tableHtml = $(data).find('#dataTable').parent().html();
                    // Update just the table content, not the whole page
                    $('.table-responsive').html(tableHtml);
                    
                    // Reinitialize DataTable
                    initializeDataTable();
                    
                    // Restore the filter form visibility state
                    if (isFilterVisible) {
                        $('#filterForm').removeClass('d-none');
                    }
                }
            });
        });
        
        // Handle form submission (Apply filters)
        $('#reservationsFilterForm').on('submit', function(e) {
            e.preventDefault();
            
            // Store the current visibility state of the filter form
            const isFilterVisible = !$('#filterForm').hasClass('d-none');
            
            // Submit the form using AJAX
            $.ajax({
                url: 'book_reservations.php',
                type: 'GET',
                data: $(this).serialize(),
                success: function(data) {
                    // Extract the relevant parts from the response
                    let tableHtml = $(data).find('#dataTable').parent().html();
                    let filterSummaryHtml = $(data).find('#filterSummary').html();
                    
                    // Update parts of the page
                    $('.table-responsive').html(tableHtml);
                    $('#filterSummary').html(filterSummaryHtml);
                    
                    // Show or hide the filter summary based on whether filters are applied
                    if ($('#status').val() || $('#date_start').val() || $('#date_end').val() || 
                        $('#user').val() || $('#book').val()) {
                        $('#filterSummary').removeClass('d-none');
                    } else {
                        $('#filterSummary').addClass('d-none');
                    }
                    
                    // Reinitialize DataTable
                    initializeDataTable();
                    
                    // Restore the filter form visibility state
                    if (isFilterVisible) {
                        $('#filterForm').removeClass('d-none');
                    }
                }
            });
        });
        
        // Function to initialize DataTable with consistent settings
        function initializeDataTable() {
            if ($.fn.DataTable.isDataTable('#dataTable')) {
                $('#dataTable').DataTable().destroy();
            }
            
            const table = $('#dataTable').DataTable({
                "dom": "<'row mb-3'<'col-sm-6'l><'col-sm-6 d-flex justify-content-end'f>>" +
                       "<'row'<'col-sm-12'tr>>" +
                       "<'row mt-3'<'col-sm-5'i><'col-sm-7 d-flex justify-content-end'p>>",
                "pagingType": "simple_numbers",
                "pageLength": 10,
                "lengthMenu": [[10, 25, 50, 100, 500], [10, 25, 50, 100, 500]],
                "responsive": false,
                "scrollY": "60vh",
                "scrollCollapse": true,
                "fixedHeader": true,
                "ordering": true,
                "order": [[1, 'asc']], // Default sort by second column (user)
                "columnDefs": [
                    { "orderable": false, "targets": 0, "searchable": false } // Disable sorting for checkbox column completely
                ],
                "language": {
                    "search": "_INPUT_",
                    "searchPlaceholder": "Search..."
                },
                "initComplete": function() {
                    $('#dataTable_filter input').addClass('form-control form-control-sm');
                    $('#dataTable_filter').addClass('d-flex align-items-center');
                    $('#dataTable_filter label').append('<i class="fas fa-search ml-2"></i>');
                    $('.dataTables_paginate .paginate_button').addClass('btn btn-sm btn-outline-primary mx-1');
                }
            });
            
            // Re-bind checkbox events
            $('#selectAll').change(function() {
                const isChecked = $(this).prop('checked');
                $('.reservation-checkbox').each(function() {
                    const status = $(this).closest('tr').data('status');
                    // Only allow selection of Pending and Ready items
                    if (status === 'Pending' || status === 'Ready') {
                        $(this).prop('checked', isChecked);
                    } else {
                        $(this).prop('checked', false);
                        $(this).prop('disabled', true);
                    }
                });
                updateBulkButtons();
            });
            
            $(document).on('change', '.reservation-checkbox', function() {
                const totalCheckable = $('.reservation-checkbox').filter(function() {
                    const status = $(this).closest('tr').find('td:eq(5) span').text().trim();
                    return status === 'Pending' || status === 'Ready';
                }).length;
                
                const totalChecked = $('.reservation-checkbox:checked').length;
                
                $('#selectAll').prop({
                    'checked': totalChecked > 0 && totalChecked === totalCheckable,
                    'indeterminate': totalChecked > 0 && totalChecked < totalCheckable
                });
                
                updateBulkButtons();
            });
        }

        // Add inline style for context menu hover
        $('.context-menu .list-group-item').hover(
            function() { $(this).css('background-color', '#f8f9fa'); },
            function() { $(this).css('background-color', ''); }
        );

        // Add style for table rows
        $('tr[data-reservation-id]').css('cursor', 'context-menu');

        // Add CSS to hide sorting icons for checkbox column
        $('<style>')
            .text(`
                #dataTable thead th:first-child.sorting::before,
                #dataTable thead th:first-child.sorting::after,
                #dataTable thead th:first-child.sorting_asc::before,
                #dataTable thead th:first-child.sorting_asc::after,
                #dataTable thead th:first-child.sorting_desc::before,
                #dataTable thead th:first-child.sorting_desc::after {
                    display: none !important;
                }
            `)
            .appendTo('head');

        const table = $('#dataTable').DataTable({
            "dom": "<'row mb-3'<'col-sm-6'l><'col-sm-6 d-flex justify-content-end'f>>" +
                   "<'row'<'col-sm-12'tr>>" +
                   "<'row mt-3'<'col-sm-5'i><'col-sm-7 d-flex justify-content-end'p>>",
            "pagingType": "simple_numbers",
            "pageLength": 10,
            "lengthMenu": [[10, 25, 50, 100, 500], [10, 25, 50, 100, 500]],
            "responsive": false,
            "scrollY": "60vh",
            "scrollCollapse": true,
            "fixedHeader": true,
            "ordering": true,
            "order": [[1, 'asc']], // Default sort by second column (user)
            "columnDefs": [
                { "orderable": false, "targets": 0, "searchable": false } // Disable sorting for checkbox column completely
            ],
            "language": {
                "search": "_INPUT_",
                "searchPlaceholder": "Search..."
            },
            "initComplete": function() {
                $('#dataTable_filter input').addClass('form-control form-control-sm');
                $('#dataTable_filter').addClass('d-flex align-items-center');
                $('#dataTable_filter label').append('<i class="fas fa-search ml-2"></i>');
                $('.dataTables_paginate .paginate_button').addClass('btn btn-sm btn-outline-primary mx-1');
            }
        });

        // Add window resize handler
        $(window).on('resize', function() {
            table.columns.adjust().draw();
        });

        const contextMenu = $('.context-menu');
        let $selectedRow = null;

        // Right-click handler for table rows
        $('#dataTable tbody').on('contextmenu', 'tr', function(e) {
            e.preventDefault();
            
            const reservationId = $(this).data('reservation-id');
            if (!reservationId) return;

            $selectedRow = $(this);
            const status = $selectedRow.data('status');

            // Don't show menu for completed states
            if (status === 'Cancelled' || status === 'Received') {
                return;
            }

            // Show/hide menu items based on status
            $(".context-menu .list-group-item").hide(); // Hide all items by default

            if (status === 'Pending') {
                // For pending items, show only Ready and Cancel options
                $(".context-menu .list-group-item[data-action='ready']").show();
                $(".context-menu .list-group-item[data-action='cancel']").show();
            } else if (status === 'Ready') {
                // For ready items, show only Received and Cancel options
                $(".context-menu .list-group-item[data-action='received']").show();
                $(".context-menu .list-group-item[data-action='cancel']").show();
            }

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

            const reservationId = $selectedRow.data('reservation-id');
            const action = $(this).data('action');
            const status = $selectedRow.data('status');
            let url = '';
            let confirmConfig = {};

            // Validate action against current status
            if ((action === 'ready' && status !== 'Pending') ||
                (action === 'received' && status !== 'Ready') ||
                (action === 'cancel' && !['Pending', 'Ready'].includes(status))) {
                Swal.fire({
                    title: 'Invalid Action',
                    text: 'This action cannot be performed on the current reservation status.',
                    icon: 'error'
                });
                contextMenu.hide();
                return;
            }

            switch(action) {
                case 'ready':
                    url = 'reservation_ready.php';
                    confirmConfig = {
                        title: 'Mark as Ready?',
                        text: 'Are you sure you want to mark this reservation as ready?',
                        icon: 'question',
                        confirmButtonText: 'Yes, Mark as Ready',
                        confirmButtonColor: '#3085d6'
                    };
                    break;
                case 'received':
                    url = 'reservation_receive.php';
                    confirmConfig = {
                        title: 'Mark as Received?',
                        text: 'Are you sure you want to mark this reservation as received and create a borrowing record? This action cannot be undone.',
                        icon: 'warning',
                        confirmButtonText: 'Yes, Mark as Received',
                        confirmButtonColor: '#28a745'
                    };
                    break;
                case 'cancel':
                    url = 'reservation_cancel.php';
                    confirmConfig = {
                        title: 'Cancel Reservation?',
                        text: 'Are you sure you want to cancel this reservation?',
                        icon: 'warning',
                        confirmButtonText: 'Yes, Cancel It',
                        confirmButtonColor: '#dc3545'
                    };
                    break;
            }

            if (url && confirmConfig) {
                Swal.fire({
                    ...confirmConfig,
                    showCancelButton: true,
                    cancelButtonText: 'No, Keep It',
                    cancelButtonColor: '#6c757d',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    showLoaderOnConfirm: true,
                    preConfirm: () => {
                        return fetch(`${url}?id=${reservationId}`)
                            .then(response => response.json())
                            .then(data => {
                                if (!data.success) {
                                    throw new Error(data.message || 'Error processing request');
                                }
                                return data;
                            })
                            .catch(error => {
                                Swal.showValidationMessage(`Request failed: ${error.message}`);
                            });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const actionTexts = {
                            'ready': 'marked as ready',
                            'received': 'received and borrowing record created',
                            'cancel': 'cancelled'
                        };
                        
                        Swal.fire({
                            title: 'Success!',
                            text: `The reservation has been ${actionTexts[action]}.`,
                            icon: 'success',
                            confirmButtonColor: '#3085d6'
                        }).then(() => {
                            window.location.reload();
                        });
                    }
                });
            }
            
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
                tr[data-reservation-id] {
                    cursor: context-menu;
                }
            `)
            .appendTo('head');

        // Handle select all checkbox
        $('#selectAll').change(function() {
            const isChecked = $(this).prop('checked');
            $('.reservation-checkbox').each(function() {
                const status = $(this).closest('tr').data('status');
                // Only allow selection of Pending and Ready items
                if (status === 'Pending' || status === 'Ready') {
                    $(this).prop('checked', isChecked);
                } else {
                    $(this).prop('checked', false);
                    $(this).prop('disabled', true);
                }
            });
            updateBulkButtons();
        });

        // Handle individual checkboxes
        $(document).on('change', '.reservation-checkbox', function() {
            const totalCheckable = $('.reservation-checkbox').filter(function() {
                const status = $(this).closest('tr').find('td:eq(5) span').text().trim();
                return status === 'Pending' || status === 'Ready';
            }).length;
            
            const totalChecked = $('.reservation-checkbox:checked').length;
            
            $('#selectAll').prop({
                'checked': totalChecked > 0 && totalChecked === totalCheckable,
                'indeterminate': totalChecked > 0 && totalChecked < totalCheckable
            });
            
            updateBulkButtons();
        });

        // Update bulk cancel button visibility
        function updateBulkButtons() {
            const checkedBoxes = $('.reservation-checkbox:checked').length;
            $('#selectedCount, #selectedCountReady, #selectedCountReceive').text(checkedBoxes);
            $('#bulkCancelBtn, #bulkReadyBtn, #bulkReceiveBtn').prop('disabled', checkedBoxes === 0);
        }

        // Handle bulk cancel button click
        $('#bulkCancelBtn').click(function() {
            const selectedIds = [];
            const invalidSelections = [];
            
            $('.reservation-checkbox:checked').each(function() {
                const $row = $(this).closest('tr');
                const status = $row.data('status');
                const bookTitle = $row.find('td:eq(2)').text();
                const borrower = $row.find('td:eq(1)').text();
                
                if (status === 'Pending' || status === 'Ready') {
                    selectedIds.push($(this).data('id'));
                } else {
                    invalidSelections.push(`${bookTitle} - ${borrower} (${status})`);
                }
            });

            // Show error if any invalid selections
            if (invalidSelections.length > 0) {
                let errorMessage = 'Only pending or ready reservations can be cancelled:<ul class="list-group mt-3">';
                invalidSelections.forEach(item => {
                    errorMessage += `<li class="list-group-item text-danger">${item}</li>`;
                });
                errorMessage += '</ul>';
                
                Swal.fire({
                    title: 'Invalid Selections',
                    html: errorMessage,
                    icon: 'warning'
                });
                return;
            }

            if (selectedIds.length === 0) return;

            Swal.fire({
                title: 'Cancel Multiple Reservations?',
                text: `Are you sure you want to cancel ${selectedIds.length} reservation(s)?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Cancel Them',
                cancelButtonText: 'No, Keep Them',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch('reservation_cancel.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ ids: selectedIds })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || 'Error cancelling reservations');
                        }
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Success!',
                        text: 'The selected reservations have been cancelled.',
                        icon: 'success',
                        confirmButtonColor: '#3085d6'
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        });

        // Add bulk ready button handler
        $('#bulkReadyBtn').click(function() {
            const selectedIds = [];
            const invalidSelections = [];
            
            $('.reservation-checkbox:checked').each(function() {
                const $row = $(this).closest('tr');
                const status = $row.data('status');
                const bookTitle = $row.find('td:eq(2)').text();
                const borrower = $row.find('td:eq(1)').text();
                
                if (status === 'Pending') {
                    selectedIds.push($(this).data('id'));
                } else {
                    invalidSelections.push(`${bookTitle} - ${borrower} (${status})`);
                }
            });

            // Show error if any invalid selections
            if (invalidSelections.length > 0) {
                let errorMessage = 'Only pending reservations can be marked as ready:<ul class="list-group mt-3">';
                invalidSelections.forEach(item => {
                    errorMessage += `<li class="list-group-item text-danger">${item}</li>`;
                });
                errorMessage += '</ul>';
                
                Swal.fire({
                    title: 'Invalid Selections',
                    html: errorMessage,
                    icon: 'warning'
                });
                return;
            }

            if (selectedIds.length === 0) return;

            Swal.fire({
                title: 'Mark Reservations as Ready?',
                text: `Are you sure you want to mark ${selectedIds.length} reservation(s) as ready?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Mark as Ready',
                cancelButtonText: 'Cancel',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch('reservation_ready.php', {  // Changed from reservation_bulk_ready.php
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ ids: selectedIds })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || 'Error updating reservations');
                        }
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Success!',
                        text: 'The selected reservations have been marked as ready.',
                        icon: 'success',
                        confirmButtonColor: '#3085d6'
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        });

        // Add bulk receive button handler
        $('#bulkReceiveBtn').click(function() {
            const selectedIds = [];
            const selectedBooks = [];
            const invalidSelections = [];
            
            $('.reservation-checkbox:checked').each(function() {
                const $row = $(this).closest('tr');
                const status = $row.data('status');
                const bookTitle = $row.find('td:eq(2)').text();
                const borrower = $row.find('td:eq(1)').text();
                
                if (status === 'Ready') {
                    selectedIds.push($(this).data('id'));
                    selectedBooks.push({
                        title: bookTitle,
                        borrower: borrower
                    });
                } else {
                    invalidSelections.push(`${bookTitle} - ${borrower} (${status})`);
                }
            });

            if (invalidSelections.length > 0) {
                let errorMessage = 'The following reservations must be marked as Ready first:<ul class="list-group mt-3">';
                invalidSelections.forEach(item => {
                    errorMessage += `<li class="list-group-item text-danger">${item}</li>`;
                });
                errorMessage += '</ul>';
                
                Swal.fire({
                    title: 'Invalid Selections',
                    html: errorMessage,
                    icon: 'warning'
                });
                return;
            }

            let booksListHtml = '<ul class="list-group mt-3">';
            selectedBooks.forEach(book => {
                booksListHtml += `<li class="list-group-item">${book.title} - ${book.borrower}</li>`;
            });
            booksListHtml += '</ul>';

            Swal.fire({
                title: 'Mark Reservations as Received?',
                html: `Are you sure you want to mark these books as received?${booksListHtml}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Mark as Received',
                cancelButtonText: 'Cancel',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return fetch('reservation_receive.php', { // Fix URL here too
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ ids: selectedIds })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || 'Error processing reservations');
                        }
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Success!',
                        text: 'The selected reservations have been marked as received.',
                        icon: 'success',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        });
        
        // Function to update the highlighting of selected rows
        function updateRowSelectionState() {
            // First, remove the selected class from all rows
            $('#dataTable tbody tr').removeClass('selected');
            
            // Then add it only to rows with checked checkboxes
            $('.reservation-checkbox:checked').each(function() {
                $(this).closest('tr').addClass('selected');
            });
            
            // Update button states
            updateBulkButtons();
        }
        
        // Handle row clicks to select/deselect rows
        $('#dataTable tbody').on('click', 'tr', function(e) {
            // Ignore clicks on checkbox itself and on action buttons
            if (e.target.type === 'checkbox' || $(e.target).hasClass('btn') || $(e.target).parent().hasClass('btn')) {
                return;
            }
            
            const checkbox = $(this).find('.reservation-checkbox');
            if (checkbox.is(':disabled')) return;
            
            checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
        });
        
        // Update row selection when checkbox state changes
        $(document).on('change', '.reservation-checkbox', function() {
            const $row = $(this).closest('tr');
            
            if ($(this).prop('checked')) {
                $row.addClass('selected');
            } else {
                $row.removeClass('selected');
            }
            
            const totalCheckable = $('.reservation-checkbox').filter(function() {
                const status = $(this).closest('tr').find('td:eq(5) span').text().trim();
                return status === 'Pending' || status === 'Ready';
            }).length;
            
            const totalChecked = $('.reservation-checkbox:checked').length;
            
            $('#selectAll').prop({
                'checked': totalChecked > 0 && totalChecked === totalCheckable,
                'indeterminate': totalChecked > 0 && totalChecked < totalCheckable
            });
            
            updateBulkButtons();
        });
        
        // Handle select all checkbox
        $('#selectAll').change(function() {
            const isChecked = $(this).prop('checked');
            $('.reservation-checkbox').each(function() {
                const $row = $(this).closest('tr');
                const status = $row.data('status');
                
                // Only allow selection of Pending and Ready items
                if (status === 'Pending' || status === 'Ready') {
                    $(this).prop('checked', isChecked);
                    
                    if (isChecked) {
                        $row.addClass('selected');
                    } else {
                        $row.removeClass('selected');
                    }
                }
            });
            
            updateBulkButtons();
        });
        
        // Handle header checkbox cell click
        $(document).on('click', '#checkboxHeader', function(e) {
            // If the click was directly on the checkbox, don't execute this handler
            if (e.target.type === 'checkbox') return;
            
            // Find and toggle the checkbox
            var checkbox = $('#selectAll');
            checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
        });
        
        // Initialize row selection states after DataTable is fully loaded
        $('#dataTable').on('draw.dt', function() {
            updateRowSelectionState();
        });
        
        // Initialize row selection state on page load
        updateRowSelectionState();
    });
</script>
</body>
</html>