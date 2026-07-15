<?php
$code = file_get_contents(__DIR__ . '/../templates/menu.php');
$tokens = token_get_all($code);
$stack = [];
$line = 1;
foreach ($tokens as $t) {
    if (is_array($t)) {
        $tok = $t[0];
        $text = $t[1];
        $line = $t[2];
        if ($tok === T_IF) {
            // look ahead for ':' to detect alternate syntax
            $stack[] = ['type'=>'if', 'line'=>$line, 'text'=>trim($text)];
        }
        if ($tok === T_ELSE || $tok === T_ELSEIF) {
            // do nothing special
        }
        if ($tok === T_ENDIF) {
            if (count($stack) > 0) array_pop($stack);
            else echo "UNMATCHED ENDIF at line $line\n";
        }
    } else {
        // single char tokens
    }
}
if (count($stack) > 0) {
    echo "UNMATCHED IFs: \n";
    foreach ($stack as $s) echo "IF at line {$s['line']}\n";
} else {
    echo "All IF tokens matched with ENDIF tokens (token-level)\n";
}

// Also simple regex count
$ifs = preg_match_all('/<?php\s+if\s*\(/', $code, $m);
$altifs = preg_match_all('/<?php\s+if\s*\(.*\):\s*\?>/U', $code, $m2);
$endifs = preg_match_all('/<?php\s*endif;\s*\?>/U', $code, $m3);
echo "Counts: if(...)=${ifs}, alt_if:=${altifs}, endif=${endifs}\n";
?>