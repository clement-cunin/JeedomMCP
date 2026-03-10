<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}
?>
