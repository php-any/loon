UPDATE admin_menus SET parent_id=NULL WHERE parent_id IN (SELECT group_id FROM admin_menu_groups);
DELETE FROM admin_menus WHERE id IN (SELECT group_id FROM admin_menu_groups);
DROP TABLE admin_menu_groups;
