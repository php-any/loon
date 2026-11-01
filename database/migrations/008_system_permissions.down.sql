DELETE FROM admin_menus WHERE id IN (SELECT id FROM admin_system_menu_backup WHERE created=1);
UPDATE admin_menus SET label=(SELECT label FROM admin_system_menu_backup WHERE id=admin_menus.id),parent_id=(SELECT parent_id FROM admin_system_menu_backup WHERE id=admin_menus.id),sort_order=(SELECT sort_order FROM admin_system_menu_backup WHERE id=admin_menus.id) WHERE id IN (SELECT id FROM admin_system_menu_backup WHERE created=0);
DROP TABLE admin_system_menu_backup;
