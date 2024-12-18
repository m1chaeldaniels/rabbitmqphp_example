<?php
// Configuration
$bundleDir = "/var/deployment/bundles/";
$qaClusterUser = "mtd32"; 
$qaClusterIP = "172.29.106.217"; 
$qaClusterDir = "/home/mtd32/deployment/tmp/deploy/"; 
$deployScript = "/home/mtd32/deployment/deploy_config.sh";

// Database connection
$conn = new mysqli("localhost", "testUser", "12345", "deploy");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Retrieve the last 5 tarballs with status 'new'
echo "Fetching the last 5 tarballs with status 'new':\n";
$query = "
    SELECT bundle_name, version 
    FROM bundles 
    WHERE status = 'new' 
    ORDER BY created_at DESC LIMIT 5
";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "- {$row['bundle_name']} (Version: {$row['version']})\n";
    }
} else {
    echo "No tarballs with status 'new' found.\n";
    $conn->close();
    exit(1);
}

// Prompt user for the tarball base name
echo "Enter the tarball base name (e.g., mybundle): ";
$handle = fopen("php://stdin", "r");
$bundleName = trim(fgets($handle));
fclose($handle);

if (empty($bundleName)) {
    die("Error: No tarball base name provided.\n");
}

// Query the database for the latest version of the tarball with status 'new'
$stmt = $conn->prepare("
    SELECT version, path 
    FROM bundles 
    WHERE bundle_name = ? AND status = 'new' 
    ORDER BY version DESC LIMIT 1
");
$stmt->bind_param("s", $bundleName);
$stmt->execute();
$stmt->bind_result($latestVersion, $tarballPath);
$stmt->fetch();
$stmt->close();
$conn->close();

// Check if a valid tarball was found
if (empty($tarballPath)) {
    die("Error: No tarball found for '$bundleName' with status 'new'.\n");
}

echo "Found tarball '$bundleName-v$latestVersion' for deployment.\n";

// Transfer the tarball to the QA cluster
$scpCommand = "scp $tarballPath $qaClusterUser@$qaClusterIP:$qaClusterDir";
echo "Transferring tarball '$bundleName-v$latestVersion.tgz' to QA cluster...\n";
exec($scpCommand, $output, $returnVar);

if ($returnVar !== 0) {
    die("Error: Failed to transfer tarball to QA cluster.\n");
}
echo "Tarball transferred successfully.\n";

// Execute the deployment script on the QA cluster
$sshCommand = "ssh $qaClusterUser@$qaClusterIP 'bash $deployScript $qaClusterDir$bundleName-v$latestVersion.tgz'";
echo "Executing deployment script on QA cluster...\n";
exec($sshCommand, $output, $returnVar);

if ($returnVar === 0) {
    echo "Deployment script executed successfully on QA cluster.\n";
} else {
    echo "Error: Deployment script failed.\n";
    print_r($output); // Debugging output
}
?>

