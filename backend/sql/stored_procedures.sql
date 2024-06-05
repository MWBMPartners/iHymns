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
