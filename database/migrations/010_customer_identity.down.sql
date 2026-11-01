DELETE FROM admin_menus WHERE path IN ('/admin#customers','/admin#purchases');
UPDATE admin_menus SET label='用户管理' WHERE path IN ('/admin#users','/admin#/users');
DROP TABLE shop_orders;
DROP TABLE shop_sessions;
DROP TABLE shop_customers;
