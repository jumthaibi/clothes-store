<?php
// Keep old bookmarks working while opening order history as an overlay on the
// storefront instead of sending the customer to a separate page.
header('Location: index.php?orders=1');
exit;
?>