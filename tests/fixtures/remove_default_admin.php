<?php

// 现有测试套件在临时数据库中创建具有固定 ID 的用户。
$fixtureDatabase = ($runtimeServices ?? $scope->container())->get(\Loom\Database::class);
$fixtureDatabase->execute("DELETE FROM admin_user_roles WHERE user_id IN (SELECT id FROM loom_users WHERE email='admin@example.com')");
$fixtureDatabase->execute("DELETE FROM admin_accounts WHERE user_id IN (SELECT id FROM loom_users WHERE email='admin@example.com')");
$fixtureDatabase->execute("DELETE FROM admin_audit WHERE action='admin.initialize'");
$fixtureDatabase->execute("DELETE FROM loom_users WHERE email='admin@example.com'");
$fixtureDatabase->execute('ALTER TABLE loom_users AUTO_INCREMENT=1');
