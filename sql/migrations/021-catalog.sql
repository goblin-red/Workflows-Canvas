-- Каталог готовых схем: разделы с паспортами, у заготовок — паспорт и отметка «в каталоге».
-- Паспорт — знание о схеме для человека и для конструктора: для чего она, что на входе и выходе,
-- какие сервисы нужны, что настраивается, что спросить у человека, на чём спотыкались прогоны.
-- Из паспортов сервер собирает раздел «Опыт каталога» для конструктора (lib/templates/catalog.php).

CREATE TABLE IF NOT EXISTS template_categories (
  `key`      varchar(32)  NOT NULL,
  title      varchar(64)  NOT NULL,
  icon       varchar(32)  NOT NULL DEFAULT '',
  about      varchar(512) NOT NULL DEFAULT '',
  passport   longtext     NOT NULL DEFAULT '{}',
  sort       int          NOT NULL DEFAULT 0,
  updated_at datetime(3)  NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`key`),
  KEY ix_categories_sort (sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE templates
  ADD COLUMN passport   longtext    NOT NULL DEFAULT '{}' AFTER body,
  ADD COLUMN in_catalog tinyint(1)  NOT NULL DEFAULT 0    AFTER passport,
  ADD COLUMN checked_at datetime(3) NULL                  AFTER in_catalog,
  ADD KEY ix_templates_catalog (in_catalog, category, sort);

INSERT IGNORE INTO template_categories (`key`, title, icon, about, sort) VALUES
  ('sites', 'Sites and landing pages', 'globe', 'Landing pages, product pages, multi-page sites and finishing existing ones', 10),
  ('photo', 'Photo', 'image', 'Generation, retouching, background removal, series of product cards', 20),
  ('video', 'Video', 'film', 'Videos from images, cutting, voiced clips, animation', 30),
  ('texts', 'Texts and content', 'text', 'Articles, descriptions, translations, editing and content plans', 40),
  ('social', 'Social media and marketing', 'megaphone', 'Posts with images, ad creatives, series of publications', 50),
  ('docs', 'Documents and reports', 'file', 'Reports, proposals, presentations and summaries from data', 60),
  ('data', 'Data and APIs', 'database', 'Requests to services, exports, tables and summaries', 70),
  ('audio', 'Audio and voice-over', 'music', 'Voice-over of a text, podcasts, music and sound tracks', 80),
  ('code', 'Code and automation', 'code', 'Scripts, mini apps, checks and routine automation', 90),
  ('basics', 'Goblin basics', 'book', 'Learning schemes: a chain, a fork, a loop, parallel branches, a check', 100);
