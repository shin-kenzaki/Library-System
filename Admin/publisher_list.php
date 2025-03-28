<?php
session_start();

// Check if the user is logged in and has the appropriate admin role
if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['role'], ['Admin', 'Librarian', 'Assistant', 'Encoder'])) {
    header("Location: index.php");
    exit();
}

// Include the database connection first
include '../db.php';
include '../admin/inc/header.php';

// Initialize selected publishers array in session if not exists
if (!isset($_SESSION['selectedPublisherIds'])) {
    $_SESSION['selectedPublisherIds'] = [];
}

// Handle AJAX request to update selected publishers
if (isset($_POST['action']) && $_POST['action'] == 'updateSelectedPublishers') {
    $_SESSION['selectedPublisherIds'] = isset($_POST['selectedIds']) ? $_POST['selectedIds'] : [];
    echo json_encode(['success' => true, 'count' => count($_SESSION['selectedPublisherIds'])]);
    exit;
}

// Handle bulk action requests
if (isset($_POST['bulk_action']) && isset($_POST['selected_ids'])) {
    $selectedIds = $_POST['selected_ids'];
    $action = $_POST['bulk_action'];
    
    if (empty($selectedIds)) {
        $_SESSION['error_message'] = "No publishers selected for action.";
    } else {
        // Process bulk actions
        switch ($action) {
            case 'delete':
                // Start transaction to ensure data integrity
                $conn->begin_transaction();
                try {
                    $deleteCount = 0;
                    foreach ($selectedIds as $id) {
                        $id = (int)$id; // Ensure it's an integer
                        
                        // First delete all publication records that reference this publisher
                        $deletePublicationsSql = "DELETE FROM publications WHERE publisher_id = $id";
                        $conn->query($deletePublicationsSql);
                        
                        // Then delete the publisher
                        $deletePublisherSql = "DELETE FROM publishers WHERE id = $id";
                        if ($conn->query($deletePublisherSql)) {
                            $deleteCount++;
                        }
                    }
                    
                    // Commit the transaction
                    $conn->commit();
                    
                    if ($deleteCount > 0) {
                        $_SESSION['success_message'] = "$deleteCount publisher(s) deleted successfully. Related publication records were also removed.";
                    } else {
                        $_SESSION['error_message'] = "Failed to delete publishers.";
                    }
                } catch (Exception $e) {
                    // An error occurred, rollback the transaction
                    $conn->rollback();
                    $_SESSION['error_message'] = "Error deleting publishers: " . $e->getMessage();
                }
                break;
                
            // Add more bulk actions here if needed
        }
    }
    
    // Clear selected IDs after processing
    $_SESSION['selectedPublisherIds'] = [];
    
    // Redirect to refresh the page
    header("Location: publisher_list.php");
    exit;
}

// Count total publishers
$totalPublishersQuery = "SELECT COUNT(*) as total FROM publishers";
$totalPublishersResult = $conn->query($totalPublishersQuery);
$totalPublishers = $totalPublishersResult->fetch_assoc()['total'];

// Handle form submission to save publishers
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $companies = $_POST['publisher'];
    $places = $_POST['place'];

    $success = true;
    $valid_entries = 0;
    $existing_combinations = [];

    for ($i = 0; $i < count($companies); $i++) {
        $publisher = trim($conn->real_escape_string($companies[$i]));
        $place = trim($conn->real_escape_string($places[$i]));

        // Skip entries without publisher name or place
        if (empty($publisher) || empty($place)) {
            continue;
        }

        // Check for duplicate entries within the current submission
        $combination = $publisher . '|' . $place;
        if (in_array($combination, $existing_combinations)) {
            $success = false;
            echo "<script>alert('Duplicate entry found: $publisher in $place');</script>";
            break;
        }
        $existing_combinations[] = $combination;

        // Check if the exact publisher and place combination already exists in database
        $checkSql = "SELECT * FROM publishers WHERE publisher = '$publisher' AND place = '$place'";
        $checkResult = $conn->query($checkSql);

        if ($checkResult->num_rows > 0) {
            $success = false;
            echo "<script>alert('This publisher already exists in this location: $publisher in $place');</script>";
            break;
        }

        $sql = "INSERT INTO publishers (publisher, place) VALUES ('$publisher', '$place')";
        if ($conn->query($sql)) {
            $valid_entries++;
        } else {
            $success = false;
            break;
        }
    }

    if ($success && $valid_entries > 0) {
        echo "<script>alert('$valid_entries publisher(s) saved successfully'); window.location.href='publisher_list.php';</script>";
    } elseif ($valid_entries === 0) {
        echo "<script>alert('No valid publishers to save. Please provide both publisher name and place.');</script>";
    } else {
        echo "<script>alert('Failed to save publishers');</script>";
    }
}

// Get the search query if it exists
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';

// Fetch publishers data with proper sorting
$sql = "SELECT id, publisher, place FROM publishers";
if (!empty($searchQuery)) {
    $sql .= " WHERE publisher LIKE '%$searchQuery%' OR place LIKE '%$searchQuery%'";
}
$sql .= " ORDER BY id DESC";
$result = $conn->query($sql);
?>

<style>
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
    
    /* Add styles for publisher stats */
    .publisher-stats {
        display: flex;
        align-items: center;
    }
    
    .total-publishers-display {
        font-size: 0.9rem;
        color: #4e73df;
        font-weight: 600;
        margin-left: 10px;
    }
    
    /* Add button badge styles */
    .bulk-delete-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .bulk-delete-btn .badge {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
</style>

<!-- Main Content -->
<div id="content" class="d-flex flex-column min-vh-100">
    <div class="container-fluid">
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex flex-wrap align-items-center justify-content-between">
                <h6 class="m-0 font-weight-bold text-primary">Publishers List</h6>
                <div class="d-flex align-items-center">
                    <span class="mr-3 total-publishers-display">
                        Total Publishers: <?php echo number_format($totalPublishers); ?>
                    </span>
                    <button id="returnSelectedBtn" class="btn btn-danger btn-sm mr-2 bulk-delete-btn" disabled>
                        <i class="fas fa-trash"></i>
                        <span>Delete Selected</span>
                        <span class="badge badge-light ml-1">0</span>
                    </button>
                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addPublisherModal">Add Publisher</button>
                </div>
            </div>
            <div class="card-body px-0">
                <div class="table-responsive px-3">
                    <!-- Hidden form for bulk actions -->
                    <form id="bulkActionForm" method="POST" action="publisher_list.php">
                        <input type="hidden" name="bulk_action" id="bulk_action">
                        <div id="selected_ids_container"></div>
                    </form>
                    
                    <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th style="cursor: pointer; text-align: center;" id="checkboxHeader"><input type="checkbox" id="selectAll"></th>
                                <th class="text-center">ID</th>
                                <th class="text-center">Publisher</th>
                                <th class="text-center">Place of Publication</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Check if the query returned any rows
                            if ($result->num_rows > 0) {
                                // Loop through the rows and display them in the table
                                while ($row = $result->fetch_assoc()) {
                                    echo "<tr>
                                            <td style='text-align: center;'><input type='checkbox' class='row-checkbox' value='" . $row['id'] . "'></td>
                                            <td style='text-align: center;'>" . $row['id'] . "</td>
                                            <td style='text-align: center;'>" . $row['publisher'] . "</td>
                                            <td style='text-align: center;'>" . $row['place'] . "</td>
                                          </tr>";
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <!-- /.container-fluid -->
</div>
<!-- End of Main Content -->

<!-- Add Publisher Modal -->
<div class="modal fade" id="addPublisherModal" tabindex="-1" role="dialog" aria-labelledby="addPublisherModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addPublisherModalLabel">Add Publishers</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="addPublishersForm" method="POST" action="publisher_list.php">
                    <div id="publishersContainer">
                        <div class="publisher-entry mb-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <input type="text" name="publisher[]" class="form-control mb-2" placeholder="Publisher" required>
                                    <input type="text" name="place[]" class="form-control mb-2" placeholder="Place" required>
                                </div>
                                <button type="button" class="btn btn-danger ml-2 remove-publisher" style="height: 38px;">×</button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary" id="addMorePublishers">Add More Publishers</button>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="savePublishers">Save Publishers</button>
            </div>
        </div>
    </div>
</div>

<!-- Context Menu -->
<div id="contextMenu" class="dropdown-menu" style="display:none; position:absolute;">
    <a class="dropdown-item" href="#" id="updatePublisher">Update</a>
    <a class="dropdown-item" href="#" id="deletePublisher">Delete</a>
</div>

<!-- Footer -->
<?php include '../Admin/inc/footer.php' ?>
<!-- End of Footer -->

<!-- Scroll to Top Button-->
<a class="scroll-to-top rounded" href="#page-top">
    <i class="fas fa-angle-up"></i>
</a>

<script>
$(document).ready(function () {
    var table = $('#dataTable').DataTable({
        "dom": "<'row mb-3'<'col-sm-6'l><'col-sm-6 d-flex justify-content-end'f>>" +
               "<'row'<'col-sm-12'tr>>" +
               "<'row mt-3'<'col-sm-5'i><'col-sm-7 d-flex justify-content-end'p>>",
        "pageLength": 10,
        "responsive": false,
        "scrollX": true,
        "order": [[1, "desc"]], // Sort by ID in descending order
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": "Search..."
        },
        "columnDefs": [
            { 
                "orderable": false, 
                "searchable": false,
                "targets": 0 
            }
        ],
        "initComplete": function() {
            $('#dataTable_filter input').addClass('form-control form-control-sm');
            $('#dataTable_filter').addClass('d-flex align-items-center');
            $('#dataTable_filter label').append('<i class="fas fa-search ml-2"></i>');
            $('.dataTables_paginate .paginate_button').addClass('btn btn-sm btn-outline-primary mx-1');
        }
    });

    // Add window resize handler
    $(window).on('resize', function () {
        table.columns.adjust();
    });

    // Add more publishers functionality
    $('#addMorePublishers').click(function() {
        var publisherEntry = `
            <div class="publisher-entry mb-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <input type="text" name="publisher[]" class="form-control mb-2" placeholder="Publisher" required>
                        <input type="text" name="place[]" class="form-control mb-2" placeholder="Place" required>
                    </div>
                    <button type="button" class="btn btn-danger ml-2 remove-publisher" style="height: 38px;">×</button>
                </div>
            </div>`;
        $('#publishersContainer').append(publisherEntry);
    });

    // Remove publisher functionality
    $(document).on('click', '.remove-publisher', function() {
        if ($('.publisher-entry').length > 1) {
            $(this).closest('.publisher-entry').remove();
        } else {
            alert('At least one publisher entry must remain.');
        }
    });

    var selectedPublisherId;

    // Show context menu on right-click
    $('#dataTable tbody').on('contextmenu', 'tr', function(e) {
        e.preventDefault();
        $('#dataTable tbody tr').removeClass('context-menu-active');
        $(this).addClass('context-menu-active');
        selectedPublisherId = $(this).find('td:nth-child(2)').text();
        $('#contextMenu').css({
            display: 'block',
            left: e.pageX,
            top: e.pageY
        });
        return false;
    });

    // Hide context menu on click outside
    $(document).click(function() {
        $('#contextMenu').hide();
    });

    // Handle context menu actions
    $('#updatePublisher').click(function() {
        console.log('Update publisher clicked');
        window.location.href = `update_publisher.php?publisher_id=${selectedPublisherId}`;
    });

    $('#deletePublisher').click(function() {
        var row = $('#dataTable tbody tr.context-menu-active');
        var publisherId = row.find('td:nth-child(2)').text();
        var publisher = row.find('td:nth-child(3)').text();
        var place = row.find('td:nth-child(4)').text();

        if (confirm(`Are you sure you want to delete this publisher?\n\nID: ${publisherId}\nPublisher: ${publisher}\nPlace: ${place}\n\nThis will also delete all publication records for this publisher.`)) {
            $.post('delete_publisher.php', { publisher_id: publisherId }, function(response) {
                alert(response.message);
                location.reload();
            }, 'json');
        }
    });

    // Save updated publisher functionality
    $('#saveUpdatedPublisher').click(function() {
        $('#updatePublisherForm').submit();
    });

    // Display success message if available
    var successMessage = "<?php echo isset($_SESSION['success_message']) ? $_SESSION['success_message'] : ''; ?>";
    if (successMessage) {
        alert(successMessage);
        <?php unset($_SESSION['success_message']); ?>
    }

    // Update the save publishers functionality
    $('#savePublishers').click(function(e) {
        e.preventDefault();
        
        // Validate that at least one publisher has both name and place
        var hasValidPublisher = false;
        $('.publisher-entry').each(function() {
            var publisher = $(this).find('input[name="publisher[]"]').val().trim();
            var place = $(this).find('input[name="place[]"]').val().trim();
            if (publisher && place) {
                hasValidPublisher = true;
                return false; // break the loop
            }
        });

        if (!hasValidPublisher) {
            alert('Please provide at least one publisher with both name and place.');
            return;
        }

        // Submit the form
        $('#addPublishersForm').submit();
    });

    // Handle select all checkbox and header click
    $('#selectAll, #checkboxHeader').on('click', function(e) {
        if ($(this).is('th')) {
            // If clicking the header cell, toggle the checkbox
            const checkbox = $('#selectAll');
            checkbox.prop('checked', !checkbox.prop('checked'));
        }
        // Apply the checkbox state to all row checkboxes
        $('.row-checkbox').prop('checked', $('#selectAll').prop('checked'));
        // Prevent event bubbling when clicking the checkbox itself
        if ($(this).is('input')) {
            e.stopPropagation();
        }
        
        // Update the selectedIds array
        if ($('#selectAll').prop('checked')) {
            $('.row-checkbox').each(function() {
                var id = $(this).val();
                if (!selectedIds.includes(id)) {
                    selectedIds.push(id);
                }
            });
        } else {
            selectedIds = [];
        }
        
        saveSelectedIds();
    });

    // Handle individual checkbox changes
    $('#dataTable tbody').on('change', '.row-checkbox', function() {
        var id = $(this).val();
        
        if ($(this).prop('checked')) {
            if (!selectedIds.includes(id)) {
                selectedIds.push(id);
            }
        } else {
            selectedIds = selectedIds.filter(item => item !== id);
        }
        
        // Update select all checkbox
        updateSelectAllCheckbox();
        saveSelectedIds();
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
        // Ignore clicks on checkbox itself and on action buttons
        if (e.target.type === 'checkbox' || $(e.target).hasClass('btn') || $(e.target).parent().hasClass('btn')) {
            return;
        }
        
        // Find the checkbox within this row and toggle it
        var checkbox = $(this).find('.row-checkbox');
        checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
    });

    // Remove the old handlers that might interfere
    $('#dataTable tbody').off('click', 'tr');
    
    // Track selected rows
    var selectedIds = <?php echo json_encode($_SESSION['selectedPublisherIds'] ?? []); ?>;
    
    // Initialize checkboxes based on session data
    function initializeCheckboxes() {
        $('.row-checkbox').each(function() {
            var id = $(this).val();
            if (selectedIds.includes(id)) {
                $(this).prop('checked', true);
            }
        });
        
        // Update select all checkbox
        updateSelectAllCheckbox();
    }
    
    // Update the select all checkbox state
    function updateSelectAllCheckbox() {
        var allChecked = $('.row-checkbox:checked').length === $('.row-checkbox').length && $('.row-checkbox').length > 0;
        $('#selectAll').prop('checked', allChecked);
    }
    
    // Save selected IDs to session via AJAX
    function saveSelectedIds() {
        updateDeleteButton(); // Add this line
        $.ajax({
            url: 'publisher_list.php',
            type: 'POST',
            data: {
                action: 'updateSelectedPublishers',
                selectedIds: selectedIds
            },
            dataType: 'json',
            success: function(response) {
                console.log('Saved ' + response.count + ' selected publishers');
            }
        });
    }
    
    // Initialize checkboxes on page load
    initializeCheckboxes();
    
    // Handle row clicks to select checkbox
    $('#dataTable tbody').on('click', 'tr', function(e) {
        // Ignore clicks on checkbox itself and on action buttons
        if (e.target.type === 'checkbox' || $(e.target).hasClass('btn') || $(e.target).parent().hasClass('btn')) {
            return;
        }
        
        var checkbox = $(this).find('.row-checkbox');
        checkbox.prop('checked', !checkbox.prop('checked')).trigger('change');
    });
    
    // Handle checkbox change events
    $('#dataTable tbody').on('change', '.row-checkbox', function() {
        var id = $(this).val();
        
        if ($(this).prop('checked')) {
            if (!selectedIds.includes(id)) {
                selectedIds.push(id);
            }
        } else {
            selectedIds = selectedIds.filter(item => item !== id);
        }
        
        updateSelectAllCheckbox();
        saveSelectedIds();
    });
    
    // Handle select all checkbox
    $('#selectAll').on('change', function() {
        var isChecked = $(this).prop('checked');
        
        $('.row-checkbox').each(function() {
            $(this).prop('checked', isChecked);
            
            var id = $(this).val();
            if (isChecked && !selectedIds.includes(id)) {
                selectedIds.push(id);
            }
        });
        
        if (!isChecked) {
            selectedIds = [];
        }
        
        saveSelectedIds();
    });
    
    // Handle header cell click for select all
    $('#checkboxHeader').on('click', function(e) {
        // If clicking directly on the checkbox, don't execute this
        if (e.target.type === 'checkbox') return;
        
        $('#selectAll').trigger('click');
    });
    
    // Handle bulk actions
    $('.bulk-action').on('click', function(e) {
        e.preventDefault();
        
        var action = $(this).data('action');
        
        if (selectedIds.length === 0) {
            alert('Please select at least one publisher to perform this action.');
            return;
        }
        
        var confirmMessage = 'Are you sure you want to ' + action + ' ' + selectedIds.length + ' selected publisher(s)?';
        if (action === 'delete') {
            confirmMessage += '\n\nThis will also delete all publication records for these publishers.';
        }
        
        if (confirm(confirmMessage)) {
            // Clear previous inputs
            $('#selected_ids_container').empty();
            
            // Add hidden inputs for each selected ID
            selectedIds.forEach(function(id) {
                $('#selected_ids_container').append('<input type="hidden" name="selected_ids[]" value="' + id + '">');
            });
            
            // Set the action and submit
            $('#bulk_action').val(action);
            $('#bulkActionForm').submit();
        }
    });

    // Modified checkbox handling - Header cell click handler
    $(document).on('click', 'thead th:first-child', function(e) {
        // If the click was directly on the checkbox, don't execute this handler
        if (e.target.type === 'checkbox') return;
        
        // Find and click the checkbox
        var checkbox = $('#selectAll');
        checkbox.prop('checked', !checkbox.prop('checked'));
        $('.row-checkbox').prop('checked', checkbox.prop('checked'));
        
        // Update selectedIds array
        if (checkbox.prop('checked')) {
            $('.row-checkbox').each(function() {
                var id = $(this).val();
                if (!selectedIds.includes(id)) {
                    selectedIds.push(id);
                }
            });
        } else {
            selectedIds = [];
        }
        saveSelectedIds();
    });

    // Remove old header click handlers
    $('#checkboxHeader').off('click');
    $('#selectAll, #checkboxHeader').off('click');

    // Keep existing checkbox change handlers

    function updateDeleteButton() {
        const count = selectedIds.length;
        const deleteBtn = $('.bulk-delete-btn');
        deleteBtn.find('.badge').text(count);
        deleteBtn.prop('disabled', count === 0);
    }

    // Replace the bulk-action click handler with this:
    $('.bulk-delete-btn').on('click', function(e) {
        e.preventDefault();
        
        if (selectedIds.length === 0) {
            alert('Please select at least one publisher to delete.');
            return;
        }
        
        var confirmMessage = 'Are you sure you want to delete ' + selectedIds.length + ' selected publisher(s)?\n\nThis will also delete all publication records for these publishers.';
        
        if (confirm(confirmMessage)) {
            $('#selected_ids_container').empty();
            selectedIds.forEach(function(id) {
                $('#selected_ids_container').append('<input type="hidden" name="selected_ids[]" value="' + id + '">');
            });
            $('#bulk_action').val('delete');
            $('#bulkActionForm').submit();
        }
    });
});
</script>