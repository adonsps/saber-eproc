<?php
require 'workspace/config/database.php';
$pdo->query("UPDATE rfq_committee SET username='hadi.risali' WHERE username='hadi.risali@kawanlamacorp.com'");
$pdo->query("UPDATE rfq_committee SET username='hartanto' WHERE username='hartanto@kawanlamacorp.com'");
echo "Done";
