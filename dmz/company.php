<?php

$apiKey = 'nOSahf1USorOnh4cDw9TVb3WjLGKmLvMuOlbj4dWGPJBWelMv1buPnRNzHPzIQHXkgj4giHdKytOc8De-DWacvLtghlwXhdAZ8ABaWU10-WUSbfzMSUc6YuJPtYfZ3Yx';  // Replace with your actual API key
$companyName = 'Dunkin';  // Replace with the company name you want to search for
$city = 'Clifton';  // Replace with the city name
$state = 'NJ';  // Replace with the state abbreviation

// Combine city and state for the location parameter
$location = $city . ', ' . $state;


$curl = curl_init();

curl_setopt_array($curl, [
  CURLOPT_URL => "https://api.yelp.com/v3/businesses/search?term=" . urlencode($companyName) . "&location=" . urlencode($location) . "&limit=1",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer $apiKey",
    "accept: application/json"
  ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);

curl_close($curl);

if ($err) {
  echo "cURL Error #:" . $err;
} else {
  $data = json_decode($response, true);

  if (isset($data['businesses'][0])) {
    $business = $data['businesses'][0];
    
    $name = $business['name'] ?? 'N/A';
    $alias = $business['alias'] ?? 'N/A';
    $review_count = $business['review_count'] ?? 'N/A';
    $rating = $business['rating'] ?? 'N/A';
    $address = implode(", ", $business['location']['display_address'] ?? ['N/A']);
    $city = $business['location']['city'] ?? 'N/A';
    $state = $business['location']['state'] ?? 'N/A';
    $zip_code = $business['location']['zip_code'] ?? 'N/A';
    $country = $business['location']['country'] ?? 'N/A';
    $phone = $business['display_phone'] ?? 'N/A';
    $alt_phone = $business['phone'] ?? 'N/A';
    $website = $business['url'] ?? 'N/A';
    $price_range = $business['price'] ?? 'N/A';
    $is_open = $business['is_closed'] ? 'Closed' : 'Open';



    
    // Transactions
    $transactions = implode(", ", $business['transactions'] ?? ['N/A']);
    
    // Attributes (if available)
    $attributes = isset($business['attributes']) ? implode(", ", $business['attributes']) : 'N/A';

    // Display information
    echo "Company Name: " . $name . "\n";
    echo "Alias: " . $alias . "\n";
    echo "Review Count: " . $review_count . "\n";
    echo "Rating: " . $rating . "\n";
    echo "Address: " . $address . "\n";
    echo "City: " . $city . "\n";
    echo "State: " . $state . "\n";
    echo "Zip Code: " . $zip_code . "\n";
    echo "Country: " . $country . "\n";
    echo "Phone: " . $phone . "\n";
    echo "Alternate Phone: " . $alt_phone . "\n";
    echo "Website: " . $website . "\n";
    echo "Price Range: " . $price_range . "\n";
    echo "Status: " . $is_open . "\n";
    echo "Transactions: " . $transactions . "\n";
    echo "Attributes: " . $attributes . "\n";
  } else {
    echo "No company found.\n";
  }
}
?>
