<?php
require_once __DIR__ . '/membership_plans.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/user_credits.php';
require_once __DIR__ . '/orders.php';

$plans   = new MembershipPlansTable();
$users   = new UsersTable();
$credits = new UserCreditsTable();
$orders  = new OrdersTable();

$plans->createTable();
$users->createTable();
$credits->createTable();
$orders->createTable();

echo "Database initialized successfully!";
