DROP DATABASE IF EXISTS `altershift`;

CREATE DATABASE `altershift`;
USE `altershift`;

CREATE TABLE `services` (
    `id` INT(3) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) DEFAULT NULL,
    PRIMARY KEY (`id`)
);

INSERT INTO `services` (`name`)
VALUES
	('Personal Training'),
    ('Group PT'),
	('Musculoskeletal Therapy'),
    ('Boxing'),
    ('24 HIT'),
    ('MetaFit'),
    ('Pilates'),
    ('Strength & Conditioning'),
    ('Awake & Active Pilates');

INSERT INTO `service_info` (`service_id`, `time`, `price`, `num_people`, `buffer`)
VALUES
    (1, 30, 55, 1, 0),
    (1, 45, 55, 1, 0),
    (2, 45, 10, 3, 0),
    (3, 30, 30, 1, 15),
    (3, 45, 60, 1, 15),
    (3, 60, 75, 1, 15),
    (3, 90, 90, 1, 15),
    (4, 45, 10, 10, 0),
    (5, 30, 10, 10, 0),
    (6, 30, 10, 10, 0),
    (7, 60, 10, 10, 0),
    (8, 45, 10, 10, 0),
    (9, 60, 10, 10, 0);

CREATE TABLE `users` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(200) DEFAULT NULL,
    `email` VARCHAR(200) DEFAULT NULL,
    `password` VARCHAR(200) DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    `role` INT(3),
    `service_id` INT(3),
    PRIMARY KEY (`id`)
);

INSERT INTO `users` (`name`, `email`, `password`, `role`, `service_id`)
VALUES
    ('Shannon White', 'blah@gmail.com', 'password01', 1, 2),
    ('Daniel Donaldson', 'blah@gmail.com', 'password01', 1, 1),
    ('Courtney White', 'blah@gmail.com', 'password01', 1, 1),
    ('Sarah-Jane Cooke', 'blah@gmail.com', 'password01', 1, 3),
    ('Marissa Invincible', 'blah@gmail.com', 'password01', 1, 4),
    ('Mat Pilates', 'blah@gmail.com', 'password01', 1, 5);

CREATE TABLE `calendar` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `service_id` INT(3) NOT NULL,
    `staff_id` INT(11) NOT NULL,
    `start` DATETIME,
    `end` DATETIME,
    PRIMARY KEY (`id`)
);
