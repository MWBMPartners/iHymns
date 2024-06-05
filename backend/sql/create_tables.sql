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
