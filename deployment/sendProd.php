<?php
// Configuration
$prodClusterUser = "mtd32"; 
$prodClusterIP = "172.29.152.82"; 
$prodClusterDir = "/home/mtd32/deployment/tmp/deploy/"; 
$deployScript = "/home/mtd32/deployment/deploy_config.sh"; 

// Database connection
$conn = new mysqli("localhost", "testUser", "12345", "deploy");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Fetch the latest version of each tarball with status 'passed'
echo "Fetching the latest version of tarballs with status 'passed':\n";
$query = "
    SELECT bundle_name, MAX(version) AS latest_version 
    FROM bundles 
    WHERE status = 'passed' 
    GROUP BY bundle_name 
    ORDER BY bundle_name ASC
";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "- {$row['bundle_name']} (Latest Version: {$row['latest_version']})\n";
    }
} else {
    echo "No tarballs with status 'passed' found.\n";
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

// Query for the latest version of the selected tarball with status 'passed'
$stmt = $conn->prepare("
    SELECT version, path, status 
    FROM bundles 
    WHERE bundle_name = ? 
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

// Double-check the status before proceeding
if ($currentStatus !== 'passed') {
    die("Error: The selected tarball '$bundleName-v$version' does not have the status 'passed'. Deployment aborted.\n");
}

// Deploy the tarball to Prod
echo "Deploying tarball '$bundleName-v$version' to Prod cluster...\n";

// Transfer the tarball to the Prod cluster
$scpCommand = "scp $tarballPath $prodClusterUser@$prodClusterIP:$prodClusterDir";
exec($scpCommand, $output, $returnVar);

if ($returnVar !== 0) {
    die("Error: Failed to transfer tarball to Prod cluster.\n");
}
echo "Tarball transferred successfully to Prod cluster.\n";

// Execute the deployment script on the Prod cluster
$sshCommand = "ssh $prodClusterUser@$prodClusterIP 'bash $deployScript $prodClusterDir" . basename($tarballPath) . "'";
exec($sshCommand, $output, $returnVar);

if ($returnVar === 0) {
    echo "Deployment script executed successfully on Prod cluster.\n";
} else {
    echo "Error: Deployment script failed on Prod cluster.\n";
    print_r($output); // Debugging output
}

$conn->close();
?>

