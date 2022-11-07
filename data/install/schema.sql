CREATE TABLE `content_lock` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `user_id` INT NOT NULL,
    `entity_id` INT NOT NULL,
    `entity_name` VARCHAR(190) NOT NULL,
    `created` DATETIME NOT NULL,
    INDEX IDX_4AED76CBA76ED395 (`user_id`),
    UNIQUE INDEX UNIQ_4AED76CB81257D5D16EFC72D (`entity_id`, `entity_name`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE `content_lock` ADD CONSTRAINT FK_4AED76CBA76ED395 FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
