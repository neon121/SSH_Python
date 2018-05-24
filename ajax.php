<?
define ('STOP_FLAG_FILE', 'stop_command'); //need manual sync with same value in daemon.php

print_r($_POST);
switch ($_POST['action']) {
    case 'stopFlag':
        fopen(STOP_FLAG_FILE,'a');
        break;
}