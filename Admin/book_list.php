<?php
session_start();

// Check if the user is logged in and has the appropriate admin role
if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['role'], ['Admin', 'Librarian', 'Assistant', 'Encoder'])) {
    header("Location: index.php");
    exit();
}

include '../db.php'; // Database connection

// Count total books in database
$totalBooksQuery = "SELECT COUNT(*) as total FROM books";
$totalBooksResult = $conn->query($totalBooksQuery);
$totalBooks = $totalBooksResult->fetch_assoc()['total'];

// Handle book deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_book_id'])) {
    $bookId = intval($_POST['delete_book_id']);

    // Start transaction
    $conn->begin_transaction();
    try {
        // Delete related contributors first
        $contribQuery = "DELETE FROM contributors WHERE book_id = ?";
        $contribStmt = $conn->prepare($contribQuery);
        $contribStmt->bind_param('i', $bookId);
        $contribStmt->execute();

        // Delete related publications first
        $pubQuery = "DELETE FROM publications WHERE book_id = ?";
        $pubStmt = $conn->prepare($pubQuery);
        $pubStmt->bind_param('i', $bookId);
        $pubStmt->execute();

        // Delete the book
        $bookQuery = "DELETE FROM books WHERE id = ?";
        $bookStmt = $conn->prepare($bookQuery);
        $bookStmt->bind_param('i', $bookId);
        $bookStmt->execute();

        if ($bookStmt->affected_rows > 0) {
            $conn->commit();
            $response = ['message' => 'Book and all related records deleted successfully!'];
        } else {
            $conn->rollback();
            $response = ['message' => 'Failed to delete the book.'];
        }
    } catch (Exception $e) {
        $conn->rollback();
        $response = ['message' => 'Error: ' . $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}

// Helper function to expand ID ranges into array of individual IDs
function expandIdRanges($idRanges) {
    $ids = [];
    $ranges = explode(',', $idRanges);
    
    foreach ($ranges as $range) {
        $range = trim($range);
        if (strpos($range, '-') !== false) {
            list($start, $end) = explode('-', $range);
            $ids = array_merge($ids, range((int)$start, (int)$end));
        } else {
            $ids[] = (int)$range;
        }
    }
    
    return array_unique($ids);
}

// Handle batch book deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_delete'])) {
    $bookIdRanges = json_decode($_POST['book_ids']);
    $allBookIds = [];
    
    // Expand all ID ranges into individual IDs
    foreach ($bookIdRanges as $idRange) {
        $allBookIds = array_merge($allBookIds, expandIdRanges($idRange));
    }
    
    $success = true;
    $deleted = 0;

    // Start transaction
    $conn->begin_transaction();
    try {
        foreach ($allBookIds as $bookId) {
            // Delete related contributors first
            $contribStmt = $conn->prepare("DELETE FROM contributors WHERE book_id = ?");
            $contribStmt->bind_param('i', $bookId);
            $contribStmt->execute();

            // Delete related publications
            $pubStmt = $conn->prepare("DELETE FROM publications WHERE book_id = ?");
            $pubStmt->bind_param('i', $bookId);
            $pubStmt->execute();

            // Delete the book
            $bookStmt = $conn->prepare("DELETE FROM books WHERE id = ?");
            $bookStmt->bind_param('i', $bookId);
            $bookStmt->execute();
            
            if ($bookStmt->affected_rows > 0) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $conn->commit();
            $response = [
                'success' => true,
                'message' => "$deleted copy/copies deleted successfully!"
            ];
        } else {
            $conn->rollback();
            $response = [
                'success' => false,
                'message' => 'No books were deleted.'
            ];
        }
    } catch (Exception $e) {
        $conn->rollback();
        $response = [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }

    echo json_encode($response);
    exit();
}

// Check for success message
$successMessage = '';
if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Initialize search query
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';

// Modified query to group books by title with better grouping
$query = "SELECT 
    title,
    GROUP_CONCAT(DISTINCT id ORDER BY id) as id_range,
    GROUP_CONCAT(DISTINCT accession ORDER BY accession) as accession_range,
    GROUP_CONCAT(CONCAT(call_number, '|', copy_number) ORDER BY copy_number) as call_number_data,
    GROUP_CONCAT(DISTINCT copy_number ORDER BY copy_number) as copy_number_range,
    GROUP_CONCAT(DISTINCT shelf_location ORDER BY shelf_location) as shelf_locations,
    GROUP_CONCAT(DISTINCT ISBN ORDER BY ISBN) as isbns,
    COUNT(*) as total_copies,
    GROUP_CONCAT(DISTINCT series ORDER BY series) as series_data,
    GROUP_CONCAT(DISTINCT edition ORDER BY edition) as editions,
    GROUP_CONCAT(DISTINCT volume ORDER BY volume) as volumes
    FROM books ";

if (!empty($searchQuery)) {
    $query .= " WHERE title LIKE ? ";
    $stmt = $conn->prepare($query);
    $searchParam = "%$searchQuery%";
    $stmt->bind_param("s", $searchParam);
} else {
    $stmt = $conn->prepare($query);
}

$query .= " GROUP BY title ORDER BY title";
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book List</title>
    <style>
        /* Add custom CSS for responsive table */
        .table-responsive {
            width: 100%;
            margin-bottom: 1rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Ensure minimum width for table columns */
        #dataTable th,
        #dataTable td {
            min-width: 100px; /* Adjust this value based on your content */
            white-space: nowrap;
        }
        
        /* Specific column widths */
        #dataTable th:nth-child(3),
        #dataTable td:nth-child(3) {
            min-width: 200px; /* Title column wider */
        }
        
        /* Make the table stretch full width */
        #dataTable {
            width: 100% !important;
        }
        
        /* Prevent text wrapping in cells */
        .table td, .table th {
            white-space: nowrap;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var successMessage = "<?php echo $successMessage; ?>";
            if (successMessage) {
                alert(successMessage);
            }
        });
    </script>
    <style>
    @media (max-width: 575.98px) {
        .card-header .btn-group {
            display: flex;
            width: 100%;
        }
        
        .card-header .btn-group .btn {
            flex: 1;
        }
        
        .card-header .btn-sm {
            padding: .25rem .5rem;
            font-size: .875rem;
            white-space: nowrap;
        }
    }
</style>
    <style>
    /* Add this to your existing styles */
    .card-header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    
    .book-stats {
        display: flex;
        align-items: center;
    }
    
    .total-books-display {
        font-size: 0.9rem;
        color: #4e73df;
        font-weight: 600;
        margin-right: 10px;
    }
    
    @media (max-width: 575.98px) {
        .card-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .card-header .btn-group {
            display: flex;
            width: 100%;
        }
        
        .card-header .btn-group .btn {
            flex: 1;
        }
    }
</style>
</head>
<body>
    <?php include '../admin/inc/header.php'; ?>

    <!-- Main Content -->
    <div id="content" class="d-flex flex-column min-vh-100">
        <div class="container-fluid">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Book List</h6>
                    <div class="btn-group d-flex align-items-center">
                        <span class="mr-2 total-books-display">
                            Total Books: <?php echo number_format($totalBooks); ?>
                        </span>
                        <button class="btn btn-danger btn-sm mx-1" id="batchDelete" disabled>
                            Delete Selected (<span id="selectedCountButton">0</span>)
                        </button>
                        <a href="add-book.php" class="btn btn-primary btn-sm">Add Book</a>
                    </div>
                </div>
                <div class="card-body px-0"> <!-- Remove padding for full-width scroll -->
                    <div class="table-responsive px-3"> <!-- Add padding inside scroll container -->
                        <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
    <thead>
        <tr>
            <th style="text-align: center"><input type="checkbox" id="selectAll"></th>
            <th style="text-align: center">ID Range</th>
            <th style="text-align: center">Title</th>
            <th style="text-align: center">Accession Range</th>
            <th style="text-align: center">Call Number Range</th>
            <th style="text-align: center">Copy Number Range</th>
            <th style="text-align: center">Shelf Locations</th>
            <th style="text-align: center">ISBN Range</th>
            <th style="text-align: center">Series Range</th>
            <th style="text-align: center">Volume Range</th>
            <th style="text-align: center">Edition Range</th>
            <th style="text-align: center">Total Copies</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $query = "SELECT 
            title,
            GROUP_CONCAT(DISTINCT id ORDER BY id) as id_range,
            GROUP_CONCAT(DISTINCT accession ORDER BY accession) as accession_range,
            GROUP_CONCAT(CONCAT(call_number, '|', copy_number) ORDER BY copy_number) as call_number_data,
            GROUP_CONCAT(DISTINCT copy_number ORDER BY copy_number) as copy_number_range,
            GROUP_CONCAT(DISTINCT shelf_location ORDER BY shelf_location) as shelf_locations,
            GROUP_CONCAT(DISTINCT ISBN ORDER BY ISBN) as isbns,
            COUNT(*) as total_copies,
            GROUP_CONCAT(DISTINCT series ORDER BY series) as series_data,
            GROUP_CONCAT(DISTINCT edition ORDER BY edition) as editions,
            GROUP_CONCAT(DISTINCT volume ORDER BY volume) as volumes
            FROM books ";
        
        if (!empty($searchQuery)) {
            $query .= " WHERE title LIKE '%$searchQuery%' ";
        }
        
        $query .= " GROUP BY title ORDER BY title";
        
        $result = $conn->query($query);

        while ($row = $result->fetch_assoc()) {
            // Process IDs
            $ids = explode(',', $row['id_range']);
            $id_range = formatRange($ids);

            // Process accessions
            $accessions = explode(',', $row['accession_range']);
            $accession_range = formatRange($accessions);

            // Process call numbers (using existing formatCallNumberSequence function)
            $call_number_data = explode(',', $row['call_number_data']);
            $call_numbers = [];
            $current_base = '';
            $current_sequence = [];

            foreach ($call_number_data as $data) {
                list($call_num, $copy_num) = explode('|', $data);
                $base_call = preg_replace('/\s*c\d+$/', '', $call_num);

                if ($base_call !== $current_base) {
                    if (!empty($current_sequence)) {
                        $call_numbers[] = formatCallNumberSequence($current_base, $current_sequence);
                    }
                    $current_base = $base_call;
                    $current_sequence = [];
                }
                $current_sequence[] = $copy_num;
            }
            
            if (!empty($current_sequence)) {
                $call_numbers[] = formatCallNumberSequence($current_base, $current_sequence);
            }

            // Process copy numbers
            $copy_numbers = explode(',', $row['copy_number_range']);
            $copy_range = formatRange($copy_numbers);

            // Process shelf locations
            $shelf_locations = array_unique(explode(',', $row['shelf_locations']));
            $formatted_shelf_locations = implode(', ', $shelf_locations);

            // Process ISBNs
            $isbns = array_unique(explode(',', $row['isbns']));
            $formatted_isbns = implode(', ', array_filter($isbns));

            // Process series
            $series = array_unique(explode(',', $row['series_data']));
            $formatted_series = implode(', ', array_filter($series));

            // Process volumes
            $volumes = array_unique(explode(',', $row['volumes']));
            $formatted_volumes = implode(', ', array_filter($volumes));

            // Process editions
            $editions = array_unique(explode(',', $row['editions']));
            $formatted_editions = implode(', ', array_filter($editions));

            // Format all data for display
            echo "<tr>
                <td style='text-align: center'><input type='checkbox' class='selectRow' value='" . implode(',', $ids) . "'></td>
                <td style='text-align: center'>{$id_range}</td>
                <td>{$row['title']}</td>
                <td style='text-align: center'>{$accession_range}</td>
                <td style='text-align: center'>" . implode('<br>', $call_numbers) . "</td>
                <td style='text-align: center'>{$copy_range}</td>
                <td style='text-align: center'>{$formatted_shelf_locations}</td>
                <td style='text-align: center'>" . ($formatted_isbns ?: 'N/A') . "</td>
                <td style='text-align: center'>" . ($formatted_series ?: 'N/A') . "</td>
                <td style='text-align: center'>" . ($formatted_volumes ?: 'N/A') . "</td>
                <td style='text-align: center'>" . ($formatted_editions ?: 'N/A') . "</td>
                <td style='text-align: center'>{$row['total_copies']}</td>
            </tr>";
        }

        // Helper function to format ranges smartly
        function formatRange($numbers) {
            if (empty($numbers)) return 'N/A';
            
            $numbers = array_map('intval', $numbers);
            sort($numbers);
            
            $ranges = [];
            $start = $numbers[0];
            $prev = $start;
            
            for ($i = 1; $i <= count($numbers); $i++) {
                if ($i == count($numbers) || $numbers[$i] - $prev > 1) {
                    if ($start == $prev) {
                        $ranges[] = $start;
                    } else {
                        $ranges[] = "$start-$prev";
                    }
                    if ($i < count($numbers)) {
                        $start = $numbers[$i];
                        $prev = $start;
                    }
                } else {
                    $prev = $numbers[$i];
                }
            }
            
            return implode(', ', $ranges);
        }

        // Keep the existing formatCallNumberSequence function
        function formatCallNumberSequence($base_call, $copies) {
            sort($copies, SORT_NUMERIC);
            $ranges = [];
            $start = $copies[0];
            $prev = $start;
            
            for ($i = 1; $i <= count($copies); $i++) {
                if ($i == count($copies) || $copies[$i] - $prev > 1) {
                    if ($start == $prev) {
                        $ranges[] = $base_call . " c" . $start;
                    } else {
                        $ranges[] = $base_call . " c" . $start . " - " . $base_call . " c" . $prev;
                    }
                    if ($i < count($copies)) {
                        $start = $copies[$i];
                        $prev = $start;
                    }
                } else {
                    $prev = $copies[$i];
                }
            }
            return implode('<br>', $ranges); 
        }
        ?>
    </tbody>
</table>
                            </div>
        </div>
        <!-- /.container-fluid -->

        <!-- Context Menu -->
        <div id="contextMenu" class="dropdown-menu" style="display:none; position:absolute;">
            <a class="dropdown-item" href="#" id="updateBook">Update</a>
            <a class="dropdown-item" href="#" id="deleteBook">Delete</a>
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
            var selectedBookIds = <?php echo json_encode(isset($_SESSION['selectedBookIds']) ? $_SESSION['selectedBookIds'] : []); ?>;

            // Function to update the selected book IDs in the session
            function updateSelectedBookIds() {
                selectedBookIds = [];
                $('.selectRow:checked').each(function() {
                    selectedBookIds.push($(this).val());
                });
                
                const count = selectedBookIds.length;
                
                // Update only the button counter
                $('#selectedCountButton').text(count);
                
                // Enable/disable delete button
                $('#batchDelete').prop('disabled', count === 0);
                
                // Store selected book IDs in session
                $.post('selected_books.php', {
                    selectedBookIds: selectedBookIds
                });

                // Update select all checkbox state
                updateSelectAllState();
            }

            // Function to update select all checkbox state
            function updateSelectAllState() {
                var totalCheckboxes = $('.selectRow').length;
                var checkedCheckboxes = $('.selectRow:checked').length;
                $('#selectAll').prop('checked', totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes);
            }

            // Select/Deselect all checkboxes
            $('#selectAll').click(function() {
                $('.selectRow').prop('checked', this.checked);
                updateSelectedBookIds();
            });

            // Individual checkbox click handler
            $(document).on('click', '.selectRow', function() {
                updateSelectedBookIds();
            });

            // Restore the selected state on page load
            function restoreSelectedState() {
                $('.selectRow').each(function() {
                    var bookId = $(this).val();
                    $(this).prop('checked', selectedBookIds.includes(bookId));
                });
                updateSelectAllState();
                $('#selectedCount').text(`(${selectedBookIds.length} books selected)`);
            }

            // Call restoreSelectedState on page load
            restoreSelectedState();

            // Function to fetch and reload the table data
            function fetchBooks() {
                var searchQuery = $('input[name="search"]').val();
                $.ajax({
                    url: 'fetch_books.php',
                    type: 'GET',
                    data: {
                        search: searchQuery
                    },
                    success: function(response) {
                        $('#dataTable tbody').html(response);
                        restoreSelectedState(); 
                    }
                });
            }

            // Handle batch delete
            $('#batchDelete').click(function() {
                if (selectedBookIds.length === 0) {
                    alert('Please select books to delete.');
                    return;
                }

                // Count total copies by expanding all ID ranges
                let totalCopies = 0;
                selectedBookIds.forEach(function(idRange) {
                    let ids = idRange.split(',');
                    ids.forEach(function(id) {
                        if (id.includes('-')) {
                            let [start, end] = id.split('-');
                            totalCopies += parseInt(end) - parseInt(start) + 1;
                        } else {
                            totalCopies++;
                        }
                    });
                });

                if (confirm(`You are about to delete ${totalCopies} copy/copies. This cannot be undone! Are you sure?`)) {
                    $.ajax({
                        url: 'book_list.php',
                        type: 'POST',
                        data: {
                            batch_delete: true,
                            book_ids: JSON.stringify(selectedBookIds)
                        },
                        dataType: 'json',
                        success: function(response) {
                            alert(response.message);
                            if (response.success) {
                                location.reload();
                            }
                        },
                        error: function() {
                            alert('Error occurred while deleting books.');
                        }
                    });
                }
            });

            // Handle search form submission
            $('#searchForm').submit(function(event) {
                event.preventDefault();
                fetchBooks();
            });
        });
        </script>
        <script>
        $(document).ready(function () {
            var selectedBookId;
            var selectedIdRange;

            // Add click handler for viewing book details
            $('#dataTable tbody').on('click', 'tr', function(e) {
                // Don't trigger on checkbox click
                if ($(e.target).is('input[type="checkbox"]')) {
                    return;
                }
                var bookId = $(this).find('td:nth-child(2)').text();
                window.location.href = `opac.php?book_id=${bookId}`;
            });

            // Show context menu on right-click
            $('#dataTable tbody').on('contextmenu', 'tr', function(e) {
                e.preventDefault();
                $('#dataTable tbody tr').removeClass('context-menu-active');
                $(this).addClass('context-menu-active');
                selectedIdRange = $(this).find('td:nth-child(2)').text(); // Get the ID range
                var totalCopies = $(this).find('td:last').text(); // Get total copies from last column
                $(this).data('totalCopies', totalCopies); // Store for later use

                // Calculate the position of the context menu
                var menu = $('#contextMenu');
                var menuWidth = menu.outerWidth();
                var menuHeight = menu.outerHeight();
                var windowWidth = $(window).width();
                var windowHeight = $(window).height();
                var clickX = e.pageX;
                var clickY = e.pageY;

                // Adjust position to prevent overflow
                if (clickX + menuWidth > windowWidth) {
                    clickX = windowWidth - menuWidth;
                }
                if (clickY + menuHeight > windowHeight) {
                    clickY = windowHeight - menuHeight;
                }

                menu.css({
                    display: 'block',
                    left: clickX,
                    top: clickY
                });
                return false;
            });

            // Hide context menu on click outside
            $(document).click(function() {
                $('#contextMenu').hide();
            });

            // Handle context menu actions
            $('#updateBook').click(function() {
                var $row = $('.context-menu-active');
                var title = $row.find('td:nth-child(3)').text();
                window.location.href = `update_books.php?title=${encodeURIComponent(title)}&id_range=${encodeURIComponent(selectedIdRange)}`;
            });

            $('#deleteBook').click(function() {
                var $row = $('.context-menu-active');
                var totalCopies = $row.data('totalCopies');
                var title = $row.find('td:nth-child(3)').text();

                if (confirm(`You are about to delete ${totalCopies} copy/copies of "${title}". This cannot be undone! Are you sure?`)) {
                    $.ajax({
                        url: 'book_list.php',
                        type: 'POST',
                        data: {
                            batch_delete: true,
                            book_ids: JSON.stringify([selectedIdRange])
                        },
                        dataType: 'json',
                        success: function(response) {
                            alert(response.message);
                            if (response.success) {
                                location.reload();
                            }
                        },
                        error: function() {
                            alert('Error occurred while deleting books.');
                        }
                    });
                }
            });
        });
        </script>
        <script>
        $(document).ready(function () {
            var table = $('#dataTable').DataTable({
                "dom": "<'row mb-3'<'col-sm-6'l><'col-sm-6 d-flex justify-content-end'f>>" +
                       "<'row'<'col-sm-12'tr>>" +
                       "<'row mt-3'<'col-sm-5'i><'col-sm-7 d-flex justify-content-end'p>>",
                "pageLength": 10,
                "lengthMenu": [[10, 25, 50, 100, 500], [10, 25, 50, 100, 500]],
                "responsive": false, // Disable DataTables responsive handling
                "scrollX": true, // Enable horizontal scrolling
                "columnDefs": [
                    { 
                        "orderable": false, 
                        "searchable": false,
                        "targets": 0 
                    }
                ],
                "order": [[2, "asc"]], // Sort by title by default
                "language": {
                    "search": "_INPUT_",
                    "searchPlaceholder": "Search..."
                }
            });
            
            // Adjust table columns on window resize
            $(window).on('resize', function () {
                table.columns.adjust();
            });
        });
        </script>
    </div>
</body>
</html>
