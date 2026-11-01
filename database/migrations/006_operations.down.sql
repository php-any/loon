DELETE m FROM admin_menus m JOIN admin_operations_menus s ON s.menu_id=m.id AND s.path=m.path LEFT JOIN admin_menus child ON child.parent_id=m.id WHERE child.id IS NULL;
DROP TABLE admin_operations_menus;
DROP TABLE admin_failure_events;
DROP TABLE admin_deliveries;
DROP TABLE admin_channels;
DROP TABLE admin_notifications;
DROP TABLE admin_order_events;
DROP TABLE admin_order_items;
DROP TABLE admin_orders;
