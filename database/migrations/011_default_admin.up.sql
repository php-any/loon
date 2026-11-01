START TRANSACTION;
INSERT INTO loom_users(name,email,password)
SELECT '管理员','admin@example.com','$2y$10$fgd0jZ/ZCLvX5lnojfLeB.Gf7c0doXH/QGecxpDqZ1G4yvZiAoMe.'
WHERE NOT EXISTS (SELECT 1 FROM loom_users);
SET @loom_default_admin_id = IF(ROW_COUNT() = 1, LAST_INSERT_ID(), NULL);
INSERT INTO admin_accounts(user_id) SELECT @loom_default_admin_id WHERE @loom_default_admin_id IS NOT NULL;
INSERT INTO admin_user_roles(user_id,role_id) SELECT @loom_default_admin_id,1 WHERE @loom_default_admin_id IS NOT NULL;
INSERT INTO admin_audit(actor_id,action,target,detail)
SELECT NULL,'admin.initialize',CAST(@loom_default_admin_id AS CHAR),'{"source":"migration"}' WHERE @loom_default_admin_id IS NOT NULL;
COMMIT;
