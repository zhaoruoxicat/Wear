

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";


-- --------------------------------------------------------

--
-- 表的结构 `access_tokens`
--

CREATE TABLE `access_tokens` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL COMMENT '用途备注，如：邮件发送接口',
  `token` varchar(96) NOT NULL COMMENT '访问用token（明文保存，便于迁移；如需更安全可改hash）',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `allowed_paths` varchar(255) DEFAULT NULL COMMENT '可选：限制在这些脚本路径(逗号分隔)可用',
  `expire_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 表的结构 `categories`
--

CREATE TABLE `categories` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `sort_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `clothes`
--

CREATE TABLE `clothes` (
  `id` int NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `category_id` int NOT NULL,
  `subcategory_id` int NOT NULL,
  `season_id` int DEFAULT NULL,
  `season` varchar(20) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `source_id` int DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `notes` text,
  `image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `sort_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `clothes_seasons`
--

CREATE TABLE `clothes_seasons` (
  `clothes_id` int NOT NULL,
  `season_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 表的结构 `clothes_tags`
--

CREATE TABLE `clothes_tags` (
  `clothes_id` int NOT NULL,
  `tag_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `email_recipients`
--

CREATE TABLE `email_recipients` (
  `id` int UNSIGNED NOT NULL,
  `email` varchar(200) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 表的结构 `email_settings`
--

CREATE TABLE `email_settings` (
  `id` int UNSIGNED NOT NULL,
  `smtp_host` varchar(200) NOT NULL,
  `smtp_port` smallint UNSIGNED NOT NULL DEFAULT '465',
  `smtp_secure` enum('ssl','tls','none') NOT NULL DEFAULT 'ssl',
  `smtp_user` varchar(200) NOT NULL,
  `smtp_pass` varchar(400) NOT NULL,
  `from_name` varchar(100) NOT NULL,
  `from_email` varchar(200) NOT NULL,
  `is_auth` tinyint(1) NOT NULL DEFAULT '1',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 表的结构 `outfits`
--

CREATE TABLE `outfits` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 表的结构 `outfit_items`
--

CREATE TABLE `outfit_items` (
  `id` int UNSIGNED NOT NULL,
  `outfit_id` int UNSIGNED NOT NULL,
  `clothes_id` int NOT NULL,
  `order_index` int UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 表的结构 `outfit_seasons`
--

CREATE TABLE `outfit_seasons` (
  `id` int UNSIGNED NOT NULL,
  `outfit_id` int UNSIGNED NOT NULL,
  `season_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 表的结构 `seasons`
--

CREATE TABLE `seasons` (
  `id` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `temp_min` decimal(4,1) DEFAULT NULL,
  `temp_max` decimal(4,1) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 表的结构 `solar_terms`
--

CREATE TABLE `solar_terms` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(20) NOT NULL COMMENT '中文名，如 立春',
  `key_name` varchar(32) NOT NULL COMMENT '英文键，程序内部使用，如 lichun',
  `month` tinyint UNSIGNED DEFAULT NULL COMMENT '1-12，手动设置',
  `day` tinyint UNSIGNED DEFAULT NULL COMMENT '1-31，手动设置',
  `season_id` int UNSIGNED DEFAULT NULL,
  `sort_order` smallint UNSIGNED NOT NULL DEFAULT '0' COMMENT '习惯排序（1~24）',
  `note` varchar(100) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- 转存表中的数据 `solar_terms`
--

INSERT INTO `solar_terms` (`id`, `name`, `key_name`, `month`, `day`, `season_id`, `sort_order`, `note`, `updated_at`) VALUES
(1, '立春', 'lichun', 2, 4, 4, 1, NULL, '2025-09-13 03:40:47'),
(2, '雨水', 'yushui', 2, 19, 3, 2, NULL, '2025-09-13 03:40:47'),
(3, '惊蛰', 'jingzhe', 3, 6, 5, 3, NULL, '2025-09-13 03:40:47'),
(4, '春分', 'chunfen', 3, 21, 5, 4, NULL, '2025-09-13 03:40:47'),
(5, '清明', 'qingming', 4, 5, 1, 5, NULL, '2025-09-13 03:40:47'),
(6, '谷雨', 'guyu', 4, 20, 1, 6, NULL, '2025-09-13 03:40:47'),
(7, '立夏', 'lixia', 5, 6, 6, 7, NULL, '2025-09-13 03:40:47'),
(8, '小满', 'xiaoman', 5, 21, 2, 8, NULL, '2025-09-13 03:40:47'),
(9, '芒种', 'mangzhong', 6, 6, 2, 9, NULL, '2025-09-13 03:40:47'),
(10, '夏至', 'xiazhi', 6, 21, 2, 10, NULL, '2025-09-13 03:40:47'),
(11, '小暑', 'xiaoshu', 7, 7, 2, 11, NULL, '2025-09-13 03:40:47'),
(12, '大暑', 'dashu', 7, 23, 2, 12, NULL, '2025-09-13 03:41:14'),
(13, '立秋', 'liqiu', 8, 8, 2, 13, NULL, '2025-09-13 03:41:14'),
(14, '处暑', 'chushu', 8, 23, 2, 14, NULL, '2025-09-13 03:41:14'),
(15, '白露', 'bailu', 9, 8, 7, 15, NULL, '2025-09-13 03:41:14'),
(16, '秋分', 'qiufen', 9, 23, 3, 16, NULL, '2025-09-21 15:39:20'),
(17, '寒露', 'hanlu', 10, 8, 3, 17, NULL, '2025-09-21 15:39:20'),
(18, '霜降', 'shuangjiang', 10, 23, 3, 18, NULL, '2025-09-13 03:40:47'),
(19, '立冬', 'lidong', 11, 7, 4, 19, NULL, '2025-09-13 03:40:47'),
(20, '小雪', 'xiaoxue', 11, 22, 4, 20, NULL, '2025-09-13 03:41:14'),
(21, '大雪', 'daxue', 12, 7, 4, 21, NULL, '2025-09-13 03:41:14'),
(22, '冬至', 'dongzhi', 12, 22, 4, 22, NULL, '2025-09-13 03:41:14'),
(23, '小寒', 'xiaohan', 1, 5, 4, 23, NULL, '2025-09-13 03:41:14'),
(24, '大寒', 'dahan', 1, 20, 4, 24, NULL, '2025-09-13 03:41:14');

-- --------------------------------------------------------

--
-- 表的结构 `sources`
--

CREATE TABLE `sources` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `subcategories`
--

CREATE TABLE `subcategories` (
  `id` int NOT NULL,
  `category_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `sort_order` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `tags`
--

CREATE TABLE `tags` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- 表的结构 `user_tokens`
--

CREATE TABLE `user_tokens` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `token` varchar(64) NOT NULL,
  `user_agent` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- 转储表的索引
--

--
-- 表的索引 `access_tokens`
--
ALTER TABLE `access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`);

--
-- 表的索引 `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `clothes`
--
ALTER TABLE `clothes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `subcategory_id` (`subcategory_id`),
  ADD KEY `source_id` (`source_id`),
  ADD KEY `idx_clothes_season_id` (`season_id`);

--
-- 表的索引 `clothes_seasons`
--
ALTER TABLE `clothes_seasons`
  ADD PRIMARY KEY (`clothes_id`,`season_id`),
  ADD KEY `fk_cs_season` (`season_id`);

--
-- 表的索引 `clothes_tags`
--
ALTER TABLE `clothes_tags`
  ADD PRIMARY KEY (`clothes_id`,`tag_id`),
  ADD KEY `tag_id` (`tag_id`);

--
-- 表的索引 `email_recipients`
--
ALTER TABLE `email_recipients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_email` (`email`);

--
-- 表的索引 `email_settings`
--
ALTER TABLE `email_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_singleton` (`id`);

--
-- 表的索引 `outfits`
--
ALTER TABLE `outfits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_outfits_name` (`name`);

--
-- 表的索引 `outfit_items`
--
ALTER TABLE `outfit_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_outfit_clothes` (`outfit_id`,`clothes_id`),
  ADD KEY `fk_oi_clothes` (`clothes_id`),
  ADD KEY `idx_oi_outfit` (`outfit_id`),
  ADD KEY `idx_oi_order` (`outfit_id`,`order_index`);

--
-- 表的索引 `outfit_seasons`
--
ALTER TABLE `outfit_seasons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_outfit_season` (`outfit_id`,`season_id`),
  ADD KEY `fk_os_season` (`season_id`),
  ADD KEY `idx_os_outfit` (`outfit_id`);

--
-- 表的索引 `seasons`
--
ALTER TABLE `seasons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_seasons_name` (`name`);

--
-- 表的索引 `solar_terms`
--
ALTER TABLE `solar_terms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_solar_terms_key` (`key_name`),
  ADD UNIQUE KEY `uk_solar_terms_name` (`name`);

--
-- 表的索引 `sources`
--
ALTER TABLE `sources`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `subcategories`
--
ALTER TABLE `subcategories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- 表的索引 `tags`
--
ALTER TABLE `tags`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- 表的索引 `user_tokens`
--
ALTER TABLE `user_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `access_tokens`
--
ALTER TABLE `access_tokens`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `clothes`
--
ALTER TABLE `clothes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `email_recipients`
--
ALTER TABLE `email_recipients`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `email_settings`
--
ALTER TABLE `email_settings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `outfits`
--
ALTER TABLE `outfits`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `outfit_items`
--
ALTER TABLE `outfit_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `outfit_seasons`
--
ALTER TABLE `outfit_seasons`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `seasons`
--
ALTER TABLE `seasons`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `solar_terms`
--
ALTER TABLE `solar_terms`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=80;

--
-- 使用表AUTO_INCREMENT `sources`
--
ALTER TABLE `sources`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `subcategories`
--
ALTER TABLE `subcategories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `tags`
--
ALTER TABLE `tags`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `user_tokens`
--
ALTER TABLE `user_tokens`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 限制导出的表
--

--
-- 限制表 `clothes`
--
ALTER TABLE `clothes`
  ADD CONSTRAINT `clothes_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  ADD CONSTRAINT `clothes_ibfk_2` FOREIGN KEY (`subcategory_id`) REFERENCES `subcategories` (`id`),
  ADD CONSTRAINT `clothes_ibfk_3` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`),
  ADD CONSTRAINT `fk_clothes_season` FOREIGN KEY (`season_id`) REFERENCES `seasons` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- 限制表 `clothes_seasons`
--
ALTER TABLE `clothes_seasons`
  ADD CONSTRAINT `fk_cs_clothes` FOREIGN KEY (`clothes_id`) REFERENCES `clothes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cs_season` FOREIGN KEY (`season_id`) REFERENCES `seasons` (`id`) ON DELETE CASCADE;

--
-- 限制表 `clothes_tags`
--
ALTER TABLE `clothes_tags`
  ADD CONSTRAINT `clothes_tags_ibfk_1` FOREIGN KEY (`clothes_id`) REFERENCES `clothes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `clothes_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE;

--
-- 限制表 `outfit_items`
--
ALTER TABLE `outfit_items`
  ADD CONSTRAINT `fk_oi_clothes` FOREIGN KEY (`clothes_id`) REFERENCES `clothes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_oi_outfit` FOREIGN KEY (`outfit_id`) REFERENCES `outfits` (`id`) ON DELETE CASCADE;

--
-- 限制表 `outfit_seasons`
--
ALTER TABLE `outfit_seasons`
  ADD CONSTRAINT `fk_os_outfit` FOREIGN KEY (`outfit_id`) REFERENCES `outfits` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_os_season` FOREIGN KEY (`season_id`) REFERENCES `seasons` (`id`) ON DELETE CASCADE;

--
-- 限制表 `subcategories`
--
ALTER TABLE `subcategories`
  ADD CONSTRAINT `subcategories_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- 限制表 `user_tokens`
--
ALTER TABLE `user_tokens`
  ADD CONSTRAINT `user_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
