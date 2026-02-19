<?php
$mysqli = new mysqli('localhost', 'd9410_ExoticSSP', '0.S3pWFm@4', 'd9410_ExoticManagementDB');

if ($mysqli->connect_error) {
    die('Connection failed: (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
} else {
    echo "✅ Connected successfully!";
}
?>
