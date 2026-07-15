http://localhost/billspayment/dashboard/home.php?debug_access=1


put: ?debug_access=1

?__showperms=1


C:\xampp\php\php.exe tools\generate_access_map.php

php tools/generate_access_map.php


Singles + Pairs (default):
C:\xampp\php\php.exe tools\generate_access_map.php

Up to triples:
C:\xampp\php\php.exe tools\generate_access_map.php 3

Full power-set (DANGEROUS — will require force if over 5M combinations):
C:\xampp\php\php.exe tools\generate_access_map.php all force

Generate per-root combinations (covers selecting all items under any menu) plus cross-root pairs:
C:\xampp\php\php.exe tools\generate_access_map.php perroot 2 force