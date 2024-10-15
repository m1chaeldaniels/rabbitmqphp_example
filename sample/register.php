<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Check if the request method and type are correct
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type']) && $_POST['type'] === 'register') {
    // Ensure username and password are set
    if (!isset($_POST["uname"]) || !isset($_POST["pword"])) {
        echo json_encode(["status" => "error", "message" => "Username or password missing"]);
        exit(0);
    }

    $username = $_POST["uname"];
    $password = $_POST["pword"];

    // Hash the password before sending it
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    // Prepare the data to be sent via RabbitMQ
    $data = [
        'username' => $username,
        'password' => $hashedPassword
    ];

    // Include sendweb.php to handle RabbitMQ messaging
    ob_start(); // Start output buffering to catch any output from sendweb.php
    include 'sendweb.php';
    $output = ob_get_clean(); // Clear the buffer

    // Check if sendweb.php returned any unexpected output
    if (!empty($output)) {
        // Log or handle the unexpected output here (optional)
        error_log("Unexpected output from sendweb.php: " . $output);
    }

    // Send a success message back to the client
    echo json_encode(["status" => "success", "message" => "Registration data sent for processing."]);
} else {
    // If the request is not valid, return an error message
    echo json_encode(["status" => "error", "message" => "Invalid request."]);
}

exit(0);
?>
