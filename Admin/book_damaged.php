<?php
session_start();
include('../db.php');

if (!isset($_SESSION['admin_id']) || !in_array($_SESSION['role'], ['Admin', 'Librarian', 'Assistant', 'Encoder'])) {
    header("Location: index.php");
    exit();
}

// Check if ID parameter exists
if (!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Book ID not provided']);
    exit();
}

$bookId = intval($_GET['id']);
$adminId = $_SESSION['admin_id'];
$currentDate = date('Y-m-d');

// Begin transaction
$conn->begin_transaction();

try {
    // Get borrowing information
    $getBorrowingQuery = "SELECT b.id as borrow_id, b.user_id, bk.title 
                        FROM borrowings b 
                        JOIN books bk ON b.book_id = bk.id 
                        WHERE b.book_id = ? AND (b.status = 'Active' OR b.status = 'Overdue') 
                        AND b.return_date IS NULL 
                        LIMIT 1";
    $stmt = $conn->prepare($getBorrowingQuery);
    $stmt->bind_param("i", $bookId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("No active borrowing found for this book");
    }
    
    $borrowing = $result->fetch_assoc();
    $borrowId = $borrowing['borrow_id'];
    $userId = $borrowing['user_id'];
    $bookTitle = $borrowing['title'];

    // Update borrowing record
    $updateBorrowingQuery = "UPDATE borrowings 
                          SET status = 'Damaged', 
                              report_date = ? 
                          WHERE id = ?";
    $stmt = $conn->prepare($updateBorrowingQuery);
    $stmt->bind_param("si", $currentDate, $borrowId);
    $stmt->execute();
    
    // Update book status
    $updateBookQuery = "UPDATE books SET status = 'Damaged' WHERE id = ?";
    $stmt = $conn->prepare($updateBookQuery);
    $stmt->bind_param("i", $bookId);
    $stmt->execute();
    
    // Note: No longer updating user's borrowed_books and damaged_books counters
    // as these columns have been removed from the users table

    // Create fine record for the damaged book (if this logic exists)
    // This section would remain if fines are still being tracked
    // ...

    // Commit transaction
    $conn->commit();
    
    // Return success response for API calls
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Book has been marked as damaged']);
    } else {
        // For direct browser access, redirect back
        header("Location: borrowed_books.php?success=Book has been marked as damaged");
    }
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Return error response for API calls
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        // For direct browser access, redirect back with error
        header("Location: borrowed_books.php?error=" . urlencode($e->getMessage()));
    }
}

$conn->close();
?>
