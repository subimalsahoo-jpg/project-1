CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) DEFAULT '',
    role VARCHAR(50) DEFAULT 'Viewer',
    status VARCHAR(20) DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_name VARCHAR(100) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY user_permission_unique (user_id, permission_name)
);

ALTER TABLE users ADD COLUMN full_name VARCHAR(150) DEFAULT '';
ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'Viewer';
ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'Active';

-- If ALTER TABLE says duplicate column, ignore that error and continue.
-- Default first-time admin, only use if your users table is empty:
-- INSERT INTO users (username, password, full_name, role, status)
-- VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCsuCBuGdv5QSdY1oM7y', 'System Admin', 'Admin', 'Active');
-- Password for above hash: password
