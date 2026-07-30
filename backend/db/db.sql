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
    roles ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS filters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filter_name VARCHAR(255) NOT NULL UNIQUE,
    filter_type ENUM('theme', 'category', 'subcategory', 'moderation') NOT NULL,
    belong_to VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NOT NULL,
    page_title VARCHAR(255) NOT NULL,
    page_status ENUM('public', 'private', 'banned') NOT NULL DEFAULT 'private',
    is_anonymous BOOLEAN NOT NULL DEFAULT FALSE,
    number_of_likes INT DEFAULT 0,
    number_of_view INT DEFAULT 0,
    number_of_followers INT DEFAULT 0,
    page_description TEXT,
    page_picture VARCHAR(255),
    pagecontent JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS moderation (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_user_id INT NOT NULL,
    reported_page_id INT NOT NULL,
    reported_filter_content INT NOT NULL,
    reported_user_message TEXT NULL,
    reported_media_url VARCHAR(2048) NULL,
    reported_content_type ENUM('page_display', 'page_content') NOT NULL DEFAULT 'page_display',
    moderation_status ENUM('pending', 'reviewed', 'dismissed') NOT NULL DEFAULT 'pending',
    reviewed_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY unique_reporter_page_content (reporter_user_id, reported_page_id, reported_content_type),
    INDEX moderation_status_created (moderation_status, created_at),
    INDEX moderation_page_content (reported_page_id, reported_content_type),
    FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_filter_content) REFERENCES filters(id) ON DELETE RESTRICT,
    FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS page_revision (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id INT NOT NULL,
    created_by_user_id INT,
    revision_number INT UNSIGNED NOT NULL,
    revision_status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    is_current BOOLEAN NOT NULL DEFAULT TRUE,
    pagecontent JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at TIMESTAMP NULL DEFAULT NULL,
    current_draft_page_id INT,
    current_published_page_id INT,
    UNIQUE KEY unique_page_revision_number (page_id, revision_number),
    UNIQUE KEY unique_current_page_draft (current_draft_page_id),
    UNIQUE KEY unique_current_page_publication (current_published_page_id),
    INDEX page_revision_lookup (page_id, revision_status, is_current),
    CONSTRAINT valid_current_page_revision CHECK (
        (is_current = FALSE AND current_draft_page_id IS NULL AND current_published_page_id IS NULL)
        OR (is_current = TRUE AND revision_status = 'draft' AND current_draft_page_id IS NOT NULL
            AND current_draft_page_id = page_id AND current_published_page_id IS NULL)
        OR (is_current = TRUE AND revision_status = 'published' AND current_published_page_id IS NOT NULL
            AND current_published_page_id = page_id AND current_draft_page_id IS NULL)
    ),
    FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
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
    FOREIGN KEY (filter_id) REFERENCES filters(id) ON DELETE CASCADE
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

INSERT INTO users (id, username, useremail, userpassword, profil_picture, roles) VALUES
    (1, 'admin', 'admin@gmail.com', '$2y$10$.jJe/dABEtFutGw8yX6qMeQVT9wMQy6Po5Ojg6XVAyLbjooaCwTqS', '/images/profiles/aeliana.webp', 'admin'),
    (2, 'kael', 'kael@example.test', '$2y$10$ZTsDvmHTVNoSs.7p26rLm.mKSNtfRqc/wHO2xddoj3hnyEcXwRxxi', '/images/profiles/kael.webp', 'user'),
    (3, 'mirelda', 'mirelda@example.test', '$2y$10$ZTsDvmHTVNoSs.7p26rLm.mKSNtfRqc/wHO2xddoj3hnyEcXwRxxi', NULL, 'user'),
    (4, 'test', 'test@test.com', '$2y$10$ZTsDvmHTVNoSs.7p26rLm.mKSNtfRqc/wHO2xddoj3hnyEcXwRxxi', NULL, 'user');

INSERT INTO filters (id, filter_name, filter_type, belong_to) VALUES
    (1, 'Fantasy', 'theme', NULL),
    (2, 'Science-fiction', 'theme', NULL),
    (3, 'Royaumes', 'category', 1),
    (4, 'Magie', 'category', 1),
    (5, 'Colonies spatiales', 'category', 2),
    (6, 'Cites marchandes', 'subcategory', 3),
    (7, 'Ecoles arcaniques', 'subcategory', 3),
    (8, 'Stations orbitales', 'subcategory', 5),
    (9, 'Mature content', 'moderation', NULL),
    (10, 'Spam', 'moderation', NULL),
    (11, 'Violent or shocking content', 'moderation', NULL),
    (12, 'Other', 'moderation', NULL);

INSERT INTO pages (
    id,
    owner_user_id,
    page_title,
    page_status,
    number_of_likes,
    number_of_view,
    number_of_followers,
    page_description,
    page_picture,
    pagecontent,
    created_at,
    updated_at
) VALUES
    (
        1,
        1,
        'Les Archives d''Eldoria',
        'public',
        12,
        145,
        5,
        'Un royaume ancien structure autour des guildes, des routes commerciales et des familles nobles.',
        'https://picsum.photos/seed/eldoria/620/820',
        '{"blocks":[{"type":"heading","content":"Eldoria"},{"type":"paragraph","content":"Eldoria est un royaume de hautes plaines traverse par trois routes marchandes."}]}',
        '2026-01-05 10:00:00',
        '2026-02-14 15:30:00'
    ),
    (
        2,
        2,
        'Station Helios-7',
        'public',
        8,
        98,
        3,
        'Une station orbitale dediee a la recherche, aux migrations longues et aux tensions politiques.',
        'https://picsum.photos/seed/helios-7/620/820',
        '{"blocks":[{"type":"heading","content":"Helios-7"},{"type":"paragraph","content":"La station accueille des chercheurs, des pilotes et des delegations venues de plusieurs colonies."}]}',
        '2026-01-12 09:20:00',
        '2026-03-01 11:45:00'
    ),
    (
        3,
        3,
        'Carnet prive de Mirelda',
        'private',
        0,
        17,
        0,
        'Brouillon prive pour preparer une campagne centree sur les ecoles de magie.',
        'https://picsum.photos/seed/mirelda/620/820',
        '{"blocks":[{"type":"paragraph","content":"Notes de travail sur les rituels, les maitres et les rivalites internes."}]}',
        '2026-01-20 18:10:00',
        '2026-01-22 08:00:00'
    ),
    (
        4,
        1,
        'Chroniques de Valombre',
        'public',
        21,
        263,
        9,
        'Un univers fantasy centre sur une marche frontiere, ses serments et ses anciennes ruines.',
        'https://picsum.photos/seed/valombre/620/820',
        '{"blocks":[{"type":"heading","content":"Valombre"},{"type":"paragraph","content":"La marche de Valombre garde les cols ou les anciens pactes sont encore respectes."}]}',
        '2026-02-02 14:00:00',
        '2026-04-10 16:20:00'
    ),
    (
        5,
        2,
        'L''Anneau de Kepler',
        'public',
        15,
        184,
        7,
        'Une megastructure scientifique ou chaque secteur defend sa vision de la survie humaine.',
        'https://picsum.photos/seed/kepler-ring/620/820',
        '{"blocks":[{"type":"heading","content":"Anneau de Kepler"},{"type":"paragraph","content":"Les habitats de l anneau tournent autour d une etoile froide et disputee."}]}',
        '2026-02-18 07:45:00',
        '2026-05-03 12:10:00'
    ),
    (
        6,
        3,
        'Couronnes de Brume',
        'public',
        34,
        412,
        16,
        'Des royaumes rivaux negocient leurs alliances au rythme des saisons et des successions.',
        'https://picsum.photos/seed/couronnes-brume/620/820',
        '{"blocks":[{"type":"heading","content":"Couronnes de Brume"},{"type":"paragraph","content":"Les couronnes changent rarement de tete sans que les routes ne se couvrent de brume."}]}',
        '2026-03-04 13:30:00',
        '2026-06-12 17:00:00'
    ),
    (
        7,
        4,
        'Le Grimoire des Sept Voiles',
        'public',
        11,
        136,
        6,
        'Un systeme de magie fonde sur les pactes, les voiles de perception et les couts rituels.',
        'https://picsum.photos/seed/sept-voiles/620/820',
        '{"blocks":[{"type":"heading","content":"Sept Voiles"},{"type":"paragraph","content":"Chaque voile ouvert revele une loi du monde et retire une certitude au mage."}]}',
        '2026-03-19 16:15:00',
        '2026-04-22 09:35:00'
    ),
    (
        8,
        1,
        'Nouvelle Aube sur Ceres',
        'public',
        18,
        227,
        12,
        'Une colonie miniere tente de devenir autonome face aux grandes compagnies orbitales.',
        'https://picsum.photos/seed/ceres-aube/620/820',
        '{"blocks":[{"type":"heading","content":"Nouvelle Aube"},{"type":"paragraph","content":"La colonie de Ceres apprend a survivre sans attendre les navettes de ravitaillement."}]}',
        '2026-04-01 08:40:00',
        '2026-05-18 14:25:00'
    ),
    (
        9,
        2,
        'Les Comptoirs d''Ivarne',
        'public',
        26,
        318,
        19,
        'Des cites marchandes controlent les taxes, les routes fluviales et les secrets des caravanes.',
        'https://picsum.photos/seed/ivarne/620/820',
        '{"blocks":[{"type":"heading","content":"Ivarne"},{"type":"paragraph","content":"A Ivarne, une signature peut valoir plus qu une armee."}]}',
        '2026-04-16 11:05:00',
        '2026-07-02 19:10:00'
    ),
    (
        10,
        3,
        'Academie de Lumeclair',
        'public',
        9,
        109,
        4,
        'Une ecole arcanique organisee autour de maisons, de concours et d interdits anciens.',
        'https://picsum.photos/seed/lumeclair/620/820',
        '{"blocks":[{"type":"heading","content":"Lumeclair"},{"type":"paragraph","content":"Les apprentis de Lumeclair apprennent d abord a nommer les dangers avant de les invoquer."}]}',
        '2026-05-06 10:50:00',
        '2026-05-28 13:15:00'
    ),
    (
        11,
        4,
        'Port-Orbite Nysa',
        'public',
        31,
        376,
        14,
        'Une station orbitale de transit ou diplomates, contrebandiers et ouvriers se croisent.',
        'https://picsum.photos/seed/nysa-orbite/620/820',
        '{"blocks":[{"type":"heading","content":"Nysa"},{"type":"paragraph","content":"Port-Orbite Nysa ne dort jamais car les fuseaux horaires y sont tous presents a la fois."}]}',
        '2026-05-21 06:30:00',
        '2026-07-09 21:40:00'
    ),
    (
        12,
        1,
        'Les Terres de Cendre',
        'public',
        7,
        82,
        2,
        'Un monde fantasy marque par une ancienne eruption et des peuples habitues aux exils.',
        'https://picsum.photos/seed/terres-cendre/620/820',
        '{"blocks":[{"type":"heading","content":"Terres de Cendre"},{"type":"paragraph","content":"Les villages se deplacent avec le vent pour eviter les pluies de cendre."}]}',
        '2026-06-08 12:00:00',
        '2026-06-20 10:20:00'
    ),
    (
        13,
        2,
        'Protocole Horizon',
        'public',
        13,
        161,
        8,
        'Une intrigue de science-fiction autour d une mission longue, d archives perdues et de signaux inconnus.',
        'https://picsum.photos/seed/protocole-horizon/620/820',
        '{"blocks":[{"type":"heading","content":"Protocole Horizon"},{"type":"paragraph","content":"Le protocole commence quand un signal repond avant meme d etre envoye."}]}',
        '2026-06-25 15:45:00',
        '2026-07-14 08:55:00'
    );

-- temporaire, pour le dev, a supprimer plus tard
INSERT INTO page_revision (
    page_id,
    created_by_user_id,
    revision_number,
    revision_status,
    is_current,
    current_draft_page_id,
    current_published_page_id,
    pagecontent,
    created_at,
    updated_at,
    published_at
)
SELECT
    id,
    owner_user_id,
    1,
    CASE WHEN page_status = 'public' THEN 'published' ELSE 'draft' END,
    TRUE,
    CASE WHEN page_status = 'public' THEN NULL ELSE id END,
    CASE WHEN page_status = 'public' THEN id ELSE NULL END,
    pagecontent,
    created_at,
    updated_at,
    CASE WHEN page_status = 'public' THEN updated_at ELSE NULL END
FROM pages
ON DUPLICATE KEY UPDATE page_id = VALUES(page_id);

INSERT INTO page_filters (id, page_id, filter_id) VALUES
    (1, 4, 1),
    (2, 5, 2),
    (3, 6, 3),
    (4, 7, 4),
    (5, 8, 5),
    (6, 9, 6),
    (7, 10, 7),
    (8, 11, 8),
    (9, 12, 1),
    (10, 13, 2);

