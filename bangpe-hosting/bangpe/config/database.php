<?php
// ===== ตั้งค่าการเชื่อมต่อฐานข้อมูล =====
// XAMPP ค่าเริ่มต้น: user = root, password = ว่าง
define('DB_HOST', 'localhost');
define('DB_NAME', 'bangpe_pos');
define('DB_USER', 'root');
define('DB_PASS', '');

// ===== ตั้งค่าระบบ =====
define('APP_NAME', 'บังเป้ ระยอง');
define('EXPIRY_ALERT_DAYS', 3);      // แจ้งเตือนวัตถุดิบที่จะหมดอายุภายในกี่วัน
define('NOTIFY_POLL_SECONDS', 60);   // ดึงการแจ้งเตือนใหม่ทุกกี่วินาที

date_default_timezone_set('Asia/Bangkok');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $ex) {
    http_response_code(500);
    exit('เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ตรวจสอบค่าใน config/database.php และว่าได้นำเข้า database.sql แล้ว');
}
