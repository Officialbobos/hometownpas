<?php
// C:\xampp_lite_8_4\www\phpfile-main\heritagebank_admin\test_hash.php

$password_to_check = 'Oluwasegunbobo0';

// Generate a new hash for the password you believe is correct
$new_hash = password_hash($password_to_check, PASSWORD_DEFAULT);

echo "<h2>Password Hash Test</h2>";
echo "Password to check: " . htmlspecialchars($password_to_check) . "<br>";
echo "Generated Hash: " . htmlspecialchars($new_hash) . "<br><br>";
echo "Current Database Hash: \$2y\$12\$cm7dKm31kqyYSgw22lo/be.gcHT4AvcYHGZ2odfA6ggSbSNJlFlPC";