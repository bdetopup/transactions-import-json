<?php

header("Content-Type: application/json");

$plugin_slug = 'transactions-import-json';

// Function to dynamically find pp-config.php
function find_pp_config(): ?string
{
    $start = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $root = dirname($start, $i + 1);
        $cfg = $root . '/pp-config.php';
        if (is_file($cfg) && is_readable($cfg)) {
            return realpath($cfg);
        }
    }
    return null;
}

// Find and include the configuration file
$config_path = find_pp_config();
if ($config_path === null) {
    die('Could not find pp-config.php file');
}

require_once $config_path;

// Try updating database
if (!isset($conn)) {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        $error = "Connection failed: " . $conn->connect_error;
    }
    if (!$conn->query("SET NAMES utf8")) {
        $error = "Set names failed: " . $conn->error;
    }
    if (!empty($db_prefix)) {
        if (!$conn->query("SET sql_mode = ''")) {
            $error = "Set sql_mode failed: " . $conn->error;
        }
    }
}

// Get JSON input
$input = json_decode(file_get_contents("php://input"), true);
$auth_id = $input['auth_id'] ?? '';
$data = $input['data'] ?? [];

// Validate auth_id
$sql = "SELECT * FROM {$db_prefix}plugins WHERE plugin_slug = '{$plugin_slug}'";
$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
    exit;
}

$row = $result->fetch_assoc();
if (!$row) {
    echo json_encode(["status" => "error", "message" => "Plugin not found"]);
    exit;
}

$plugin_array = json_decode($row['plugin_array'], true);
if (!$plugin_array || !isset($plugin_array['auth_id'])) {
    echo json_encode(["status" => "error", "message" => "Invalid plugin configuration"]);
    exit;
}

// Strict auth_id check
if ($plugin_array['auth_id'] !== $auth_id) {
    echo json_encode(["status" => "error", "message" => "Invalid auth_id"]);
    exit;
}

if (empty($data)) {
    echo json_encode(["status" => "error", "message" => "No data received"]);
    exit;
}

// Check for duplicates in existing database
$duplicate_count = 0;
$unique_data = [];

// Get all existing pp_id and payment_verify_id from database
$existing_pp_ids = [];
$existing_verify_ids = [];

$check_sql = "SELECT pp_id, payment_verify_id FROM {$db_prefix}transaction";
$check_result = $conn->query($check_sql);
if ($check_result) {
    while ($row = $check_result->fetch_assoc()) {
        if (!empty($row['pp_id'])) {
            $existing_pp_ids[$row['pp_id']] = true;
        }
        if (!empty($row['payment_verify_id']) && $row['payment_verify_id'] !== '--') {
            $existing_verify_ids[$row['payment_verify_id']] = true;
        }
    }
}

// Filter out duplicates
foreach ($data as $item) {
    $is_duplicate = false;
    
    if (isset($existing_pp_ids[$item['pp_id']])) {
        $is_duplicate = true;
    }
    
    if (!$is_duplicate && isset($item['payment_verify_id']) && 
        $item['payment_verify_id'] !== '--' && 
        isset($existing_verify_ids[$item['payment_verify_id']])) {
        $is_duplicate = true;
    }
    
    if (!$is_duplicate) {
        $unique_data[] = $item;
    } else {
        $duplicate_count++;
    }
}

// Prepare statement for insertion
$stmt = $conn->prepare("INSERT INTO {$db_prefix}transaction (
    pp_id, c_id, c_name, c_email_mobile, payment_method_id, payment_method, 
    payment_verify_way, payment_sender_number, payment_verify_id, transaction_amount, 
    transaction_fee, transaction_refund_amount, transaction_refund_reason, 
    transaction_currency, transaction_redirect_url, transaction_return_type, 
    transaction_cancel_url, transaction_webhook_url, transaction_metadata, 
    transaction_status, transaction_product_name, transaction_product_description, 
    transaction_product_meta, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$stmt->bind_param(
    "ssssssssssssssssssssssss", 
    $pp_id, $c_id, $c_name, $c_email_mobile, $payment_method_id, $payment_method,
    $payment_verify_way, $payment_sender_number, $payment_verify_id, $transaction_amount,
    $transaction_fee, $transaction_refund_amount, $transaction_refund_reason,
    $transaction_currency, $transaction_redirect_url, $transaction_return_type,
    $transaction_cancel_url, $transaction_webhook_url, $transaction_metadata,
    $transaction_status, $transaction_product_name, $transaction_product_description,
    $transaction_product_meta, $created_at
);

$inserted = 0;
$errors = [];

foreach ($unique_data as $row) {
    $pp_id = $row["pp_id"] ?? '';
    $c_id = $row["c_id"] ?? '--';
    $c_name = $row["c_name"] ?? '--';
    $c_email_mobile = $row["c_email_mobile"] ?? '--';
    $payment_method_id = $row["payment_method_id"] ?? '--';
    $payment_method = $row["payment_method"] ?? '--';
    $payment_verify_way = $row["payment_verify_way"] ?? '--';
    $payment_sender_number = $row["payment_sender_number"] ?? '--';
    $payment_verify_id = $row["payment_verify_id"] ?? '--';
    $transaction_amount = $row["transaction_amount"] ?? '0';
    $transaction_fee = $row["transaction_fee"] ?? '0';
    $transaction_refund_amount = $row["transaction_refund_amount"] ?? '0';
    $transaction_refund_reason = $row["transaction_refund_reason"] ?? '--';
    $transaction_currency = $row["transaction_currency"] ?? 'BDT';
    $transaction_redirect_url = $row["transaction_redirect_url"] ?? '--';
    $transaction_return_type = $row["transaction_return_type"] ?? 'GET';
    $transaction_cancel_url = $row["transaction_cancel_url"] ?? '--';
    $transaction_webhook_url = $row["transaction_webhook_url"] ?? '--';
    $transaction_metadata = $row["transaction_metadata"] ?? '{}';
    $transaction_status = $row["transaction_status"] ?? 'pending';
    $transaction_product_name = $row["transaction_product_name"] ?? '--';
    $transaction_product_description = $row["transaction_product_description"] ?? '--';
    $transaction_product_meta = $row["transaction_product_meta"] ?? '--';
    $created_at = $row["created_at"] ?? date('Y-m-d H:i:s');

    if ($stmt->execute()) {
        $inserted++;
    } else {
        $errors[] = "Failed to insert record with pp_id: $pp_id - " . $stmt->error;
    }
}

// Clear auth_id after import
if (isset($conn) && !$conn->connect_error) {
    $auth_id = '';
    $sql = "UPDATE {$db_prefix}plugins SET plugin_array = '{\"auth_id\":\"$auth_id\"}' WHERE plugin_slug = '{$plugin_slug}'";
    $conn->query($sql);
}

$stmt->close();
$conn->close();

echo json_encode([
    "status" => "success",
    "inserted" => $inserted,
    "duplicates" => $duplicate_count,
    "total" => count($data),
    "errors" => $errors,
    "message" => "Successfully imported $inserted out of " . count($data) . " records. Skipped $duplicate_count duplicates."
]);