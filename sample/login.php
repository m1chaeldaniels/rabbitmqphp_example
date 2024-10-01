<?php
session_start();

// Dummy storage for user credentials (for now, we’ll just use session as a storage placeholder)
$users = isset($_SESSION['users']) ? $_SESSION['users'] : array();

// Check if POST request is set
if (!isset($_POST)) {
    $msg = "NO POST MESSAGE SET, POLITELY FUCK OFF";
    echo json_encode($msg);
    exit(0);
}

$request = $_POST;
$response = "unsupported request type, politely FUCK OFF";

switch ($request["type"]) {
    case "register":
        if (isset($request["uname"]) && isset($request["pword"])) {
            $username = $request["uname"];
            $password = $request["pword"];

            // Check if username already exists
            if (isset($users[$username])) {
                $response = "Username already exists";
            } else {
                // Store user credentials (for demo purposes, storing in session)
                $users[$username] = $password;
                $_SESSION['users'] = $users;
                $response = "Registration successful!";
            }
        } else {
            $response = "Username or password missing";
        }
        break;

    case "login":
        if (isset($request["uname"]) && isset($request["pword"])) {
            $username = $request["uname"];
            $password = $request["pword"];

            // Validate user credentials
            if (isset($users[$username]) && $users[$username] === $password) {
                $_SESSION['logged_in'] = true;
                $_SESSION['username'] = $username;
                $response = "Login successful!";
            } else {
                $response = "Invalid username or password";
            }
        } else {
            $response = "Username or password missing";
        }
        break;
}

echo json_encode($response);
exit(0);
?>
