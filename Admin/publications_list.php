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
            $fetchDetailsSql = "SELECT p.id, b.title as book_title, pb.publisher, pb.place, YEAR(p.publish_date) as year 
                                FROM publications p
                                JOIN books b ON p.book_id = b.id
                                JOIN publishers pb ON p.publisher_id = pb.id
                                WHERE p.id IN ($idsString)";
            $detailsResult = $conn->query($fetchDetailsSql);
            if ($detailsResult && $detailsResult->num_rows > 0) {
                while ($row = $detailsResult->fetch_assoc()) {
                    $deleted_details[$row['id']] = htmlspecialchars('"' . $row['book_title'] . '" by ' . $row['publisher'] . ' (' . $row['place'] . ', ' . $row['year'] . ')');
                }
            }
        }

        $conn->begin_transaction();
        try {
            $deleteCount = 0;
            $successfully_deleted_list = [];
            $stmt = $conn->prepare("DELETE FROM publications WHERE id = ?");
            
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
            echo json_encode([
                'success' => true, 
                'message' => "$deleteCount publication record(s) deleted successfully.",
                'deleted_items' => $successfully_deleted_list // Send back the list
            ]);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => "Error deleting publications: " . $e->getMessage()]);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => "No publication IDs provided for deletion."]);
        exit();
    }
}

// Include other files after header operations
include '../admin/inc/header.php';
include '../db.php';

// Initialize selected publications array in session if not exists
if (!isset($_SESSION['selectedPublicationIds'])) {
    $_SESSION['selectedPublicationIds'] = [];
}

// Query to fetch publications data
$query = "SELECT 
            GROUP_CONCAT(p.id ORDER BY p.id) AS id_ranges,
            pb.publisher,
            pb.place,
            b.title AS book_title,
            GROUP_CONCAT(DISTINCT YEAR(p.publish_date) ORDER BY YEAR(p.publish_date)) AS publish_years,
            COUNT(p.id) AS total_books
          FROM publications p 
          JOIN books b ON p.book_id = b.id 
          JOIN publishers pb ON p.publisher_id = pb.id
          GROUP BY pb.publisher, pb.place, b.title  -- Group by publisher, place, and title
          ORDER BY pb.publisher, b.title";

$result = $conn->query($query);
$publications_data = array();

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
    $publications_data[] = $row;
}
?>

<!-- Main Content -->
    <div class="container-fluid">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
                <h6 class="m-0 font-weight-bold text-primary">Publications List</h6>
                <div class="d-flex align-items-center">
                    <button id="returnSelectedBtn" class="btn btn-danger btn-sm mr-2 bulk-delete-btn" disabled>
                        <i class="fas fa-trash"></i>
                        <span>Delete Selected</span>
                        <span class="badge badge-light ml-1">0</span>
                    </button>
                    <button type="button" class="btn btn-info btn-sm ml-2" data-toggle="modal" data-target="#instructionsModal">
                        <i class="fas fa-question-circle"></i> Instructions
                    </button>
                </div>
            </div>
            <div class="card-body px-0">
                <div class="table-responsive px-3">
                    <table class="table table-bordered table-striped" id="dataTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th style="text-align: center;">Select</th>
                                <th style='text-align: center;'>ID</th>
                                <th style='text-align: center;'>Publisher</th>
                                <th style='text-align: center;'>Place</th>
                                <th style='text-align: center;'>Book Title</th>
                                <th style='text-align: center;'>Years</th>
                                <th style='text-align: center;'>Total Books</th>
                            </tr>
                        </thead>
                        <tbody id="publicationsTableBody">
                            <?php foreach ($publications_data as $row): ?>
                            <tr>
                                <td style='text-align: center;'><input type="checkbox" class="row-checkbox" value="<?php echo htmlspecialchars($row['id_ranges']); ?>"></td>
                                <td style='text-align: center;'><?php echo htmlspecialchars($row['id_ranges']); ?></td>
                                <td><?php echo htmlspecialchars($row['publisher']); ?></td>
                                <td style='text-align: center;'><?php echo htmlspecialchars($row['place']); ?></td>
                                <td><?php echo htmlspecialchars($row['book_title']); ?></td>
                                <td style='text-align: center;'><?php echo htmlspecialchars($row['publish_years']); ?></td>
                                <td style='text-align: center;'><?php echo htmlspecialchars($row['total_books']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../Admin/inc/footer.php'; ?>

<!-- Hidden form for bulk delete -->
<form id="bulkActionForm" method="POST" action="publications_list.php">
    <input type="hidden" name="action" value="delete">
    <div id="selected_ids_container"></div>
</form>

<!-- Instructions Modal -->
<div class="modal fade" id="instructionsModal" tabindex="-1" role="dialog" aria-labelledby="instructionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="instructionsModalLabel">
                    <i class="fas fa-info-circle mr-2"></i>Publications Management Instructions
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold">Understanding Publications</h6>
                    </div>
                    <div class="card-body">
                        <p>In the library system, a publication record represents the publishing details of a book:</p>
                        <ul>
                            <li><strong>Book</strong>: The book title this publication information belongs to</li>
                            <li><strong>Publisher</strong>: The publishing company or organization</li>
                            <li><strong>Publication Date</strong>: When the book was published</li>
                            <li><strong>Copyright Date</strong>: The copyright year (may differ from publication date)</li>
                            <li><strong>Place of Publication</strong>: City/location where the book was published</li>
                            <li><strong>Edition</strong>: Publication edition information (e.g., 1st, 2nd, Revised)</li>
                        </ul>
                        <p>Each book must have at least one publication record. Books with multiple editions or publishers may have multiple publication records.</p>
                    </div>
                </div>
                
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold">Managing Publications</h6>
                    </div>
                    <div class="card-body">
                        <p>This page allows you to manage publication information for all books in the library:</p>
                        <ul>
                            <li><strong>View Publications</strong>: The table displays all publication records with details</li>
                            <li><strong>Select Publications</strong>: Click on a row to select it or use the checkbox in the first column</li>
                            <li><strong>Multiple Selection</strong>: Use checkboxes to select multiple publications for bulk actions</li>
                            <li><strong>Bulk Delete</strong>: Select multiple publications and use the "Delete Selected" button to remove them</li>
                            <li><strong>Search and Filter</strong>: Use the search box to quickly find publications by any field</li>
                        </ul>
                        <p>The table can be sorted by clicking on column headers and filtered using the search field at the top right.</p>
                    </div>
                </div>
                
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold">Using Bulk Actions</h6>
                    </div>
                    <div class="card-body">
                        <ol>
                            <li>Select publications by clicking their checkboxes (or click the header checkbox to select all)</li>
                            <li>The number of selected items will appear on the "Delete Selected" button</li>
                            <li>Click the "Delete Selected" button to remove all selected publications</li>
                            <li>Confirm the deletion in the confirmation dialog that appears</li>
                        </ol>
                        <p class="text-danger"><strong>Note:</strong> Deleting publication records cannot be undone, so use this feature carefully.</p>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="m-0 font-weight-bold">Best Practices</h6>
                    </div>
                    <div class="card-body">
                        <ul>
                            <li><strong>Complete Information</strong>: Include as much publication information as available</li>
                            <li><strong>Date Formats</strong>: Use consistent date formats (YYYY or YYYY-MM-DD)</li>
                            <li><strong>Multiple Editions</strong>: Create separate publication records for different editions</li>
                            <li><strong>Location Format</strong>: For place of publication, use "City, State/Country" format</li>
                            <li><strong>Publisher Selection</strong>: Always check if the publisher already exists before creating a new one</li>
                            <li><strong>Standard Abbreviations</strong>: Use standard library abbreviations for edition information</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Remove context menu styles */

/* Add checkbox cell styles */
.checkbox-cell {
    cursor: pointer;
    text-align: center;
    vertical-align: middle;
    width: 50px !important; /* Fixed width for uniformity */
}
.checkbox-cell:hover {
    background-color: rgba(0, 123, 255, 0.1);
}
.checkbox-cell input[type="checkbox"] {
    margin: 0 auto;
    display: block;
}

/* Add responsive table styles */
.table-responsive {
    width: 100%;
    margin-bottom: 1rem;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

#dataTable th,
#dataTable td {
    min-width: 100px;
    white-space: nowrap;
}

#dataTable {
    width: 100% !important;
}

.table td, .table th {
    white-space: nowrap;
}

.bulk-delete-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.bulk-delete-btn .badge {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Improved checkbox centering */
#dataTable th:first-child,
#dataTable td:first-child {
    text-align: center;
    vertical-align: middle;
    width: 40px !important;
    min-width: 40px !important;
    max-width: 40px !important;
    box-sizing: border-box;
    padding: 0.75rem 0.5rem;
}

#checkboxHeader {
    width: 40px !important;
    min-width: 40px !important;
    max-width: 40px !important;
    padding: 0.75rem 0.5rem !important;
    box-sizing: border-box;
}

#dataTable input[type="checkbox"] {
    margin: 0 auto;
    display: block;
    width: 16px;
    height: 16px;
}

.checkbox-wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100%;
    width: 100%;
    padding: 0 !important;
}

/* Add selected row styling */
#dataTable tbody tr.selected {
    background-color: rgba(0, 123, 255, 0.1) !important;
}

/* Override striped table styling for selected rows */
#dataTable.table-striped tbody tr.selected:nth-of-type(odd),
#dataTable.table-striped tbody tr.selected:nth-of-type(even) {
    background-color: rgba(0, 123, 255, 0.1) !important;
}
</style>

<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    var table = $('#dataTable').DataTable({
        "dom": "<'row mb-3'<'col-sm-6'l><'col-sm-6 d-flex justify-content-end'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row mt-3'<'col-sm-5'i><'col-sm-7 d-flex justify-content-end'p>>",
        "pageLength": 10,
        "lengthMenu": [[10, 25, 50, 100, 500, -1], [10, 25, 50, 100, 500, "All"]],
        "responsive": false,
        "scrollX": true,
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search..."
        },
        "order": [[2, "asc"], [4, "asc"]], // Sort by publisher then book title
        "columnDefs": [
            { 
                "orderable": false, 
                "searchable": false,
                "targets": 0,
                "className": "checkbox-cell" // Add checkbox-cell class to first column
            },
            {
                "targets": 1, // ID Range column
                "visible": true,
                "searchable": true // Allow searching ID ranges
            }
        ],
        "initComplete": function() {
            $('#dataTable_filter input').addClass('form-control form-control-sm');
            $('#dataTable_filter').addClass('d-flex align-items-center');
            $('#dataTable_filter label').append('<i class="fas fa-search ml-2"></i>');
            $('.dataTables_paginate .paginate_button').addClass('btn btn-sm btn-outline-primary mx-1');
        }
    });

    // Add a confirmation dialog when "All" option is selected
    $('#dataTable').on('length.dt', function ( e, settings, len ) {
        if (len === -1) {
            Swal.fire({
                title: 'Display All Entries?',
                text: "Are you sure you want to display all entries? This may cause performance issues.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, display all!'
            }).then((result) => {
                if (result.dismiss === Swal.DismissReason.cancel) {
                    // If the user cancels, reset the page length to the previous value
                    table.page.len(settings._iDisplayLength).draw();
                }
            });
        }
    });

    // Add cell click handler for the checkbox column
    $('#dataTable tbody').on('click', 'td:first-child', function(e) {
        // If the click was directly on the checkbox, don't execute this handler
        if (e.target.type === 'checkbox') return;
        
        // Find the checkbox within this cell and toggle it
        var checkbox = $(this).find('.row-checkbox');
        checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
    });

    // Add row click handler to check the row checkbox
    $('#dataTable tbody').on('click', 'tr', function(e) {
        // If the click was directly on the checkbox, don't execute this handler
        if (e.target.type === 'checkbox') return;
        
        // Find the checkbox within this row and toggle it
        var checkbox = $(this).find('.row-checkbox');
        checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
    });

    // Add window resize handler
    $(window).on('resize', function () {
        table.columns.adjust();
    });

    function updateDeleteButton() {
        const count = $('.row-checkbox:checked').length;
        const deleteBtn = $('.bulk-delete-btn');
        deleteBtn.find('.badge').text(count);
        deleteBtn.prop('disabled', count === 0);
    }

    // Handle checkbox changes
    $('#dataTable').on('change', '.row-checkbox', function() {
        updateDeleteButton();
        updateRowSelectionState();
    });

    // Handle bulk delete
    $('.bulk-delete-btn').on('click', function(e) {
        e.preventDefault();
        
        const selectedRanges = [];
        let selectedPublicationsInfo = []; // Store details for confirmation
        let pubCounts = {}; // Temporary map { 'Publisher (Place)': count }

        $('.row-checkbox:checked').each(function() {
            const row = $(this).closest('tr');
            const idRange = row.find('td:eq(1)').text();
            const publisher = row.find('td:eq(2)').text();
            const place = row.find('td:eq(3)').text();
            const bookTitle = row.find('td:eq(4)').text(); // Get book title
            
            selectedRanges.push(idRange);

            // Count items per publisher/place for the confirmation message
            let currentCount = 0;
            idRange.split(',').forEach(item => {
                item = item.trim();
                if (item.includes('-')) {
                    const [start, end] = item.split('-').map(Number);
                    currentCount += end - start + 1;
                } else if (item) {
                    currentCount++;
                }
            });

            const key = `${publisher} (${place}) - "${bookTitle}"`; // Group by publisher, place, and book
            if (!pubCounts[key]) {
                pubCounts[key] = 0;
            }
            pubCounts[key] += currentCount;
        });

        // Format counts for display
        for (const key in pubCounts) {
            selectedPublicationsInfo.push(`- ${key} (${pubCounts[key]} record(s))`);
        }

        if (selectedRanges.length === 0) {
            Swal.fire({
                title: 'No Selection',
                text: 'Please select at least one publication group to delete.',
                icon: 'warning',
                confirmButtonColor: '#ffc107'
            });
            return;
        }

        let totalItems = 0;
        selectedRanges.forEach(range => {
            range.split(',').forEach(item => {
                item = item.trim();
                if (item.includes('-')) {
                    const [start, end] = item.split('-').map(Number);
                    totalItems += end - start + 1;
                } else if (item) {
                    totalItems++;
                }
            });
        });

        Swal.fire({
            title: 'Confirm Deletion',
            html: `Are you sure you want to delete <strong>${totalItems}</strong> selected publication record(s)?<br><br>
                   This action will affect the following:<br>
                   <div style="text-align: left; max-height: 150px; overflow-y: auto; margin-top: 10px; padding-left: 20px;">
                       ${selectedPublicationsInfo.join('<br>')}
                   </div><br>
                   <span class="text-danger">This action cannot be undone.</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete them!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#selected_ids_container').empty();
                selectedRanges.forEach(range => {
                    range.split(',').forEach(item => {
                        item = item.trim();
                        if (item.includes('-')) {
                            const [start, end] = item.split('-').map(Number);
                            for (let id = start; id <= end; id++) {
                                $('#selected_ids_container').append(
                                    `<input type="hidden" name="ids[]" value="${id}">`
                                );
                            }
                        } else if (item) {
                            $('#selected_ids_container').append(
                                `<input type="hidden" name="ids[]" value="${item}">`
                            );
                        }
                    });
                });

                // Submit form with AJAX
                $.ajax({
                    url: 'publications_list.php',
                    method: 'POST',
                    data: $('#bulkActionForm').serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                             let successHtml = response.message;
                            if (response.deleted_items && response.deleted_items.length > 0) {
                                successHtml += `<br><br><strong>Deleted Items:</strong><br>
                                                <div style="text-align: left; max-height: 150px; overflow-y: auto; margin-top: 5px; font-size: 0.9em;">
                                                    ${response.deleted_items.join('<br>')}
                                                </div>`;
                            }
                            Swal.fire({
                                title: 'Deleted!',
                                html: successHtml,
                                icon: 'success',
                                confirmButtonColor: '#3085d6'
                            }).then(() => {
                                location.reload(); // Reload page
                            });
                        } else {
                            Swal.fire({
                                title: 'Error!',
                                text: response.message || 'An unknown error occurred.',
                                icon: 'error',
                                confirmButtonColor: '#d33'
                            });
                        }
                    },
                     error: function(xhr) { // Add error handling for AJAX request itself
                         Swal.fire({
                            title: 'Request Failed!',
                            text: 'Could not reach the server. Please try again. Status: ' + xhr.statusText,
                            icon: 'error',
                            confirmButtonColor: '#d33'
                        });
                    }
                });
            }
        });
    });
    
    // Make the entire checkbox cell clickable
    $(document).on('click', '.checkbox-cell', function(e) {
        // Prevent triggering if clicking directly on the checkbox
        if (e.target.type !== 'checkbox') {
            const checkbox = $(this).find('input[type="checkbox"]');
            checkbox.prop('checked', !checkbox.prop('checked'));
            checkbox.trigger('change'); // Trigger change event
        }
    });

    // Remove the old click handlers that might interfere
    $('#dataTable tbody').off('click', 'td:first-child');
    $('#dataTable tbody').off('click', 'tr');

    // Wrap checkboxes in centering div for better alignment
    $('#dataTable tbody tr td:first-child').each(function() {
        // Only add wrapper if not already wrapped
        if (!$(this).find('.checkbox-wrapper').length) {
            const checkbox = $(this).find('input[type="checkbox"]');
            checkbox.wrap('<div class="checkbox-wrapper"></div>');
        }
    });
    
    // Make sure newly added rows also get wrapper
    table.on('draw', function() {
        $('#dataTable tbody tr td:first-child').each(function() {
            if (!$(this).find('.checkbox-wrapper').length) {
                const checkbox = $(this).find('input[type="checkbox"]');
                checkbox.wrap('<div class="checkbox-wrapper"></div>');
            }
        });
    });

    // Add row click handler to toggle the row checkbox
    $('#dataTable tbody').on('click', 'tr', function(e) {
        // Ignore clicks on checkbox itself or action buttons
        if (e.target.type === 'checkbox' || $(e.target).hasClass('btn') || $(e.target).parent().hasClass('btn')) {
            return;
        }

        // Find the checkbox within this row and toggle it
        var checkbox = $(this).find('.row-checkbox');
        checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
    });

    // Add selected class styling
    $('<style>').text(`
        #dataTable tbody tr.selected {
            background-color: rgba(0, 123, 255, 0.1) !important;
        }
    `).appendTo('head');

    // Function to update row selection state
    function updateRowSelectionState() {
        $('#dataTable tbody tr').each(function() {
            const checkbox = $(this).find('.row-checkbox');
            const isChecked = checkbox.prop('checked');
            
            // Clear any existing row styling first
            $(this).removeClass('selected');
            
            // Apply selected class if checkbox is checked
            if (isChecked) {
                $(this).addClass('selected');
            }
        });
        
        // Also update delete button state
        const count = $('.row-checkbox:checked').length;
        $('.bulk-delete-btn .badge').text(count);
        $('.bulk-delete-btn').prop('disabled', count === 0);
    }

    // Listen for checkbox state changes to update row selection visuals
    $('#dataTable tbody').on('change', '.row-checkbox', function() {
        updateRowSelectionState();
    });
    
    // Call this function after DataTable is drawn
    table.on('draw', function() {
        updateRowSelectionState();
    });

    // Initial call to update row selection state
    updateRowSelectionState();
});
</script>