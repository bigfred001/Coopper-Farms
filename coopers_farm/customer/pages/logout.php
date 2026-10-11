<?php
require_once '../../config/config.php';
session_destroy();
header('Location: ' . SITE_URL . '/customer/index.php');
exit;
