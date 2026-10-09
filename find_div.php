<?php
$lines = file('workspace/pages/rfq_dashboard.php');
$open = 0;
foreach ($lines as $i => $line) {
    $o = preg_match_all('/<div\b[^>]*>/i', $line);
    $c = preg_match_all('/<\/div>/i', $line);
    $open += $o;
    $open -= $c;
    if ($open < 0) {
        echo "Extra closing div at line " . ($i + 1) . "\n";
        $open = 0;
    }
}
echo "Final open divs: $open\n";
