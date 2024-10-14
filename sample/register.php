<?php


header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type']) && $_POST['type'] === 'register') {
    if (!isset($_POST["uname"]) || !isset($_POST["pword"])) {
        echo json_encode(["status" => "error", "message" => "Username or password missing"]);
        exit(0);
    }

    $username = $_POST["uname"];
    $password = $_POST["pword"];

    // Hash the password before sending it
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    // Prepare the data for sendweb.php
    $data = [
        'username' => $username,
        'password' => $hashedPassword
    ];

    // Include sendweb.php to handle RabbitMQ messaging
    include 'sendweb.php';
   
    echo json_encode(["status" => "success", "message" => "Registration data sent for processing."]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request."]);
}

exit(0);
?>
