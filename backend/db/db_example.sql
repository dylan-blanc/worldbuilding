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

INSERT IGNORE INTO users (id, username, useremail, userpassword, profil_picture) VALUES
    (1, 'aeliana', 'aeliana@example.test', '$2y$10$8rYHOv0ZlZfFKvKXkD5xj.r8UOqAOFBtwnubSUlUIqBYy9eLVbVOa', '/images/profiles/aeliana.webp'),
    (2, 'kael', 'kael@example.test', '$2y$10$8rYHOv0ZlZfFKvKXkD5xj.r8UOqAOFBtwnubSUlUIqBYy9eLVbVOa', '/images/profiles/kael.webp'),
    (3, 'mirelda', 'mirelda@example.test', '$2y$10$8rYHOv0ZlZfFKvKXkD5xj.r8UOqAOFBtwnubSUlUIqBYy9eLVbVOa', NULL);

INSERT IGNORE INTO filters (id, filter_name, filter_type, belong_to) VALUES
    (1, 'Fantasy', 'theme', NULL),
    (2, 'Science-fiction', 'theme', NULL),
    (3, 'Royaumes', 'category', 1),
    (4, 'Magie', 'category', 1),
    (5, 'Colonies spatiales', 'category', 2),
    (6, 'Cites marchandes', 'subcategory', 3),
    (7, 'Ecoles arcaniques', 'subcategory', 4),
    (8, 'Stations orbitales', 'subcategory', 5);

INSERT IGNORE INTO pages (
    id,
    owner_user_id,
    page_title,
    page_status,
    number_of_likes,
    number_of_followers,
    page_description,
    pagecontent
) VALUES
    (
        1,
        1,
        'Les Archives d''Eldoria',
        'public',
        12,
        5,
        'Un royaume ancien structure autour des guildes, des routes commerciales et des familles nobles.',
        '{"blocks":[{"type":"heading","content":"Eldoria"},{"type":"paragraph","content":"Eldoria est un royaume de hautes plaines traverse par trois routes marchandes."}]}'
    ),
    (
        2,
        2,
        'Station Helios-7',
        'public',
        8,
        3,
        'Une station orbitale dediee a la recherche, aux migrations longues et aux tensions politiques.',
        '{"blocks":[{"type":"heading","content":"Helios-7"},{"type":"paragraph","content":"La station accueille des chercheurs, des pilotes et des delegations venues de plusieurs colonies."}]}'
    ),
    (
        3,
        3,
        'Carnet prive de Mirelda',
        'private',
        0,
        0,
        'Brouillon prive pour preparer une campagne centree sur les ecoles de magie.',
        '{"blocks":[{"type":"paragraph","content":"Notes de travail sur les rituels, les maitres et les rivalites internes."}]}'
    );

