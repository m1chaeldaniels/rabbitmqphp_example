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
        $fileModTime = filemtime($file);

        // Query for the latest version of this bundle
        $stmt = $conn->prepare("SELECT version, created_at FROM bundles WHERE bundle_name = ? ORDER BY version DESC LIMIT 1");
        $stmt->bind_param("s", $bundleName);
        $stmt->execute();
        $stmt->bind_result($latestVersion, $latestCreatedAt);
        $stmt->fetch();
        $stmt->close();

        // Determine if this is a new version or a duplicate
        if ($latestVersion !== null && $fileModTime <= strtotime($latestCreatedAt)) {
            echo "File $fileName is already processed as version $latestVersion. Skipping.\n";
            continue;
        }

        // Increment version if the bundle exists, otherwise start at 1
        $nextVersion = ($latestVersion !== null) ? $latestVersion + 1 : 1;

        // Construct the new file name with the updated version
        $newFileName = "{$bundleName}-v{$nextVersion}.tgz";
        $newFilePath = $processedDir . $newFileName;

        // Insert bundle information into the database
        $createdAt = date("Y-m-d H:i:s");
        $insertStmt = $conn->prepare("INSERT INTO bundles (bundle_name, version, path, created_at) VALUES (?, ?, ?, ?)");
        $insertStmt->bind_param("siss", $bundleName, $nextVersion, $newFilePath, $createdAt);
        $insertStmt->execute();
        $insertStmt->close();

        echo "Bundle $bundleName (version $nextVersion) processed successfully. Status set to 'new'.\n";

        // Rename the processed file to include the version and move it to the processed directory
        rename($file, $newFilePath);
    }

    // Sleep for a short time before checking again
    sleep(5);
}
?>

