CREATE TABLE shop_customers(id BIGINT PRIMARY KEY AUTO_INCREMENT,name VARCHAR(255) NOT NULL,email VARCHAR(254) NOT NULL UNIQUE,password VARCHAR(255) NOT NULL,enabled INTEGER NOT NULL DEFAULT 1,created BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE shop_sessions(token VARCHAR(64) PRIMARY KEY,customer_id BIGINT NOT NULL,csrf VARCHAR(64) NOT NULL,expires BIGINT NOT NULL,FOREIGN KEY(customer_id) REFERENCES shop_customers(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE INDEX shop_sessions_customer ON shop_sessions(customer_id);
CREATE TABLE shop_orders(id BIGINT PRIMARY KEY AUTO_INCREMENT,number VARCHAR(255) NOT NULL UNIQUE,customer_id BIGINT NOT NULL,total BIGINT NOT NULL,status VARCHAR(30) NOT NULL,request_key VARCHAR(255) NOT NULL UNIQUE,created BIGINT NOT NULL,FOREIGN KEY(customer_id) REFERENCES shop_customers(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
UPDATE admin_menus SET label='管理员管理' WHERE path IN ('/admin#users','/admin#/users');
INSERT INTO admin_menus(label,path,permission,parent_id,sort_order) SELECT '用户管理','/admin#customers','customers.manage',group_id,5 FROM admin_menu_groups WHERE name='business';
INSERT INTO admin_menus(label,path,permission,parent_id,sort_order) SELECT '用户订单','/admin#purchases','orders.view',group_id,6 FROM admin_menu_groups WHERE name='business';
