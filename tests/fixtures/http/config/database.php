<?php
return ['dsn' => getenv('LOOM_DATABASE') ?: 'mysql:host=127.0.0.1;dbname=loom_test'];
