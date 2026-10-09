<?php
require 'config/database.php';
$pdo->query("UPDATE rfq_committee SET username='hadi.risali' WHERE username='hadi.risali@kawanlamacorp.com'");
$pdo->query("UPDATE rfq_committee SET username='hartanto' WHERE username='hartanto@kawanlamacorp.com'");
$pdo->query("UPDATE rfq_committee SET has_opened=0 WHERE rfq_id=999");
echo "Done";
