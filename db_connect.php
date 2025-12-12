<?php
$DB_HOST = 'localhost';
$DB_USER = 'root'; // XAMPP default
$DB_PASS = ''; // XAMPP default (empty)
$DB_NAME = 'ecofinal';
$conn = mysqli_connect($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if (!$conn) {
 die('Database connection failed: ' . mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');