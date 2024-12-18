<?php
// Configuration
$deploymentDir = "/var/deployment/temp/";
$processedDir = "/var/deployment/bundles/";

// Database connection
$conn = new mysqli("localhost", "testUser", "12345", "deploy");

// Check database connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

echo "Listening for new tarballs in $deploymentDir...\n";

while (true) {
    // Scan the directory for new files
    $files = glob($deploymentDir . "*.tgz");
    foreach ($files as $file) {
        $fileName = basename($file);
        $bundleName = pathinfo($fileName, PATHINFO_FILENAME);

        // Query for the latest version of this bundle
        $stmt = $conn->prepare("SELECT MAX(version) FROM bundles WHERE bundle_name = ?");
        $stmt->bind_param("s", $bundleName);
        $stmt->execute();
        $stmt->bind_result($latestVersion);
        $stmt->fetch();
        $stmt->close();

        // Increment version if the bundle exists, otherwise start at 1
        $nextVersion = ($latestVersion !== null) ? $latestVersion + 1 : 1;

        // Construct the new file name with the updated version
        $newFileName = "{$bundleName}-v{$nextVersion}.tgz";
        $newFilePath = $processedDir . $newFileName;

        // Insert bundle information into the database
        $status = 'new'; // Set default status to 'new'
        $insertStmt = $conn->prepare("INSERT INTO bundles (bundle_name, version, path, status) VALUES (?, ?, ?, ?)");
        $insertStmt->bind_param("siss", $bundleName, $nextVersion, $newFilePath, $status);
        $insertStmt->execute();
        $insertStmt->close();

        echo "Bundle $bundleName (version $nextVersion) processed successfully. Status set to 'new'.\n";

        // Rename the processed file to include the version and move it to the processed directory
        rename($file, $newFilePath);

        // Delete the original file from the deployment directory
        if (file_exists($file)) {
            unlink($file);
            echo "Original file $fileName deleted from deployment directory.\n";
        } else {
            echo "Error: Failed to delete $fileName. File not found.\n";
        }
    }

    // Sleep for a short time before checking again
    sleep(5);
}
?>

