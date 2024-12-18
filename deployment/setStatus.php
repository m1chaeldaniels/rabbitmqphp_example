<?php
// Configuration
$bundleDir = "/var/deployment/bundles/";
$qaClusterUser = "mtd32"; 
$qaClusterIP = "172.29.106.217"; 
$qaClusterDir = "/home/mtd32/deployment/tmp/deploy/"; 
$deployScript = "/home/mtd32/deployment/deploy_config.sh";
$prodClusterUser = "mtd32"; 
$prodClusterIP = "172.29.152.82"; 
$prodClusterDir = "/home/mtd32/deployment/tmp/deploy/"; 

// Database connection
$conn = new mysqli("localhost", "testUser", "12345", "deploy");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Fetch recent tarballs with status 'new' or 'passed'
echo "Fetching the last 5 tarballs with status 'new' or 'passed':\n";
$query = "
    SELECT bundle_name, version, status 
    FROM bundles 
    WHERE status IN ('new', 'passed') 
    ORDER BY created_at DESC LIMIT 5
";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "- {$row['bundle_name']} (Version: {$row['version']}, Status: {$row['status']})\n";
    }
} else {
    echo "No tarballs with status 'new' or 'passed' found.\n";
    $conn->close();
    exit(1);
}

// Prompt user to select a tarball
echo "Enter the tarball base name (e.g., mybundle): ";
$handle = fopen("php://stdin", "r");
$bundleName = trim(fgets($handle));
fclose($handle);

if (empty($bundleName)) {
    die("Error: No tarball base name provided.\n");
}

// Query for the selected tarball
$stmt = $conn->prepare("
    SELECT version, path, status 
    FROM bundles 
    WHERE bundle_name = ?
    AND status IN ('new', 'passed') 
    ORDER BY version DESC LIMIT 1
");
$stmt->bind_param("s", $bundleName);
$stmt->execute();
$stmt->bind_result($version, $tarballPath, $currentStatus);
$stmt->fetch();
$stmt->close();

if (empty($tarballPath)) {
    die("Error: No tarball found for '$bundleName'.\n");
}

echo "Selected tarball: '$bundleName-v$version' (Current status: $currentStatus).\n";

// Ask user what status to set
echo "Enter the new status (passed/failed): ";
$handle = fopen("php://stdin", "r");
$newStatus = trim(fgets($handle));
fclose($handle);

if (!in_array($newStatus, ['passed', 'failed'])) {
    die("Error: Invalid status provided.\n");
}

// If the status is set to 'failed', perform rollback
if ($newStatus === 'failed') {
    echo "Rolling back to the most recent tarball with status 'passed'...\n";

$updateStmt = $conn->prepare("UPDATE bundles SET status = 'failed' WHERE bundle_name = ? AND version = ?");
    $updateStmt->bind_param("si", $bundleName, $version);
    $updateStmt->execute();
    $updateStmt->close();



    $rollbackStmt = $conn->prepare("
        SELECT version, path 
        FROM bundles 
        WHERE status = 'passed' 
        ORDER BY version DESC LIMIT 1
    ");
    $rollbackStmt->execute();
    $rollbackStmt->bind_result($rollbackVersion, $rollbackPath);
    $rollbackStmt->fetch();
    $rollbackStmt->close();

    if (empty($rollbackPath)) {
        die("Error: No tarball with status 'passed' available for rollback.\n");
    }

    echo "Rolling back to '$rollbackPath' (Version: $rollbackVersion).\n";

    // Deploy to QA
    $scpCommand = "scp $rollbackPath $qaClusterUser@$qaClusterIP:$qaClusterDir";
    exec($scpCommand, $output, $returnVar);

    if ($returnVar !== 0) {
        die("Error: Failed to transfer rollback tarball to QA cluster.\n");
    }
    echo "Rollback tarball transferred to QA cluster.\n";

    $sshCommand = "ssh $qaClusterUser@$qaClusterIP 'bash $deployScript $qaClusterDir" . basename($rollbackPath) . "' 2>/dev/null";
    exec($sshCommand, $output, $returnVar);

    if ($returnVar !== 0) {
        die("Error: Failed to deploy rollback tarball to QA cluster.\n");
    }
    echo "Rollback deployed to QA cluster.\n";

    // Deploy to Prod
    $scpCommand = "scp $rollbackPath $prodClusterUser@$prodClusterIP:$prodClusterDir";
    exec($scpCommand, $output, $returnVar);

    if ($returnVar !== 0) {
        die("Error: Failed to transfer rollback tarball to Prod cluster.\n");
    }
    echo "Rollback tarball transferred to Prod cluster.\n";

    $sshCommand = "ssh $prodClusterUser@$prodClusterIP 'bash $deployScript $prodClusterDir" . basename($rollbackPath) . "' 2>/dev/null";
    exec($sshCommand, $output, $returnVar);

    if ($returnVar !== 0) {
        die("Error: Failed to deploy rollback tarball to Prod cluster.\n");
    }
    echo "Rollback deployed to Prod cluster.\n";

    // Update status in the database

    echo "Rollback completed. Status set to 'failed' for tarball '$bundleName-v$version'.\n";
} else {
    // Set status to 'passed'
    $updateStmt = $conn->prepare("UPDATE bundles SET status = 'passed' WHERE bundle_name = ? AND version = ?");
    $updateStmt->bind_param("si", $bundleName, $version);
    $updateStmt->execute();
    $updateStmt->close();

    echo "Status set to 'passed' for tarball '$bundleName-v$version'.\n";
}

$conn->close();
?>

