CREATE DATABASE IF NOT EXISTS worldbuilding;

-- CREATE USER IF NOT EXISTS 'secretadmin'@'%' IDENTIFIED BY 'secretpassword';
-- GRANT ALL PRIVILEGES ON worldbuilding.* TO 'secretadmin'@'%';
-- FLUSH PRIVILEGES;
-- deja pris en comptent par le fichier docker-compose dev et prod
-- sert juste de demonstration

USE worldbuilding;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    useremail VARCHAR(255) NOT NULL UNIQUE,
    userpassword VARCHAR(255) NOT NULL,
    profil_picture VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS filters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filter_name VARCHAR(255) NOT NULL UNIQUE,
    filter_type ENUM('theme', 'category', 'subcategory') NOT NULL,
    belong_to INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (belong_to) REFERENCES filters(id)
);

CREATE TABLE IF NOT EXISTS pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NOT NULL,
    page_title VARCHAR(255) NOT NULL,
    page_status ENUM('public', 'private', 'anonymous', 'banned') NOT NULL DEFAULT 'private',
    number_of_likes INT DEFAULT 0,
    number_of_followers INT DEFAULT 0,
    page_description TEXT,
    pagecontent JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS subpages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    master_page_id INT NOT NULL,
    parent_subpages_id INT,
    subpages_title VARCHAR(255) NOT NULL,
    subpages_status ENUM('public', 'private', 'anonymous', 'banned') NOT NULL DEFAULT 'private',
    subpages_content JSON NOT NULL,
    inherit_master_page_filters BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (master_page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_subpages_id) REFERENCES subpages(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS page_comment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    comment_user_id INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS page_filters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    filter_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (filter_id) REFERENCES filters(id)
);

CREATE TABLE IF NOT EXISTS users_engagement (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    page_id INT NOT NULL,
    engagement_type ENUM('like', 'follow') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
);

