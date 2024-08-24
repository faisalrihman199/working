<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id             = isset($input['id']) ? (int)$input['id'] : 0;
$name           = trim($input['name'] ?? '');
$phone          = trim($input['phone'] ?? '');
$address        = trim($input['address'] ?? '');
$table_number   = $input['table_number'] ?? null;
$order_type     = $input['order_type'] ?? '';
$order_status   = $input['order_status'] ?? '';
$order_details  = $input['order_details'] ?? null;
$note           = $input['note'] ?? null;
$payment_status = $input['payment_status'] ?? 'Unpaid';

if ($id <= 0 || $name === '' || $phone === '' || $address === '') {
    echo json_encode(['success'=>false,'message'=>'Missing required fields']);
    exit;
}

$validTypes   = ['Delivery','Dine-in','Takeaway'];
$validStatus  = ['Pending','Preparing','Ready','Served','Delivered','Handover','Cancelled'];
$validPayStat = ['Unpaid','Paid','Refunded'];
if (!in_array($order_type, $validTypes, true))    { echo json_encode(['success'=>false,'message'=>'Invalid order_type']); exit; }
if (!in_array($order_status, $validStatus, true)) { echo json_encode(['success'=>false,'message'=>'Invalid order_status']); exit; }
if (!in_array($payment_status, $validPayStat, true)) { echo json_encode(['success'=>false,'message'=>'Invalid payment_status']); exit; }

try {
    $db = (new Database())->getConnection();
    $sql = "UPDATE orders
            SET name=:name, phone=:phone, address=:address, table_number=:table_number,
                order_type=:order_type, order_status=:order_status,
                order_details=:order_details, note=:note, payment_status=:payment_status
            WHERE id=:id AND user_id=:uid";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':name' => $name,
        ':phone'=> $phone,
        ':address'=>$address,
        ':table_number'=> $table_number ?: null,
        ':order_type'=> $order_type,
        ':order_status'=> $order_status,
        ':order_details'=> $order_details,
        ':note'=> $note,
        ':payment_status'=> $payment_status,
        ':id' => $id,
        ':uid'=> (int)$_SESSION['user_id']
    ]);

    echo json_encode(['success'=>true]);
} catch (PDOException $e) {
    error_log('update_order error: '.$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
