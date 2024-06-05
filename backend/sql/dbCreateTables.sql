CREATE DATABASE hymnals;

USE hymnals;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    subscription_status ENUM('free', 'paid') NOT NULL DEFAULT 'free',
    preferred_language VARCHAR(50),
    preferred_media_provider VARCHAR(50)
);

CREATE TABLE songs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    lyrics TEXT NOT NULL,
    language VARCHAR(50) NOT NULL,
    is_copy_protected BOOLEAN DEFAULT FALSE,
    embeddable_media JSON,
    purchase_links JSON,
    background_media_type ENUM('image', 'video'),
    background_media_url VARCHAR(255)
);

DELIMITER //

CREATE PROCEDURE SearchSongs (
    IN searchTerm VARCHAR(255),
    IN searchLanguage VARCHAR(50),
    IN userStatus ENUM('free', 'paid')
)
BEGIN
    IF userStatus = 'paid' THEN
        SELECT * FROM songs
        WHERE (title LIKE CONCAT('%', searchTerm, '%') OR lyrics LIKE CONCAT('%', searchTerm, '%'))
        AND language = searchLanguage;
    ELSE
        SELECT * FROM songs
        WHERE (title LIKE CONCAT('%', searchTerm, '%') OR lyrics LIKE CONCAT('%', searchTerm, '%'))
        AND language = searchLanguage
        AND is_copy_protected = 0;
    END IF;
END //

DELIMITER ;
