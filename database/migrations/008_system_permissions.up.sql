CREATE TABLE admin_system_menu_backup(id BIGINT PRIMARY KEY,label VARCHAR(100) NOT NULL,parent_id BIGINT,sort_order INTEGER NOT NULL,created INTEGER NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
INSERT INTO admin_system_menu_backup(id,label,parent_id,sort_order) SELECT id,label,parent_id,sort_order FROM admin_menus WHERE path IN ('/admin#users','/admin#/users','/admin#roles','/admin#/roles','/admin#settings','/admin#/settings','/admin#permissions','/admin#/permissions');
UPDATE admin_menus SET parent_id=(SELECT group_id FROM admin_menu_groups WHERE name='system') WHERE path IN ('/admin#users','/admin#/users','/admin#roles','/admin#/roles','/admin#settings','/admin#/settings','/admin#permissions','/admin#/permissions');
UPDATE admin_menus SET label='管理员',sort_order=10 WHERE path IN ('/admin#users','/admin#/users');
UPDATE admin_menus SET sort_order=20 WHERE path IN ('/admin#roles','/admin#/roles');
UPDATE admin_menus SET sort_order=40 WHERE path IN ('/admin#settings','/admin#/settings');
INSERT INTO admin_menus(label,path,permission,parent_id,sort_order) SELECT '权限管理','/admin#permissions','roles.manage',group_id,30 FROM admin_menu_groups WHERE name='system' AND NOT EXISTS (SELECT 1 FROM admin_menus WHERE path IN ('/admin#permissions','/admin#/permissions'));
INSERT INTO admin_system_menu_backup(id,label,parent_id,sort_order,created) SELECT id,label,parent_id,sort_order,1 FROM admin_menus WHERE path IN ('/admin#permissions','/admin#/permissions') AND id NOT IN (SELECT id FROM admin_system_menu_backup);
