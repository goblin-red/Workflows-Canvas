<?php
/* Прежний адрес админки. Теперь она одной страницей — admin.php.
   Ссылку оставляем живой, чтобы старые закладки не ломались. */

declare(strict_types=1);

header('Location: ../admin.php', true, 302);
echo '<meta charset="utf-8"><p>The admin panel has moved: <a href="../admin.php">admin.php</a>.</p>';
