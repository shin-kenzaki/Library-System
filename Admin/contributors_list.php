<?php
ob_start(); // Start output buffering
session_start();

// Check login and handle bulk delete first
if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['role'], ['Admin', 'Librarian', 'Assistant', 'Encoder'])) {
    header("Location: index.php");
    exit();
}

// Handle bulk delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (isset($_POST['ids']) && !empty($_POST['ids'])) {
        require_once '../db.php';
        
        $safeIds = array_map('intval', $_POST['ids']);
        $idsString = implode(',', $safeIds);
        $deleted_details = [];

        // Fetch details before deleting
        if (!empty($idsString)) {
            $fetchDetailsSql = "SELECT c.id, b.title as book_title, CONCAT(w.firstname, ' ', w.middle_init, ' ', w.lastname) as writer_name, c.role 
                                FROM contributors c
                                JOIN books b ON c.book_id = b.id
                                JOIN writers w ON c.writer_id = w.id
                                WHERE c.id IN ($idsString)";
            $detailsResult = $conn->query($fetchDetailsSql);
            if ($detailsResult && $detailsResult->num_rows > 0) {
                while ($row = $detailsResult->fetch_assoc()) {
                    $deleted_details[$row['id']] = htmlspecialchars($row['writer_name'] . ' (' . $row['role'] . ') for "' . $row['book_title'] . '"');
                }
            }
        }

        $conn->begin_transaction();
        try {
            $deleteCount = 0;
            $successfully_deleted_list = [];
            $stmt = $conn->prepare("DELETE FROM contributors WHERE id = ?");
            
            foreach ($safeIds as $id) {
                $stmt->bind_param("i", $id);
                if ($stmt->execute() && $conn->affected_rows > 0) {
                    $deleteCount++;
                    if (isset($deleted_details[$id])) {
                        $successfully_deleted_list[] = $deleted_details[$id];
                    }
                }
            }
            $stmt->close();
            $conn->commit();
            
            $_SESSION['success_message'] = "$deleteCount contributor record(s) deleted successfully.";
            $_SESSION['deleted_contributors_details'] = $successfully_deleted_list;
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error deleting contributors: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "No contributor IDs provided for deletion.";
    }

    // Redirect to refresh the page
    header("Location: contributors_list.php");
    exit();
}

// Include other files after header operations
include '../admin/inc/header.php';
include '../db.php';

// Initialize selected contributors array in session if not exists
if (!isset($_SESSION['selectedContributorIds'])) {
    $_SESSION['selectedContributorIds'] = [];
}

// Query to fetch individual contributors data
$query = "SELECT 
            GROUP_CONCAT(c.id ORDER BY c.id) as id_ranges,
            b.title as book_title,
            CONCAT(w.firstname, ' ', w.middle_init, ' ', w.lastname) as writer_name,
            c.role,
            COUNT(c.id) as total_entries
          FROM contributors c
          JOIN books b ON c.book_id = b.id
          JOIN writers w ON c.writer_id = w.id
          GROUP BY b.title, w.id, c.role
          ORDER BY b.title";

$result = $conn->query($query);
$contributors_data = array();

while ($row = $result->fetch_assoc()) {
    // Format ID ranges
    $ids = explode(',', $row['id_ranges']);
    $ranges = [];
    $start = $ids[0];
    $prev = $ids[0];
    
    for ($i = 1; $i < count($ids); $i++) {
        if ($ids[$i] - $prev > 1) {
            $ranges[] = $start == $prev ? $start : "$start-$prev";
            $start = $ids[$i];
        }
        $prev = $ids[$i];
    }
    $ranges[] = $start == $prev ? $start : "$start-$prev";
    
    $row['id_ranges'] = implode(', ', $ranges);
    $contributors_data[] = $row;
}
?>

<div id="content" class="d-flex flex-column min-vh-100">
    <div class="container-fluid">
        <h1 class="h3 mb-2 text-gray-800">Contributors Management</h1>
        <p class="mb-4">Manage all individual contributors in the system.</p>

        <!-- Action Buttons -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <button id="deleteSelectedBtn" class="btn btn-outline-danger btn-sm" disabled>
                    Delete Selected (<span id="selectedDeleteCount">0</span>)
                </button>
            </div>
        </div>

        <!-- Contributors Table -->
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="dataTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th style="text-align: center;" id="checkboxHeader">
                            <input type="checkbox" id="selectAll">
                        </th>
                        <th style="text-align: center;">ID</th>
                        <th style="text-align: center;">Book Title</th>
                        <th style="text-align: center;">Writer Name</th>
                        <th style="text-align: center;">Role</th>
                        <th style="text-align: center;">Total Entries</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contributors_data as $row): ?>
                    <tr>
                        <td style='text-align: center;'><input type="checkbox" class="row-checkbox" value="<?php echo htmlspecialchars($row['id_ranges']); ?>"></td>
                        <td style='text-align: center;'><?php echo htmlspecialchars($row['id_ranges']); ?></td>
                        <td><?php echo htmlspecialchars($row['book_title']); ?></td>
                        <td><?php echo htmlspecialchars($row['writer_name']); ?></td>
                        <td style='text-align: center;'><?php echo htmlspecialchars($row['role']); ?></td>
                        <td style='text-align: center;'><?php echo htmlspecialchars($row['total_entries']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Footer -->
<?php include '../Admin/inc/footer.php'; ?>
<!-- End of Footer -->

<script>
$(document).ready(function () {
    var selectedIds = [];

    // Handle select all checkbox
    $('#selectAll').on('change', function () {
        var isChecked = $(this).prop('checked');
        $('.row-checkbox').prop('checked', isChecked);
        selectedIds = isChecked ? $('.row-checkbox').map(function () { return $(this).val(); }).get() : [];
        updateDeleteButton();
    });

    // Handle individual checkbox changes
    $('#dataTable tbody').on('change', '.row-checkbox', function () {
        var idRange = $(this).val();
        if ($(this).prop('checked')) {
            selectedIds.push(idRange);
        } else {
            selectedIds = selectedIds.filter(item => item !== idRange);
        }
        $('#selectAll').prop('checked', $('.row-checkbox:checked').length === $('.row-checkbox').length);
        updateDeleteButton();
    });

    // Update delete button state and count
    function updateDeleteButton() {
        const count = selectedIds.length;
        $('#deleteSelectedBtn span').text(count);
        $('#deleteSelectedBtn').prop('disabled', count === 0);
    }

    // Parse ID ranges into individual IDs
    function parseIdRanges(idRanges) {
        const parsedIds = [];
        idRanges.forEach(range => {
            range.split(',').forEach(part => {
                part = part.trim();
                if (part.includes('-')) {
                    const [start, end] = part.split('-').map(Number);
                    for (let i = start; i <= end; i++) {
                        parsedIds.push(i);
                    }
                } else {
                    parsedIds.push(Number(part));
                }
            });
        });
        return parsedIds;
    }

    // Handle bulk delete button click with loading style
    $('#deleteSelectedBtn').on('click', function () {
        if (selectedIds.length === 0) return;

        Swal.fire({
            title: 'Confirm Bulk Deletion',
            html: `Are you sure you want to delete <strong>${selectedIds.length}</strong> selected contributor(s)?<br><br>
                   <span class="text-danger">This action cannot be undone!</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete them!',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Deleting...',
                    html: 'Please wait while the selected contributors are being deleted.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                // Parse ID ranges into individual IDs
                const parsedIds = parseIdRanges(selectedIds);

                // Submit the form with parsed IDs
                $.ajax({
                    url: 'contributors_list.php',
                    method: 'POST',
                    data: {
                        action: 'delete',
                        ids: parsedIds
                    },
                    success: function() {
                        location.reload(); // Reload the page to show updated list and messages
                    },
                    error: function() {
                        Swal.fire({
                            title: 'Error!',
                            text: 'An error occurred while deleting the contributors. Please try again.',
                            icon: 'error',
                            confirmButtonColor: '#d33'
                        });
                    }
                });
            }
        });
    });

    // Display session messages using SweetAlert2
    <?php if (isset($_SESSION['success_message'])): ?>
        <?php
        $message = addslashes($_SESSION['success_message']);
        $detailsList = '';
        // Check for deleted contributor details
        if (isset($_SESSION['deleted_contributors_details']) && !empty($_SESSION['deleted_contributors_details'])) {
            $details = array_map(function($detail) {
                return htmlspecialchars($detail, ENT_QUOTES);
            }, $_SESSION['deleted_contributors_details']); // Sanitize details
            $detailsList = '<br><br><strong>Deleted Contributors:</strong><br>' . implode('<br>', $details);
            unset($_SESSION['deleted_contributors_details']); // Unset the deleted details list
        }
        ?>
        Swal.fire({
            title: 'Success!',
            html: '<?php echo $message . $detailsList; ?>', // Use html property for formatted content
            icon: 'success',
            confirmButtonColor: '#3085d6'
        });
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        Swal.fire({
            title: 'Error!',
            text: '<?php echo addslashes($_SESSION['error_message']); ?>',
            icon: 'error',
            confirmButtonColor: '#d33'
        });
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    $('#dataTable').DataTable({
        "dom": "<'row mb-3'<'col-sm-6'l><'col-sm-6 d-flex justify-content-end'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row mt-3'<'col-sm-5'i><'col-sm-7 d-flex justify-content-end'p>>",
        "pageLength": 10,
        "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        "responsive": true,
        "scrollX": true,
        "order": [[1, "asc"]],
        "columnDefs": [
            { "orderable": false, "targets": 0 } // Disable sorting for the checkbox column
        ],
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search..."
        }
    });
});
</script>
